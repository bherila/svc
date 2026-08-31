<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AllocationService;
use App\Services\Billing\TimeEntrySplitter;
use App\Support\Billing\SubcontractorBillingMode;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Recombination is by lineage here, not by matching fields, so the cases that
 * matter are the ones the predecessor's merge key got wrong.
 */
final class AllocationServiceTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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

    public function test_a_subcontractor_billing_snapshot_survives_split_and_recombination(): void
    {
        $entry = $this->entry(180);
        $entry->forceFill([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 7500,
            'subcontractor_cost_currency' => 'USD',
            'subcontractor_cost_metadata' => ['source' => 'synthetic-assignment'],
        ])->save();

        $parts = app(TimeEntrySplitter::class)->splitEntry($entry, 120);

        foreach ($parts as $part) {
            $this->assertSame(SubcontractorBillingMode::FlatHourly, $part->subcontractor_billing_mode);
            $this->assertSame(7500, $part->subcontractor_cost_amount);
            $this->assertSame('USD', $part->subcontractor_cost_currency);
            $this->assertSame(['source' => 'synthetic-assignment'], $part->subcontractor_cost_metadata);
        }

        $this->assertSame(1, $this->recombine());
        $survivor = ClientTimeEntry::query()->sole();
        $this->assertSame(SubcontractorBillingMode::FlatHourly, $survivor->subcontractor_billing_mode);
        $this->assertSame(7500, $survivor->subcontractor_cost_amount);
        $this->assertSame('USD', $survivor->subcontractor_cost_currency);
        $this->assertSame(['source' => 'synthetic-assignment'], $survivor->subcontractor_cost_metadata);
    }

    public function test_fragments_with_different_subcontractor_modes_do_not_recombine(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);
        $parts['overflow']->forceFill([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
        ])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
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

    public function test_fragments_with_and_without_a_task_do_not_recombine(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);
        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Synthetic Divergent Task',
        ]);

        $parts['overflow']->forceFill(['client_task_id' => $task->id])->save();

        $this->assertNull($parts['primary']->fresh()?->client_task_id);
        $this->assertSame($task->id, $parts['overflow']->fresh()?->client_task_id);
        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    public function test_fragments_with_and_without_an_approval_author_do_not_recombine(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);
        $approver = User::factory()->create();

        $parts['overflow']->forceFill(['approved_by_user_id' => $approver->id])->save();

        $this->assertNull($parts['primary']->fresh()?->approved_by_user_id);
        $this->assertSame($approver->id, $parts['overflow']->fresh()?->approved_by_user_id);
        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    public function test_fragments_with_and_without_an_approval_timestamp_do_not_recombine(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);

        $parts['overflow']->forceFill(['approved_at' => '2026-03-14 09:30:00'])->save();

        $this->assertNull($parts['primary']->fresh()?->approved_at);
        $this->assertSame(
            '2026-03-14 09:30:00',
            $parts['overflow']->fresh()?->approved_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    #[DataProvider('fragmentFieldsThatMustAgree')]
    public function test_fragments_with_divergent_preserved_fields_do_not_recombine(string $field, mixed $value): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);

        if ($field === 'billing_rate_source') {
            // Provenance matters even when the money matches: `explicit` must
            // not be replaced by a rate resolved from an agreement later.
            $parts['primary']->forceFill([
                'billing_rate_amount' => 15000,
                'billing_rate_source' => 'explicit',
            ])->save();
            $parts['overflow']->forceFill(['billing_rate_amount' => 15000])->save();
        }

        if ($field === 'client_project_id') {
            $value = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'name' => 'Synthetic Divergent Project',
            ])->id;
        }

        if ($field === 'user_id') {
            $value = User::factory()->create()->id;
        }

        if (str_starts_with($field, 'subcontractor_cost_')) {
            foreach ($parts as $part) {
                $part->forceFill([
                    'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
                    'subcontractor_cost_amount' => 5000,
                    'subcontractor_cost_currency' => 'USD',
                    'subcontractor_cost_metadata' => ['source' => 'synthetic-baseline'],
                ])->save();
            }
        }

        $parts['overflow']->forceFill([$field => $value])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
        $this->assertNotNull($parts['overflow']->fresh()?->{$field});

        if ($field === 'billing_rate_source') {
            $this->assertSame(15000, $parts['primary']->fresh()?->billing_rate_amount);
            $this->assertSame(15000, $parts['overflow']->fresh()?->billing_rate_amount);
        }
    }

    /**
     * Two fragments that differ only across a field boundary must not merge.
     *
     * The signature used to join fields with `|`, and the free-text ones -
     * description, client-visible description, job type - can contain it. A
     * value carrying the delimiter shifts every field after it, so entries
     * that genuinely differ collapse to one signature, and recombination folds
     * the edited fragment into the survivor and deletes it.
     *
     * The two rows here are constructed to be exactly that pair: the same
     * characters, split differently across two adjacent fields.
     */
    public function test_a_delimiter_in_free_text_cannot_forge_a_matching_signature(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);

        $parts['primary']->forceFill([
            'description' => 'Investigate',
            'client_visible_description' => 'billing|export',
        ])->save();

        // Same characters, one boundary further along.
        $parts['overflow']->forceFill([
            'description' => 'Investigate|billing',
            'client_visible_description' => 'export',
        ])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
        $this->assertSame('Investigate', $parts['primary']->fresh()?->description);
        $this->assertSame('Investigate|billing', $parts['overflow']->fresh()?->description);
    }

    /**
     * A real null and the literal string "null" are different values.
     *
     * The signature used a `?? 'null'` sentinel, so a fragment with no job
     * type and one whose job type a person had typed as "null" compared equal
     * - and a match here deletes a fragment rather than merely declining to
     * merge one. Encoding the tuple as JSON fixed the delimiter problem and
     * left this one, which is why the comparison is now the typed array
     * itself.
     */
    public function test_an_absent_value_is_not_the_word_null(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);

        $parts['primary']->forceFill(['job_type' => null])->save();
        $parts['overflow']->forceFill(['job_type' => 'null'])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
        $this->assertNull($parts['primary']->fresh()?->job_type);
        $this->assertSame('null', $parts['overflow']->fresh()?->job_type);
    }

    /**
     * And a numeric id is not its own decimal string.
     *
     * The same sentinel shape stringified every id, so a comparison that
     * should have been `1 === 1` became `'1' === '1'` - harmless until a value
     * arrives that is equal as a string and not as a value.
     */
    public function test_fragments_differing_only_in_rate_type_do_not_merge(): void
    {
        $parts = app(TimeEntrySplitter::class)->splitEntry($this->entry(180), 120);

        $parts['primary']->forceFill(['billing_rate_amount' => 15000])->save();
        $parts['overflow']->forceFill(['billing_rate_amount' => null])->save();

        $this->assertSame(0, $this->recombine());
        $this->assertSame(2, $this->entryCount());
    }

    /** @return array<string, array{string, mixed}> */
    public static function fragmentFieldsThatMustAgree(): array
    {
        return [
            'billable flag' => ['is_billable', false],
            'deferred flag' => ['is_deferred', true],
            'client visibility' => ['is_visible_to_client', true],
            'currency' => ['currency', 'EUR'],
            'work date' => ['worked_on', '2026-03-15'],
            'description' => ['description', 'Synthetic divergent work'],
            'client-visible description' => ['client_visible_description', 'Synthetic client-safe divergence'],
            'job type' => ['job_type', 'Support'],
            // The ids are replaced with real same-tenant fixtures in the test
            // body so both SQLite and MariaDB exercise their foreign keys.
            'project' => ['client_project_id', 0],
            'user' => ['user_id', 0],
            // `null` means this row has no stamped rate provenance; `agreement`
            // means a later workflow re-resolved it. The monetary amount can be
            // identical while those meanings cannot be folded together.
            'billing rate source' => ['billing_rate_source', 'agreement'],
            'subcontractor cost' => ['subcontractor_cost_amount', 7500],
            'subcontractor cost currency' => ['subcontractor_cost_currency', 'EUR'],
            'subcontractor cost metadata' => ['subcontractor_cost_metadata', ['source' => 'synthetic-divergence']],
        ];
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

    public function test_recombination_refuses_when_a_lineage_root_claims_another_workspace(): void
    {
        $root = $this->entry(180);
        $firstSplit = app(TimeEntrySplitter::class)->splitEntry($root, 60);
        app(TimeEntrySplitter::class)->splitEntry($firstSplit['overflow'], 60);
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Root Elsewhere',
            'slug' => 'root-elsewhere',
        ]);

        // Moving the root alone leaves its company and project behind, which the
        // composite tenant keys refuse. Enforcement is suspended because the
        // recombination guard is the subject: a database migrated from before
        // those keys can still hold a row shaped like this.
        $this->writingLegacyCrossTenantRows(
            fn () => $root->forceFill(['workspace_id' => $otherWorkspace->id])->save(),
        );

        try {
            $this->recombine();
            $this->fail('A cross-workspace root must stop fragment recombination.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertSame(3, ClientTimeEntry::query()->count());
        $this->assertSame(2, ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('split_from_time_entry_id', $root->id)
            ->count());
        $this->assertSame($otherWorkspace->id, $root->fresh()?->workspace_id);
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
        $this->assertSame(180, (int) ClientTimeEntry::query()->sum('minutes'));
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
