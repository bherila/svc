<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Walking a company's billing history through time: its drafts, and each tick
 * that generates the periods due and issues what is dated by then. Shared by
 * the scripted-history invariant classes, which are split so that ParaTest,
 * scheduling by class, can run the long properties beside the rest.
 */
trait WalksBillingHistories
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return Collection<int, ClientInvoice> */
    private function drafts(ClientCompany $company): Collection
    {
        return ClientInvoice::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)
            ->where('status', InvoiceStatus::Draft->value)->orderBy('service_period_start')->orderBy('id')->get();
    }

    /** Travel to `$at`, generate every period due, and issue every draft - before `$before` only, when given. */
    private function tickAndIssue(ClientCompany $company, Workspace $workspace, CarbonImmutable $at, ?string $before): void
    {
        $this->travelTo($at);
        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        foreach ($this->drafts($company) as $draft) {
            $dated = $draft->issue_date?->toDateString();
            if (($dated === null || $dated <= $at->toDateString()) && ($before === null || ($draft->cycle_start !== null && $draft->cycle_start->toDateString() < $before))) {
                app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $workspace);
            }
        }
    }
}
