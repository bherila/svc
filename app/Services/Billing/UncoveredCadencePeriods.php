<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every active recurring agreement whose current cadence period has started
 * with no non-void invoice: what `billing_audit.stale_and_missing` reports as
 * missing and what `svc:billing:generate-cadence-drafts` creates, read once so
 * the two cannot disagree about which periods are uncovered.
 *
 * Only the period containing today. An older gap is history an operator may
 * have settled off the books, and is not this reader's to reopen.
 */
final class UncoveredCadencePeriods
{
    public function __construct(private readonly BillingCycleResolver $cycles) {}

    /** @param Closure(ClientAgreement, BillingCycle): void $visit */
    public function each(Workspace $workspace, CarbonImmutable $today, Closure $visit): void
    {
        ClientAgreement::query()->where('workspace_id', $workspace->id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->where('status', 'active')->whereDate('starts_on', '<=', $today->toDateString())
            ->where(fn ($end) => $end->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
            ->orderBy('id')->chunkById(200, function (Collection $agreements) use ($workspace, $today, $visit): void {
                $recurring = $agreements->filter(fn (ClientAgreement $agreement): bool => $agreement->billsOnARecurringCadence());
                if ($recurring->isEmpty()) {
                    return;
                }
                $periods = [];
                foreach ($recurring as $agreement) {
                    // Cadence terms are date-only values. Compare calendar days
                    // in the resolver, not workspace midnight against UTC dates.
                    $periods[$agreement->id] = $this->cycles->cycleContaining($agreement, Carbon::parse($today->toDateString())->startOfDay());
                }
                // A distinct agreement and status is enough to classify
                // coverage. Even duplicate legacy invoices cannot make this read
                // hydrate more than one row per status for each of the 200
                // agreements in this batch.
                $covered = ClientInvoice::query()->where('workspace_id', $workspace->id)
                    ->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
                    ->where(function ($candidates) use ($recurring, $periods): void {
                        foreach ($recurring as $agreement) {
                            $cycle = $periods[$agreement->id];
                            $candidates->orWhere(function ($candidate) use ($agreement, $cycle): void {
                                $candidate->where('client_agreement_id', $agreement->id)->where('client_company_id', $agreement->client_company_id)
                                    ->where(function ($period) use ($cycle): void {
                                        $period->whereDate('cycle_start', $cycle->start->toDateString())
                                            ->orWhere(fn ($legacy) => $legacy->whereNull('cycle_start')->whereDate('service_period_end', $cycle->start->copy()->subDay()->toDateString()));
                                    });
                            });
                        }
                    })->distinct()->get(['client_agreement_id', 'status'])
                    ->filter(static fn (ClientInvoice $claim): bool => InvoiceStatus::claimsPeriod($claim->status))->keyBy('client_agreement_id');
                foreach ($recurring as $agreement) {
                    if (! $covered->has($agreement->id)) {
                        $visit($agreement, $periods[$agreement->id]);
                    }
                }
            });
    }
}
