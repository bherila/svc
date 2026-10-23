<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Collection;

/** Historical coverage is evidence to review, never permission to rewrite an invoice. */
final class AgreementHandoverAudit
{
    public function __construct(private readonly AgreementHandoverRepository $handovers = new AgreementHandoverRepository) {}

    /** @return array{count: int, invoices: list<array{invoice_id: string, successor_id: string, successor_starts_on: string, coverage_start: string, coverage_end: string}>, truncated: bool} */
    public function report(?Workspace $workspace): array
    {
        $count = 0;
        $items = [];
        $query = ClientInvoice::query();
        if ($workspace !== null) {
            $query->where('workspace_id', $workspace->id);
        }
        $query
            ->whereHas('clientCompany', fn ($companies) => $companies->whereColumn('client_companies.workspace_id', 'client_invoices.workspace_id'))
            ->whereHas('agreement', fn ($agreements) => $agreements
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_agreements.client_company_id', 'client_invoices.client_company_id'))
            ->with('agreement')
            ->whereIn('status', InvoiceStatus::charged())
            ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
            ->chunkById(200, function (Collection $invoices) use (&$count, &$items): void {
                $scopes = array_values($invoices->map(fn (ClientInvoice $invoice): array => [
                    'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
                ])->unique(fn (array $scope): string => implode(':', $scope))->all());
                $handovers = $this->handovers->forCompanies($scopes);
                foreach ($invoices as $invoice) {
                    $handover = $invoice->client_agreement_id === null ? null : ($handovers[$invoice->client_agreement_id] ?? null);
                    // Schedules sell their stated service period. Cadence invoices
                    // sell cycle_start/end; legacy monthly rows sell the next month.
                    $start = $invoice->cycle_start ?? ($invoice->client_billing_schedule_id !== null ? $invoice->service_period_start
                        : ($invoice->agreement?->billing_cadence === 'monthly' ? $invoice->service_period_end?->addDay()->startOfMonth() : null));
                    $end = $invoice->cycle_end ?? ($invoice->client_billing_schedule_id === null
                        ? $start?->endOfMonth() : $invoice->service_period_end);
                    if ($handover === null || $start === null || $end === null || ! $handover->overlaps($start->toDateString(), $end->toDateString())) {
                        continue;
                    }
                    $count++;
                    if (count($items) < 100) {
                        $items[] = ['invoice_id' => $invoice->public_id, 'successor_id' => $handover->successorId,
                            'successor_starts_on' => $handover->startsOn, 'coverage_start' => $start->toDateString(), 'coverage_end' => $end->toDateString()];
                    }
                }
            });

        return ['count' => $count, 'invoices' => $items, 'truncated' => $count > count($items)];
    }
}
