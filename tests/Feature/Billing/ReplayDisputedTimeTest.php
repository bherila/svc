<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * Replay demotes the invoices it regenerates and deletes their time
 * allocations, and a charged allocation is what keeps a row disputed after
 * its charge billable (`ClientTimeEntry::scopeNotHeldForDispute()`). Replay
 * must keep that row's charged eligibility, and still leave out a row a
 * dispute held before anything charged it.
 */
final class ReplayDisputedTimeTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = $this->syntheticWorkspace('Replay dispute');
        $this->company = $this->syntheticCompany($this->workspace, 'Replay dispute');
        $this->project = $this->syntheticProject($this->company, 'Replay dispute work');
        $this->member = $this->syntheticMember($this->workspace, 'Replay dispute worker');
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic monthly retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-07-01', 'billing_cadence' => 'monthly',
            'retainer_amount' => 10000, 'retainer_minutes' => 60, 'hourly_rate_amount' => 10000, 'rollover_months' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_replay_keeps_the_charge_on_time_disputed_after_it_and_leaves_out_time_held_before_it(): void
    {
        $this->hours('2026-07-08');
        $chargedThenDisputed = $this->hours('2026-07-09');
        $heldBeforeCharge = $this->hours('2026-07-10');
        ClientTimeEntry::query()->whereKey($heldBeforeCharge->id)->update(['client_disputed_at' => '2026-07-20 12:00:00']);

        Carbon::setTestNow(Carbon::parse('2026-08-15'));
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        Carbon::setTestNow(Carbon::parse('2026-09-01'));
        foreach (ClientInvoice::query()->where('workspace_id', $this->workspace->id)->get() as $invoice) {
            app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
        }
        $charged = DB::table('client_invoice_line_time_entries')->where('workspace_id', $this->workspace->id)->pluck('client_time_entry_id')->all();
        $this->assertContains($chargedThenDisputed->id, $charged);
        $this->assertNotContains($heldBeforeCharge->id, $charged, 'The held row never billed.');

        // The client disputes an hour the issued invoice already charged.
        ClientTimeEntry::query()->whereKey($chargedThenDisputed->id)->update(['client_disputed_at' => '2026-09-05 12:00:00']);
        // The replay's vantage point, where history also holds October's draft.
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id, '--as-of' => '2026-09-15'])
            ->expectsOutputToContain('No unexplained historical billing divergence remains.')
            ->assertSuccessful();

        // Rolled back: the dispute is still open on the row.
        $this->assertNotNull($chargedThenDisputed->fresh()?->client_disputed_at);
        $this->assertNotNull($heldBeforeCharge->fresh()?->client_disputed_at);
    }

    private function hours(string $day): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->member->id, 'worked_on' => $day, 'minutes' => 60, 'description' => 'Synthetic replay work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'currency' => 'USD',
        ]);
    }
}
