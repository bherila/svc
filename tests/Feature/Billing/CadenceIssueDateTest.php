<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cadence invoice is issued on the first day of the cycle it sells.
 *
 * The generators left `issue_date` empty, and `InvoiceLifecycleService::issue()`
 * fills an empty one with today - so the October invoice read "issued 5
 * October" (and was due that day) whenever the operator got to it on the fifth.
 */
final class CadenceIssueDateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Issue dates', 'slug' => 'issue-dates']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Beacon Labs', 'slug' => 'beacon-labs',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Labs',
        ]);
    }

    public function test_a_monthly_draft_is_dated_the_first_of_the_month_it_sells_and_issuing_later_keeps_it(): void
    {
        $agreement = $this->agreement('monthly');
        $this->entry('2026-09-15', 90);

        $draft = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement,
        );
        $this->assertSame('2026-10-01', $draft->issue_date?->toDateString());

        $this->travelTo(Carbon::parse('2026-10-05 15:00:00'));
        $issued = app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $this->assertSame('issued', $issued->status);
        $this->assertSame('2026-10-01', $issued->issue_date?->toDateString());
        $this->assertSame('2026-10-01', $issued->due_date?->toDateString());
    }

    public function test_a_regenerated_draft_is_dated_again(): void
    {
        $agreement = $this->agreement('monthly');
        $service = app(ClientInvoicingService::class);
        $draft = $service->generateInvoice($this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);
        $draft->forceFill(['issue_date' => null])->save();

        $refreshed = $service->generateInvoice($this->company, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $agreement);

        $this->assertSame($draft->id, $refreshed->id);
        $this->assertSame('2026-10-01', $refreshed->issue_date?->toDateString());
    }

    public function test_a_quarterly_draft_is_dated_the_first_day_of_the_quarter_it_sells(): void
    {
        $agreement = $this->agreement('quarterly');

        $draft = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $agreement,
        );
        $this->assertSame('2026-04-01', $draft->issue_date?->toDateString());

        $draft->forceFill(['issue_date' => null])->save();
        $refreshed = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $agreement,
        );
        $this->assertSame('2026-04-01', $refreshed->issue_date?->toDateString());
    }

    private function agreement(string $cadence): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 375000,
            'hourly_rate_amount' => 37500,
            'billing_cadence' => $cadence,
            'rollover_months' => 1,
        ]);
    }

    private function entry(string $workedOn, int $minutes): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 37500,
            'currency' => 'USD',
        ]);
    }
}
