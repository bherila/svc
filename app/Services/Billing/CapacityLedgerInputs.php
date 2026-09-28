<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Support\Billing\CarriedDeferredLine;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Per-month hours the capacity ledger books, split the way RolloverCalculator
 * needs them: ordinary work, deferred work an invoice applied, and re-carried
 * deferred work a line applied or billed. Both monthly ledgers read it, so
 * they cannot drift apart.
 */
final class CapacityLedgerInputs
{
    /**
     * @param  Collection<int, ClientTimeEntry>  $entries  loaded withCapacityPlacement() and already limited to the ledger's end
     * @return array<string, array{ordinary: float, deferred: float, carried: float, carried_billed: float}> keyed by `Y-m`
     */
    public function byMonth(ClientCompany $company, ClientAgreement $agreement, Collection $entries, CarbonInterface $through): array
    {
        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_agreement_id', $agreement->id)
            ->whereIn('type', [InvoiceLineType::PriorMonthRetainer->value, InvoiceLineType::AdditionalHours->value])
            ->where(fn ($query) => $query
                ->where('description', 'like', CarriedDeferredLine::Applied->value.' (%')
                ->orWhere('description', 'like', CarriedDeferredLine::BilledOnTermination->value.' (%'))
            ->whereNotNull('line_date')
            ->whereDate('line_date', '<=', $through->toDateString())
            ->whereHas('invoice', fn ($invoice) => $invoice
                ->where('client_invoices.workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->whereIn('status', InvoiceStatus::live()))
            ->get(['type', 'description', 'hours', 'line_date']);

        return self::fold(
            $entries->map(fn (ClientTimeEntry $entry): array => [
                'month' => $entry->capacityDate()->format('Y-m'),
                'hours' => ((int) $entry->minutes) / 60,
                'deferred' => $entry->drawsAsDeferred(),
            ]),
            $lines->map(fn (ClientInvoiceLine $line): array => [
                'month' => $line->line_date?->format('Y-m') ?? '',
                'hours' => (float) $line->hours,
                'kind' => CarriedDeferredLine::of((string) $line->type, (string) $line->description),
            ]),
        );
    }

    /**
     * The RolloverCalculator fields for one month of {@see byMonth()}'s result.
     * A month before the agreement carries no work of its own; settled
     * re-carried hours are booked wherever their line says.
     *
     * @param  array<string, array{ordinary: float, deferred: float, carried: float, carried_billed: float}>  $byMonth
     * @return array{hours_worked: float, deferred_hours: float, carried_deferred_hours: float, carried_deferred_billed_hours: float}
     */
    public static function monthRow(array $byMonth, string $month, bool $countsWork = true): array
    {
        $hours = $byMonth[$month] ?? ['ordinary' => 0.0, 'deferred' => 0.0, 'carried' => 0.0, 'carried_billed' => 0.0];

        return [
            'hours_worked' => $countsWork ? $hours['ordinary'] : 0.0,
            'deferred_hours' => $countsWork ? $hours['deferred'] : 0.0,
            'carried_deferred_hours' => $hours['carried'],
            'carried_deferred_billed_hours' => $hours['carried_billed'],
        ];
    }

    /**
     * @param  iterable<array{month: string, hours: float, deferred: bool}>  $entries
     * @param  iterable<array{month: string, hours: float, kind: CarriedDeferredLine|null}>  $carriedLines
     * @return array<string, array{ordinary: float, deferred: float, carried: float, carried_billed: float}>
     */
    public static function fold(iterable $entries, iterable $carriedLines): array
    {
        $months = [];
        $blank = ['ordinary' => 0.0, 'deferred' => 0.0, 'carried' => 0.0, 'carried_billed' => 0.0];

        foreach ($entries as $entry) {
            $months[$entry['month']] ??= $blank;
            $months[$entry['month']][$entry['deferred'] ? 'deferred' : 'ordinary'] += $entry['hours'];
        }
        foreach ($carriedLines as $line) {
            if ($line['kind'] === null) {
                continue;
            }
            $months[$line['month']] ??= $blank;
            $months[$line['month']][match ($line['kind']) {
                CarriedDeferredLine::Applied => 'carried',
                CarriedDeferredLine::BilledOnTermination => 'carried_billed',
            }] += $line['hours'];
        }

        return array_map(static fn (array $month): array => array_map(static fn (float $hours): float => round($hours, 4), $month), $months);
    }
}
