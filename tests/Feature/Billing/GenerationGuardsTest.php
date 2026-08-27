<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\WorkspaceAuthorization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the generator has to hold when the inputs are not the happy path.
 *
 * Each of these describes a way an invoice could be written against the wrong
 * money: a settled invoice rewritten, a tenant's terms applied to another's
 * work, a partial month billed whole, or work predating the agreement charged
 * against it.
 */
final class GenerationGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Guards', 'slug' => 'guards']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Guards Client', 'slug' => 'guards-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Guards Project',
        ]);
        $this->user = User::factory()->create();
    }

    /**
     * Money has changed hands on a partially paid invoice, so rebuilding its
     * lines would rewrite what the client already paid against.
     */
    public function test_a_partially_paid_invoice_refuses_regeneration(): void
    {
        $this->agreement();
        $this->entry('2024-02-14', 120);

        $invoice = $this->generate('2024-02-01', '2024-02-29');
        $invoice->forceFill(['status' => 'partially_paid'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be modified');

        $this->generate('2024-02-01', '2024-02-29');
    }

    /**
     * An agreement handed in by a caller is not automatically this company's.
     */
    public function test_an_agreement_from_another_company_is_refused(): void
    {
        $this->agreement();

        $other = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Other', 'slug' => 'other',
        ]);
        $foreign = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $other->id,
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 999900, 'hourly_rate_amount' => 99900,
            'rollover_months' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different client company');

        app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $foreign,
        );
    }

    /**
     * An agreement covering half a month sells half a retainer. Billing the
     * whole one overcharges and grants a full pool against a partial term.
     */
    public function test_a_month_the_agreement_only_partly_covers_is_prorated(): void
    {
        // Starts mid-March, so the March retainer covers 17 of 31 days.
        $this->agreement(startsOn: '2024-03-15');
        $this->entry('2024-02-20', 60);

        $invoice = $this->generate('2024-02-01', '2024-02-29');
        $retainer = $invoice->lines()->where('type', 'retainer')->first();

        $this->assertNotNull($retainer);
        $this->assertLessThan(
            150000,
            (int) $retainer->total_amount,
            'A partly-covered month must not be billed as a whole one',
        );
        $this->assertGreaterThan(0, (int) $retainer->total_amount);
    }

    /**
     * Work done before the agreement existed has no retainer to draw on, and
     * must not arrive as debt against the retainer the client has now.
     */
    public function test_work_predating_the_agreement_does_not_become_retainer_debt(): void
    {
        $this->agreement(startsOn: '2024-03-01');
        $this->entry('2024-01-10', 3000); // 50h, long before the agreement

        $invoice = $this->generate('2024-03-01', '2024-03-31');

        $this->assertSame(
            0.0,
            (float) $invoice->negative_hours_balance,
            'Pre-agreement work must not be carried in as a debt against the new retainer',
        );
    }

    /**
     * Voiding must release a milestone's claim, or the replacement invoice omits
     * it permanently - the generator only picks up unclaimed tasks.
     */
    public function test_voiding_an_invoice_releases_its_milestone_claim(): void
    {
        $this->agreement();
        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Deliverable',
            'status' => 'completed',
            'completed_at' => '2024-02-20',
            'milestone_price_amount' => 25000,
        ]);

        $invoice = $this->generate('2024-02-01', '2024-02-29');
        $this->assertNotNull($task->refresh()->client_invoice_line_id);

        $lifecycle = new InvoiceLifecycleService(app(WorkspaceAuthorization::class));
        $lifecycle->issue($invoice->refresh());
        $lifecycle->void($invoice->refresh(), null, 'Reissued');

        $this->assertNull(
            $task->refresh()->client_invoice_line_id,
            'A voided invoice must give the milestone back',
        );
    }

    /**
     * A partially paid retainer period must not be sold a second time.
     *
     * The guard listed issued, paid and void by hand and omitted
     * partially_paid, so a cycle the client had begun paying for was offered
     * again on the next generation run.
     */
    public function test_a_partially_paid_cycle_is_not_sold_again(): void
    {
        $this->agreement();
        $this->entry('2024-02-14', 60);

        $first = $this->generate('2024-02-01', '2024-02-29');
        $first->forceFill(['status' => 'partially_paid'])->save();

        $results = app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $sold = ClientInvoice::query()
            ->where('client_company_id', $this->company->id)
            ->whereDate('cycle_start', '2024-03-01')
            ->count();

        $this->assertSame(1, $sold, 'The March retainer was already sold and part-paid');
        $this->assertNotSame([], $results['skipped'], 'The run should report the cycle as skipped');
    }

    private function generate(string $from, string $to): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse($from),
            Carbon::parse($to),
        )->refresh();
    }

    private function agreement(string $startsOn = '2024-01-01'): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => $startsOn,
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 2,
        ]);
    }

    private function entry(string $workedOn, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
