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
use App\Services\Billing\HeldWorkBasisRecorder;
use App\Services\Billing\InterimOverageGenerator;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every column of a time entry is decided for each issue-time digest: the
 * held-work basis a cadence draft records (docs/ux-overhaul.md 7b″) and the
 * time fingerprint an interim overage claim is checked against.
 *
 * Twice in #655 a digest missed a column a state change moves - the interim
 * fingerprint the dispute stamp, the basis `is_deferred` - and a draft priced
 * before the change issued after it. So each column is either digested,
 * bounds the digest's scope, or is named here with the reason it cannot move
 * what the draft was priced from; a new column fails until it is decided. The
 * digested and scoping columns are then changed one at a time, on a row the
 * digest reads, without the lock version an application write would also
 * bump, and the digest must notice each.
 */
final class IssueDigestCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Columns neither digest reads, and why that is safe. */
    private const array IGNORED_BY_BOTH = [
        'id' => 'identity; a different id is a different row',
        'workspace_id' => 'composite tenant keys refuse a row moving workspace without its company and project, which both digests scope on',
        'public_id' => 'identity for the API',
        'user_id' => 'who logged it; no capacity input',
        'description' => 'text',
        'job_type' => 'a label',
        'client_visible_description' => 'text',
        'is_visible_to_client' => 'portal visibility; no capacity input',
        'billing_rate_amount' => 'prices flat-hourly and direct time, which neither ledger reads; retainer work is priced at the agreement rate',
        'billing_rate_source' => 'provenance of the rate above',
        'currency' => 'the rate above\'s currency',
        'approved_by_user_id' => 'who approved it; approval itself is `status`',
        'approved_at' => 'when; approval itself is `status`',
        'subcontractor_cost_amount' => 'a cost, never charged',
        'subcontractor_cost_currency' => 'a cost\'s currency',
        'subcontractor_cost_metadata' => 'a cost\'s detail',
        'created_at' => 'a timestamp',
        'updated_at' => 'a timestamp',
        'client_approved_at' => 'the client\'s approval, which bills nothing it would not bill',
        'client_approved_by_user_id' => 'who approved',
        'client_approval_source' => 'how',
        'client_disputed_by_user_id' => 'who disputed; the dispute itself is `client_disputed_at`',
        'client_dispute_note' => 'text',
        'submitted_at' => 'the submission, which bills nothing',
        'submitted_by_user_id' => 'who submitted',
        'client_dispute_reply' => 'text',
        'client_dispute_settled_at' => 'when; the outcome is `client_dispute_settlement`',
        'client_dispute_settled_by_user_id' => 'who settled',
    ];

    /**
     * The held-work basis reads only the dispute state of disputed and settled
     * rows. Their other capacity inputs move by ordinary edits, as any row's
     * do, which the basis was never asked to catch.
     */
    private const array BASIS_IGNORES = [
        'client_task_id' => 'a task link; no capacity input',
        'split_from_time_entry_id' => 'a split keeps the minutes, and the fragments\' dispute state is their own',
        'minutes' => 'an ordinary edit, not a dispute\'s; a held row\'s minutes count for nothing',
        'status' => 'an ordinary approval change, not a dispute\'s',
        'is_billable' => 'an ordinary edit, not a dispute\'s',
        'lock_version' => 'moves with every write; the basis records state, not writes',
    ];

    private const array BASIS_DIGESTS = ['client_disputed_at', 'client_dispute_settlement', 'deleted_at', 'is_deferred'];

    private const array BASIS_SCOPES = ['client_company_id', 'client_project_id', 'worked_on', 'subcontractor_billing_mode'];

    private const array INTERIM_DIGESTS = [
        'client_project_id', 'client_task_id', 'worked_on', 'minutes', 'status', 'is_billable', 'is_deferred',
        'split_from_time_entry_id', 'subcontractor_billing_mode', 'deleted_at',
        'client_disputed_at', 'client_dispute_settlement', 'lock_version',
    ];

    private const array INTERIM_SCOPES = ['client_company_id'];

    public function test_every_time_entry_column_is_decided_for_the_held_work_basis(): void
    {
        $this->assertPartition(array_merge(self::BASIS_DIGESTS, self::BASIS_SCOPES), self::BASIS_IGNORES, 'held-work basis');

        [$workspace, $company, $project, $owner] = $this->world('basis');
        $agreement = $this->agreement($workspace, $company, $project, 'monthly', false);
        $disputed = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 1200);
        ClientTimeEntry::query()->whereKey($disputed->id)->update(['client_disputed_at' => '2026-02-02 12:00:00', 'client_dispute_note' => 'Synthetic question']);
        $disputed = $disputed->fresh() ?? $disputed;
        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1800);
        $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement, Carbon::parse('2026-03-01'));
        $this->assertNotNull($draft->held_work_basis);
        $disputed = $disputed->fresh() ?? $disputed;
        $changes = $this->changes($owner, $disputed, [
            'worked_on' => '2026-06-15',
            'subcontractor_billing_mode' => 'flat_hourly',
        ]);
        $current = fn (): bool => app(HeldWorkBasisRecorder::class)->isCurrent($draft->fresh() ?? $draft);
        $this->assertTrue($current(), 'Unchanged, the basis stays current.');

        $moved = static fn (): bool => ! app(HeldWorkBasisRecorder::class)->isCurrent($draft->fresh() ?? $draft);
        $missed = $this->changesMissed($disputed, array_merge(self::BASIS_DIGESTS, self::BASIS_SCOPES), $changes, $moved);
        $this->assertSame([], $missed, 'The held-work basis no longer notices these columns change.');
    }

    public function test_every_time_entry_column_is_decided_for_the_interim_fingerprint(): void
    {
        $this->assertPartition(array_merge(self::INTERIM_DIGESTS, self::INTERIM_SCOPES), [], 'interim time fingerprint');

        [$workspace, $company, $project, $owner] = $this->world('interim');
        $agreement = $this->agreement($workspace, $company, null, 'quarterly', true);
        $entry = $this->hours($workspace, $company, $project, $owner, '2026-01-10', 900);
        $generator = app(InterimOverageGenerator::class);
        $draft = $generator->generateInterimOverageInvoice($company, Carbon::parse('2026-01-01'), $agreement);
        $this->assertInstanceOf(ClientInvoice::class, $draft);
        $fingerprint = static function () use ($generator, $draft, $agreement): string {
            $fresh = $draft->fresh() ?? $draft;

            return $generator->lockTimeFingerprint($fresh, $agreement, $generator->lockCycleClaims($fresh, $agreement)[0]);
        };
        // Generation may have split it; and the fixtures the changes need are
        // made before the baseline, so that none of them is the change seen.
        $entry = $entry->fresh() ?? $entry;
        $changes = $this->changes($owner, $entry, [
            'worked_on' => '2026-01-11',
            'subcontractor_billing_mode' => 'retainer',
        ]);
        $before = $fingerprint();
        $this->assertSame($before, $fingerprint(), 'Unchanged, the fingerprint is stable.');

        $missed = $this->changesMissed($entry, array_merge(self::INTERIM_DIGESTS, self::INTERIM_SCOPES), $changes, static fn (): bool => $fingerprint() !== $before);
        $this->assertSame([], $missed, 'The interim time fingerprint no longer notices these columns change.');
    }

    /**
     * @param  list<string>  $read
     * @param  array<string, string>  $ignored  column => reason
     */
    private function assertPartition(array $read, array $ignored, string $digest): void
    {
        $decided = array_merge($read, array_keys($ignored), array_keys(array_diff_key(self::IGNORED_BY_BOTH, array_flip($read))));
        $columns = Schema::getColumnListing('client_time_entries');
        sort($columns);
        $sorted = array_values(array_unique($decided));
        sort($sorted);
        $this->assertSame(
            [],
            array_values(array_diff($columns, $sorted)),
            "A time-entry column is undecided for the {$digest}: digest it, or name it with the reason it cannot move what a draft was priced from.",
        );
        $this->assertSame([], array_values(array_diff($sorted, $columns)), "A decided column no longer exists for the {$digest}.");
        $this->assertSame([], array_values(array_intersect($read, array_keys($ignored))), "A column is both read and ignored by the {$digest}.");
    }

    /**
     * A different value for each column, valid for its type and constraints;
     * an array is a change of several columns the keys only allow together.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, Closure(): mixed>
     */
    private function changes(User $owner, ClientTimeEntry $entry, array $overrides): array
    {
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $entry->workspace_id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client-'.$entry->id]);
        $otherProject = ClientProject::query()->create(['workspace_id' => $entry->workspace_id, 'client_company_id' => $entry->client_company_id, 'name' => 'Synthetic other project']);
        $otherCompanyProject = ClientProject::query()->create(['workspace_id' => $entry->workspace_id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other client project']);
        $task = ClientTask::query()->create(['workspace_id' => $entry->workspace_id, 'client_project_id' => $entry->client_project_id, 'title' => 'Synthetic task', 'status' => 'open']);
        $sibling = $this->hours(Workspace::query()->findOrFail($entry->workspace_id), ClientCompany::query()->findOrFail($entry->client_company_id), ClientProject::query()->findOrFail($entry->client_project_id), $owner, '2026-01-02', 1);
        $flip = static fn (string $column): Closure => static fn (): mixed => $entry->getRawOriginal($column) === null ? '2026-02-20 12:00:00' : null;

        return array_map(static fn (mixed $value): Closure => $value instanceof Closure ? $value : static fn (): mixed => $value, $overrides + [
            // With a project of its own: the project's company must match.
            'client_company_id' => ['client_company_id' => $otherCompany->id, 'client_project_id' => $otherCompanyProject->id],
            'client_project_id' => $otherProject->id,
            'client_task_id' => $task->id,
            'split_from_time_entry_id' => $sibling->id,
            'minutes' => (int) $entry->minutes + 1,
            'status' => 'invoiced',
            'is_billable' => ! $entry->is_billable,
            'is_deferred' => ! $entry->is_deferred,
            'lock_version' => (int) $entry->getRawOriginal('lock_version') + 1,
            'deleted_at' => $flip('deleted_at'),
            'client_disputed_at' => $flip('client_disputed_at'),
            'client_dispute_settlement' => static fn (): ?string => $entry->getRawOriginal('client_dispute_settlement') === null ? 'answered' : null,
        ]);
    }

    /**
     * Change each column alone, inside a savepoint, and say which the digest
     * did not notice.
     *
     * @param  list<string>  $columns
     * @param  array<string, Closure(): mixed>  $changes
     * @param  Closure(): bool  $noticed
     * @return list<string>
     */
    private function changesMissed(ClientTimeEntry $entry, array $columns, array $changes, Closure $noticed): array
    {
        $missed = [];
        foreach ($columns as $column) {
            $this->assertArrayHasKey($column, $changes, "No change is defined for {$column}.");
            DB::beginTransaction();
            try {
                $change = $changes[$column]();
                DB::table('client_time_entries')->where('id', $entry->id)->update(is_array($change) ? $change : [$column => $change]);
                if (! $noticed()) {
                    $missed[] = $column;
                }
            } finally {
                DB::rollBack();
            }
        }

        return $missed;
    }

    /** @return array{Workspace, ClientCompany, ClientProject, User} */
    private function world(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic digest '.$name, 'slug' => 'synthetic-digest-'.$name]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic digest client', 'slug' => 'synthetic-digest-client-'.$name]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic digest project']);

        return [$workspace, $company, $project, $owner];
    }

    private function agreement(Workspace $workspace, ClientCompany $company, ?ClientProject $project, string $cadence, bool $interim): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project?->id,
            'title' => 'Synthetic agreement', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => $cadence,
            'currency' => 'USD', 'hourly_rate_amount' => 10000, 'retainer_minutes' => 600, 'retainer_amount' => 60000,
            'rollover_months' => 0, 'catch_up_threshold_minutes' => 0, 'bill_overage_interim' => $interim,
        ]);
    }

    private function hours(Workspace $workspace, ClientCompany $company, ClientProject $project, User $author, string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }
}
