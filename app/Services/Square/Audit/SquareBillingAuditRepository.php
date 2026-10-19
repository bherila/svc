<?php

namespace App\Services\Square\Audit;

use App\Models\ClientAgreement;
use App\Models\ClientInvoicePayment;
use App\Models\CompanySquareCustomer;
use App\Models\InvoiceSquareLink;
use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareSeriesSummary;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one bounded, workspace-scoped load behind the Square billing audit
 * (#438). A fixed number of queries whatever the workspace holds: the
 * billed-in-Square agreements, the mirrored invoices within the audit's reach,
 * their active links, the confirmed and retired customers, and which of their
 * payment ids svc has recorded. Every query names the workspace, and every
 * join repeats it, so a row stamped to another workspace is never read.
 */
final class SquareBillingAuditRepository
{
    /** Mirrored invoices in reach read at most, besides series members: decades of a small practice's billing. */
    public const int MAX_INVOICES = 5000;

    /** Square payment ids checked against svc's ledger at most: 20 lookups. */
    public const int MAX_PAYMENT_IDS = 10_000;

    /** Members of billed-in-Square series read at most. */
    public const int MAX_SERIES_MEMBERS = 5000;

    /** Billed-in-Square agreements read at most. */
    public const int MAX_AGREEMENTS = 500;

    /** Identifiers per IN list. */
    private const int CHUNK = 500;

    public function load(Workspace $workspace, string $today): SquareBillingAuditFacts
    {
        $agreements = $this->agreements($workspace);
        $complete = count($agreements) <= self::MAX_AGREEMENTS;
        $agreements = array_slice($agreements, 0, self::MAX_AGREEMENTS);
        $seriesKeys = array_map(static fn (SquareAuditAgreement $agreement): string => $agreement->seriesKey, $agreements);

        $columns = ['id', 'square_invoice_id', 'invoice_number', 'series_key', 'series_occurrence', 'square_customer_id', 'recipient_name', 'square_location_id',
            'status', 'currency', 'total_amount', 'paid_amount', 'due_on', 'scheduled_at', 'autopay', 'payment_ids', 'square_order_id', 'payment_ids_basis'];
        // Series members first, under their own bound and newest first, so a
        // large mirror never hides a billed-in-Square series: drift reads the
        // latest members, and absence is only claimed of a complete load.
        $members = $seriesKeys === [] ? collect() : SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)->whereIn('series_key', $seriesKeys)
            ->orderByDesc('id')->limit(self::MAX_SERIES_MEMBERS + 1)->get($columns);
        $seriesComplete = $members->count() <= self::MAX_SERIES_MEMBERS;
        $members = $members->take(self::MAX_SERIES_MEMBERS);

        $mapped = CompanySquareCustomer::query()->where('workspace_id', $workspace->id)->whereNotNull('active_customer_slot')->select('active_customer_slot');
        $linked = InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereNotNull('active_square_invoice_slot')->select('active_square_invoice_slot');
        $reach = SquareInvoiceSnapshot::query()->where('workspace_id', $workspace->id)
            ->where(static function (Builder $reach) use ($mapped, $linked): void {
                $reach->whereIn('status', SquareSeriesSummary::OPEN_STATUSES)
                    ->orWhereIn('square_customer_id', $mapped)
                    ->orWhereIn('square_invoice_id', $linked);
            })
            ->orderByDesc('id')
            ->limit(self::MAX_INVOICES + 1)
            ->get($columns);
        $reachComplete = $reach->count() <= self::MAX_INVOICES;
        $complete = $complete && $seriesComplete && $reachComplete;
        // The newest run must be the complete one: a newer run that is still
        // going, or failed, has left the mirror between generations.
        $lastSync = SquareSyncRun::query()->where('workspace_id', $workspace->id)->orderByDesc('id')->first(['status', 'truncated']);
        $mirrorComplete = $lastSync !== null && $lastSync->status === SquareSyncRun::COMPLETED && ! $lastSync->truncated;
        $snapshots = [];
        foreach ([...$members->all(), ...$reach->take(self::MAX_INVOICES)->all()] as $snapshot) {
            $snapshots[$snapshot->square_invoice_id] ??= $snapshot;
        }

        $squareInvoiceIds = [];
        $paymentIds = [];
        $deferred = [];
        $customerIds = [];
        foreach ($snapshots as $snapshot) {
            $squareInvoiceIds[] = $snapshot->square_invoice_id;
            // Past the bound, an invoice's payments are left unchecked - and
            // say so - rather than looked up in ever more queries.
            $ids = $snapshot->payment_ids ?? [];
            if (count($paymentIds) + count($ids) > self::MAX_PAYMENT_IDS) {
                $deferred[$snapshot->square_invoice_id] = true;
                $ids = [];
            }
            foreach ($ids as $paymentId) {
                $paymentIds[$paymentId] = true;
            }
            if ($snapshot->square_customer_id !== null) {
                $customerIds[$snapshot->square_customer_id] = true;
            }
        }
        $links = $this->links($workspace, $squareInvoiceIds);

        $invoices = [];
        foreach ($snapshots as $snapshot) {
            $invoices[] = new SquareAuditInvoice(
                $snapshot->square_invoice_id,
                $snapshot->invoice_number,
                $snapshot->series_key,
                $snapshot->series_occurrence,
                $snapshot->square_customer_id,
                $snapshot->recipient_name,
                $snapshot->square_location_id,
                $snapshot->status,
                $snapshot->currency,
                $snapshot->total_amount,
                $snapshot->paid_amount,
                $snapshot->due_on?->toDateString(),
                $snapshot->scheduled_at?->toDateString(),
                $snapshot->autopay,
                array_values(array_filter($snapshot->payment_ids ?? [], 'is_string')),
                $links[$snapshot->square_invoice_id] ?? null,
                ! isset($deferred[$snapshot->square_invoice_id])
                    && SquareSnapshotMapper::paymentIdsCurrent($snapshot->status, $snapshot->paid_amount, $snapshot->square_order_id, $snapshot->payment_ids_basis),
            );
        }

        return new SquareBillingAuditFacts(
            $today,
            $agreements,
            $invoices,
            $this->mappedCustomers($workspace, array_map(strval(...), array_keys($customerIds))),
            $this->retiredCustomers($workspace, array_map(strval(...), array_keys($customerIds))),
            $this->recordedPayments($workspace, array_map(strval(...), array_keys($paymentIds))),
            $complete,
            $seriesComplete,
            $reachComplete,
            $mirrorComplete,
        );
    }

    /** @return list<SquareAuditAgreement> */
    private function agreements(Workspace $workspace): array
    {
        $rows = ClientAgreement::query()->where('client_agreements.workspace_id', $workspace->id)->whereNotNull('client_agreements.square_series_key')
            ->join('client_companies as c', static fn ($join) => $join->on('c.id', '=', 'client_agreements.client_company_id')->where('c.workspace_id', $workspace->id))
            ->orderBy('client_agreements.square_series_key')
            ->limit(self::MAX_AGREEMENTS + 1)
            ->get(['client_agreements.*', 'c.public_id as company_public_id']);
        $agreements = [];
        foreach ($rows as $agreement) {
            $cadence = $agreement->billsOnARecurringCadence() ? $agreement->effectiveBillingCadence() : null;
            $months = $cadence?->monthsInCycle() ?? 1;
            $amount = $agreement->period_retainer_amount ?? ((int) ($agreement->getAttribute('retainer_amount') ?? 0)) * $months;
            $agreements[] = new SquareAuditAgreement(
                $agreement->public_id,
                (string) $agreement->getAttribute('company_public_id'),
                (string) $agreement->square_series_key,
                $agreement->starts_on->toDateString(),
                $cadence,
                $amount > 0 ? $amount : null,
                strtoupper((string) $agreement->getAttribute('currency')),
                $agreement->ends_on?->toDateString(),
            );
        }

        return $agreements;
    }

    /**
     * The svc invoice each of these Square invoices is actively linked to.
     *
     * @param  list<string>  $squareInvoiceIds
     * @return array<string, SquareAuditLinkedInvoice>
     */
    private function links(Workspace $workspace, array $squareInvoiceIds): array
    {
        $links = [];
        foreach (array_chunk($squareInvoiceIds, self::CHUNK) as $chunk) {
            $rows = DB::table('invoice_square_links as l')->where('l.workspace_id', $workspace->id)->whereIn('l.active_square_invoice_slot', $chunk)
                ->join('client_invoices as i', static fn ($join) => $join->on('i.id', '=', 'l.active_invoice_slot')->where('i.workspace_id', $workspace->id))
                ->join('client_companies as c', static fn ($join) => $join->on('c.id', '=', 'i.client_company_id')->where('c.workspace_id', $workspace->id))
                ->get(['l.active_square_invoice_slot', 'i.public_id as invoice_public_id', 'c.public_id as company_public_id']);
            foreach ($rows as $row) {
                $links[(string) $row->active_square_invoice_slot] = new SquareAuditLinkedInvoice((string) $row->invoice_public_id, (string) $row->company_public_id);
            }
        }

        return $links;
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, true>
     */
    private function mappedCustomers(Workspace $workspace, array $customerIds): array
    {
        $mapped = [];
        foreach (array_chunk($customerIds, self::CHUNK) as $chunk) {
            $mapped = [...$mapped, ...CompanySquareCustomer::query()->where('workspace_id', $workspace->id)->whereIn('active_customer_slot', $chunk)->pluck('active_customer_slot')->all()];
        }

        return self::set($mapped);
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, true>
     */
    private function retiredCustomers(Workspace $workspace, array $customerIds): array
    {
        $retired = [];
        foreach (array_chunk($customerIds, self::CHUNK) as $chunk) {
            $retired = [...$retired, ...SquareCustomerSnapshot::onlyTrashed()->where('workspace_id', $workspace->id)->whereIn('square_customer_id', $chunk)->pluck('square_customer_id')->all()];
        }

        return self::set($retired);
    }

    /**
     * @param  list<string>  $paymentIds
     * @return array<string, true>
     */
    private function recordedPayments(Workspace $workspace, array $paymentIds): array
    {
        $recorded = [];
        foreach (array_chunk($paymentIds, self::CHUNK) as $chunk) {
            $recorded = [...$recorded, ...ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('provider', 'square')
                ->whereIn('provider_payment_identifier', $chunk)->pluck('provider_payment_identifier')->all()];
        }

        return self::set($recorded);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, true>
     */
    private static function set(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $set[$value] = true;
            }
        }

        return $set;
    }
}
