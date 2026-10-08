<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingCycleResolver;
use App\Support\AgentApi\StaleAndMissingInvoiceAudit;
use App\Support\Billing\InvoiceKind;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/** Workspace-scoped audit with bounded batches and at most 100 disclosed identifiers per category. */
final class AgentInvoiceReviewReadService
{
    public function __construct(private readonly AgentAccess $access, private readonly BillingCycleResolver $cycles, private readonly WorkspaceClock $clock) {}

    public function staleAndMissing(User|AgentPrincipal $actor, Workspace $workspace): StaleAndMissingInvoiceAudit
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $today = $this->clock->today($workspace);
        $stale = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->where('status', 'draft')->whereDate('due_date', '<', $today->toDateString());
        $count = (clone $stale)->count();
        $balances = [];
        foreach ((clone $stale)->selectRaw('currency, SUM(balance_amount) as aggregate_balance')->groupBy('currency')->get() as $row) {
            $balances[$row->currency] = (int) $row->getAttribute('aggregate_balance');
        }
        ksort($balances);
        $ids = array_values((clone $stale)->orderBy('id')->limit(100)->get()->map(fn (ClientInvoice $invoice): string => $invoice->public_id)->all());
        $missingCount = 0;
        $missing = [];
        ClientAgreement::query()->where('workspace_id', $workspace->id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->where('status', 'active')->whereDate('starts_on', '<=', $today->toDateString())
            ->where(fn ($end) => $end->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
            ->orderBy('id')->chunkById(200, function (Collection $agreements) use ($workspace, $today, &$missingCount, &$missing): void {
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
                // A distinct agreement ID is enough to classify coverage. Even
                // duplicate legacy invoices cannot make this read hydrate more
                // than the 200 agreements in this batch.
                $covered = ClientInvoice::query()->where('workspace_id', $workspace->id)
                    ->where('status', '!=', 'void')->where(fn ($kind) => $kind->whereNull('invoice_kind')->orWhere('invoice_kind', InvoiceKind::CadencePeriod->value))
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
                    })->distinct()->get(['client_agreement_id'])->keyBy('client_agreement_id');
                foreach ($recurring as $agreement) {
                    $cycle = $periods[$agreement->id];
                    if (! $covered->has($agreement->id)) {
                        $missingCount++;
                        if (count($missing) < 100) {
                            $missing[] = ['agreement_id' => $agreement->public_id, 'period_start' => $cycle->start->toDateString(), 'period_end' => $cycle->end->toDateString()];
                        }
                    }
                }
            });

        return new StaleAndMissingInvoiceAudit($today->toDateString(), $count, $balances, $ids, $missingCount, $missing);
    }
}
