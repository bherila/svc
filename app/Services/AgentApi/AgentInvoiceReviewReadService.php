<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\AgreementHandoverAudit;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\UncoveredCadencePeriods;
use App\Services\Square\Audit\SquareBillingAudit;
use App\Services\Square\SquarePaymentMismatchAudit;
use App\Support\AgentApi\StaleAndMissingInvoiceAudit;
use App\Support\WorkspaceClock;

/** Workspace-scoped audit with bounded batches and at most 100 disclosed identifiers per category. */
final class AgentInvoiceReviewReadService
{
    public function __construct(private readonly AgentAccess $access, private readonly UncoveredCadencePeriods $uncovered, private readonly WorkspaceClock $clock) {}

    public function staleAndMissing(User|AgentPrincipal $actor, Workspace $workspace): StaleAndMissingInvoiceAudit
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 404);
        $today = $this->clock->today($workspace);
        // Late on either date the operator set (#381): the day it falls due,
        // or the day it was meant to be issued. A null date is no plan, and
        // never makes a draft stale on its own.
        $stale = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id))->where('status', 'draft')
            ->where(fn ($late) => $late->whereDate('due_date', '<', $today->toDateString())->orWhereDate('scheduled_issue_date', '<', $today->toDateString()));
        $count = (clone $stale)->count();
        $balances = [];
        foreach ((clone $stale)->selectRaw('currency, SUM(balance_amount) as aggregate_balance')->groupBy('currency')->get() as $row) {
            $balances[$row->currency] = (int) $row->getAttribute('aggregate_balance');
        }
        ksort($balances);
        $ids = array_values((clone $stale)->orderBy('id')->limit(100)->get()->map(fn (ClientInvoice $invoice): string => $invoice->public_id)->all());
        $missingCount = 0;
        $missing = [];
        $this->uncovered->each($workspace, $today, function (ClientAgreement $agreement, BillingCycle $cycle) use (&$missingCount, &$missing): void {
            $missingCount++;
            if (count($missing) < 100) {
                $missing[] = ['agreement_id' => $agreement->public_id, 'period_start' => $cycle->start->toDateString(), 'period_end' => $cycle->end->toDateString()];
            }
        });

        return new StaleAndMissingInvoiceAudit($today->toDateString(), $count, $balances, $ids, $missingCount, $missing,
            app(SquarePaymentMismatchAudit::class)->report($workspace), app(SquareBillingAudit::class)->report($workspace), app(AgreementHandoverAudit::class)->report($workspace));
    }
}
