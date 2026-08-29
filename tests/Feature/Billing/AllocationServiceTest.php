<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AllocationService;
use App\Services\Billing\TimeEntrySplitter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recombination is by lineage here, not by matching fields, so the cases that
 * matter are the ones the predecessor's merge key got wrong.
 */
final class AllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Alloc', 'slug' => 'alloc']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Alloc Client', 'slug' => 'alloc-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Alloc Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_a_split_entry_recombines_into_one(): void
    {
        $entry = $this->entry(180);
        app(TimeEntrySplitter::class)->splitEntry($entry, 120);

        $this->assertSame(2, $this->entryCount());
        $this->assertSame(1, $this->recombine());

        $this->assertSame(1, $this->entryCount());
        $this->assertSame(180, (int) ClientTimeEntry::query()->firstOrFail()->minutes);
    }

    public function test_a_repeatedly_split_entry_recombines_in_one_pass(): void
    {
        $entry = $this->entry(180);
        $parts = app(TimeEntrySplitter::class)->splitEntry($entry, 60);
        app(TimeEntrySplitter::class)->splitEntry($parts['overflow'], 60);

        $this->assertSame(3, $this->entryCount());
        $this->assertSame(2, $this->recombine());

        $this->assertSame(1, $this->entryCount());
        $this->assertSame(180, (int) ClientTimeEntry::query()->firstOrFail()->minutes);
    }

    public function test_entries_that_merely_look_alike_are_never_merged(): void
    {
        // Same day, same person, same project, same description - and genuinely
        // two pieces of work. The predecessor's merge key would fold these into
        // one; lineage does not.
        $this->entry(30);
        $this->entry(30);

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    public function test_a_group_with_any_billed_fragment_is_left_alone(): void
    {
        $entry = $this->entry(180);
        $parts = app(TimeEntrySplitter::class)->splitEntry($entry, 120);
        $this->bill($parts['primary']);

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    public function test_a_fragment_decided_on_separately_is_left_alone(): void
    {
        $entry = $this->entry(180);
        $parts = app(TimeEntrySplitter::class)->splitEntry($entry, 120);
        // Someone approved one half on its own: it is now its own record of work.
        $parts['overflow']->forceFill(['status' => 'approved'])->save();
        $parts['primary']->forceFill(['status' => 'draft'])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    public function test_the_survivor_stops_being_a_fragment(): void
    {
        $entry = $this->entry(180);
        app(TimeEntrySplitter::class)->splitEntry($entry, 120);

        $this->recombine();

        $this->assertNull(ClientTimeEntry::query()->firstOrFail()->split_from_time_entry_id);
    }

    public function test_another_workspace_is_not_touched(): void
    {
        $entry = $this->entry(180);
        app(TimeEntrySplitter::class)->splitEntry($entry, 120);

        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Other Client', 'slug' => 'other-client',
        ]);

        $this->assertSame(0, app(AllocationService::class)->recombineUnlinkedFragments($other, $otherCompany));
        $this->assertSame(2, $this->entryCount());
    }

    public function test_recombination_refuses_fragments_whose_project_belongs_to_another_company(): void
    {
        $entry = $this->entry(180);
        $parts = app(TimeEntrySplitter::class)->splitEntry($entry, 120);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other Client',
            'slug' => 'alloc-other-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Project',
        ]);

        foreach ($parts as $part) {
            $part->forceFill(['client_project_id' => $otherProject->id])->save();
        }

        try {
            $this->recombine();
            $this->fail('A mismatched project chain must stop fragment recombination.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertSame(2, $this->entryCount());
        $this->assertSame(180, ClientTimeEntry::query()->sum('minutes'));
    }

    private function recombine(): int
    {
        return app(AllocationService::class)->recombineUnlinkedFragments($this->workspace, $this->company);
    }

    private function entryCount(): int
    {
        return ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->count();
    }

    private function entry(int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-03-14',
            'minutes' => $minutes,
            'description' => 'Standup',
            'job_type' => 'Software Development',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function bill(ClientTimeEntry $entry): void
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ALLOC-1',
            'currency' => 'USD',
            'status' => 'issued',
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'time',
            'description' => 'Billed',
            'quantity' => '2.0000',
            'unit_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 2000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
    }
}
