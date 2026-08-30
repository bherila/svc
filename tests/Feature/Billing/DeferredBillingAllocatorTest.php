<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\DeferredAllocationResult;
use App\Services\Billing\DeferredBillingAllocator;
use App\Support\Billing\SubcontractorBillingMode;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The predecessor covered the allocator only through the invoicing service, so
 * these are new. They pin the rules its docblock states: entries are never
 * split, only whole entries that fit are billed, and the rest roll forward.
 */
final class DeferredBillingAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Deferred', 'slug' => 'deferred']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Deferred Client', 'slug' => 'deferred-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Deferred Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_bills_whole_entries_in_date_order_until_capacity_runs_out(): void
    {
        $first = $this->deferred(60, '2026-03-01');
        $second = $this->deferred(90, '2026-03-02');
        $third = $this->deferred(60, '2026-03-03');

        // 2.5h of capacity: the first two fit exactly, the third does not.
        $result = $this->allocate(2.5);

        $this->assertSame(2.5, $result->hoursBilled);
        $this->assertSame(
            [$first->id, $second->id],
            array_map(static fn ($candidate): int => $candidate->id(), $result->billed),
        );
        $this->assertCount(1, $result->skipped);
        $this->assertSame($third->id, $result->skipped[0]['id']);
    }

    public function test_an_entry_that_does_not_fit_is_never_split(): void
    {
        $this->deferred(180, '2026-03-01');

        $result = $this->allocate(2.0);

        $this->assertSame(0.0, $result->hoursBilled);
        $this->assertSame([], $result->billed);
        $this->assertCount(1, $result->skipped);
    }

    public function test_a_later_entry_still_fits_after_an_earlier_one_is_skipped(): void
    {
        // Candidates are considered in date order, but a skip does not stop the
        // scan: a later entry that fits the remaining capacity is still billed.
        // Best-effort fill, not strict first-fail-stop.
        $big = $this->deferred(180, '2026-03-01');
        $small = $this->deferred(30, '2026-03-02');

        $result = $this->allocate(1.0);

        $this->assertSame(0.5, $result->hoursBilled);
        $this->assertSame([$small->id], array_map(static fn ($c): int => $c->id(), $result->billed));
        $this->assertSame([$big->id], array_map(static fn (array $s): int => $s['id'], $result->skipped));
    }

    public function test_no_capacity_bills_nothing(): void
    {
        $this->deferred(30, '2026-03-01');

        foreach ([0.0, -5.0] as $capacity) {
            $result = $this->allocate($capacity);
            $this->assertSame(0.0, $result->hoursBilled);
        }
    }

    public function test_it_ignores_work_that_is_not_deferred_unapproved_or_already_billed(): void
    {
        $this->deferred(60, '2026-03-01', deferred: false);
        $this->deferred(60, '2026-03-01', status: 'draft');
        $this->deferred(60, '2026-03-01', billable: false);
        $billed = $this->deferred(60, '2026-03-01');
        $this->attachToInvoice($billed);

        $this->assertSame(0.0, $this->allocate(10.0)->hoursBilled);
    }

    public function test_it_ignores_work_dated_after_the_cutoff(): void
    {
        $this->deferred(60, '2026-04-05');

        $this->assertSame(0.0, $this->allocate(10.0)->hoursBilled);
    }

    public function test_termination_collects_every_outstanding_deferred_entry(): void
    {
        $this->deferred(600, '2026-03-01');
        $this->deferred(600, '2026-03-02');

        // Capacity is irrelevant here: nothing may be left unbilled at termination.
        $outstanding = app(DeferredBillingAllocator::class)
            ->collectForTermination($this->company, Carbon::parse('2026-03-31'));

        $this->assertCount(2, $outstanding);
    }

    public function test_termination_excludes_direct_subcontractor_work(): void
    {
        $direct = $this->deferred(60, '2026-03-01');
        $direct->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
        ]);

        $outstanding = app(DeferredBillingAllocator::class)
            ->collectForTermination($this->company, Carbon::parse('2026-03-31'));

        $this->assertTrue($outstanding->isEmpty());
    }

    public function test_termination_collects_only_the_project_scoped_to_the_agreement(): void
    {
        $included = $this->deferred(60, '2026-03-01');
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Other project',
        ]);
        $excluded = $this->deferred(60, '2026-03-02');
        $excluded->update(['client_project_id' => $otherProject->id]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'title' => 'Project agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        $outstanding = app(DeferredBillingAllocator::class)
            ->collectForTermination($this->company, Carbon::parse('2026-03-31'), $agreement);

        $this->assertSame([$included->id], $outstanding->modelKeys());
    }

    public function test_allocation_refuses_deferred_time_owned_by_another_companys_project(): void
    {
        $this->deferredAgainstAnotherCompany();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        $this->allocate(10.0);
    }

    public function test_termination_refuses_deferred_time_owned_by_another_companys_project(): void
    {
        $this->deferredAgainstAnotherCompany();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project outside this client company');

        app(DeferredBillingAllocator::class)
            ->collectForTermination($this->company, Carbon::parse('2026-03-31'));
    }

    private function allocate(float $capacityHours): DeferredAllocationResult
    {
        return app(DeferredBillingAllocator::class)
            ->allocate($this->company, Carbon::parse('2026-03-31'), $capacityHours);
    }

    private function deferred(int $minutes, string $workedOn, bool $deferred = true, string $status = 'approved', bool $billable = true): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Deferred work',
            'is_billable' => $billable,
            'is_deferred' => $deferred,
            'status' => $status,
            'currency' => 'USD',
        ]);
    }

    private function deferredAgainstAnotherCompany(): ClientTimeEntry
    {
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other Deferred Client',
            'slug' => 'other-deferred-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Deferred Project',
        ]);

        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $otherProject->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-03-01',
            'minutes' => 60,
            'description' => 'Mismatched deferred work',
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function attachToInvoice(ClientTimeEntry $entry): void
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-DEF-1',
            'currency' => 'USD',
            'status' => 'issued',
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'time',
            'description' => 'Billed already',
            'quantity' => '1.0000',
            'unit_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
    }
}
