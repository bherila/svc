<?php

namespace App\Services\Submission;

use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Submission\SubmissionRules;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Submitting hours and expenses for review, and taking a submission back
 * (docs/ux-overhaul.md sections 7b and 7e).
 *
 * The author acts on their own rows and nobody else's: every read names the
 * workspace and `user_id` (or `created_by_user_id`), so another person's row,
 * or another tenant's, is simply not found. Submitting changes nothing but the
 * submission stamps - never a status, never an approval - and the only person
 * who can move a submitted row on is whoever the provider asked to review it.
 *
 * No lock is taken. Each write is one conditional `UPDATE` that repeats the
 * rule's facts in its `WHERE`, so a concurrent approval, edit or withdrawal
 * makes it touch fewer rows than expected and the whole call rolls back as a
 * 409. That keeps it out of the billing lock order entirely; the approval path
 * reads the same rows under its own lock and sees whichever write came first.
 *
 * Two shapes, as with approving: name the rows and every one must be
 * submittable, or name a calendar month and what can be submitted is, with the
 * rest left alone.
 */
final class SubmissionService
{
    /** The most rows one call moves; a month with more than this is not a case the screen is for. */
    public const int LIMIT = 500;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  list<string>  $timeIds  public ids; with $expenseIds, exactly these rows
     * @param  list<string>  $expenseIds
     * @param  bool  $includeExpenses  false leaves expenses alone, for a caller who may not touch them
     * @return list<array{kind: 'time'|'expense', id: string}> the rows now submitted
     */
    public function submit(Workspace $workspace, User $actor, ?string $periodKey, ?string $projectPublicId, array $timeIds = [], array $expenseIds = [], bool $includeExpenses = true): array
    {
        [$explicit, $period] = $this->shape($periodKey, $timeIds, $expenseIds);

        return DB::transaction(function () use ($workspace, $actor, $explicit, $period, $projectPublicId, $timeIds, $expenseIds, $includeExpenses): array {
            $time = $this->timeRows($workspace, $actor, $period, $projectPublicId, $timeIds, $explicit);
            $expenses = $includeExpenses ? $this->expenseRows($workspace, $actor, $period, $projectPublicId, $expenseIds, $explicit) : new Collection;

            $eligibleTime = $time->filter(function (ClientTimeEntry $row) use ($actor, $explicit): bool {
                $refusal = SubmissionRules::timeRefusal(
                    $row->user_id, $actor->id, $row->status, $row->submitted_at !== null,
                    $row->is_billable, $row->is_visible_to_client && trim((string) $row->client_visible_description) !== '', $row->subcontractor_billing_mode,
                );
                abort_if($explicit && $refusal !== null, 409, $refusal?->message());

                return $refusal === null;
            });
            $eligibleExpenses = $expenses->filter(function (ClientExpense $row) use ($actor, $explicit): bool {
                $refusal = SubmissionRules::expenseRefusal($row->created_by_user_id, $actor->id, $row->status, $row->submitted_at !== null);
                abort_if($explicit && $refusal !== null, 409, $refusal?->message());

                return $refusal === null;
            });

            $now = $this->clock->instant();
            $stamp = ['submitted_at' => $now, 'submitted_by_user_id' => $actor->id, 'lock_version' => DB::raw('lock_version + 1')];
            // Submitting again after a reviewer sent a row back is the author's
            // answer to that dispute, so the dispute is cleared with it.
            $cleared = ['client_disputed_at' => null, 'client_disputed_by_user_id' => null, 'client_dispute_note' => null];

            $this->conditional(
                ClientTimeEntry::query()->where('user_id', $actor->id)->where('status', SubmissionRules::DRAFT),
                $workspace, $eligibleTime, $stamp + $cleared,
            );
            $this->conditional(
                ClientExpense::query()->where('created_by_user_id', $actor->id)->where('status', SubmissionRules::DRAFT),
                $workspace, $eligibleExpenses, $stamp + $cleared,
            );

            return $this->handles($eligibleTime, $eligibleExpenses);
        });
    }

    /**
     * @param  list<string>  $timeIds
     * @param  list<string>  $expenseIds
     * @return list<array{kind: 'time'|'expense', id: string}> the rows no longer submitted
     */
    public function withdraw(Workspace $workspace, User $actor, ?string $periodKey, ?string $projectPublicId, array $timeIds = [], array $expenseIds = [], bool $includeExpenses = true): array
    {
        [$explicit, $period] = $this->shape($periodKey, $timeIds, $expenseIds);

        return DB::transaction(function () use ($workspace, $actor, $explicit, $period, $projectPublicId, $timeIds, $expenseIds, $includeExpenses): array {
            $time = $this->timeRows($workspace, $actor, $period, $projectPublicId, $timeIds, $explicit);
            $expenses = $includeExpenses ? $this->expenseRows($workspace, $actor, $period, $projectPublicId, $expenseIds, $explicit) : new Collection;

            $eligibleTime = $time->filter(function (ClientTimeEntry $row) use ($actor, $explicit): bool {
                $refusal = SubmissionRules::withdrawRefusal($row->user_id, $actor->id, $row->status, $row->submitted_at !== null, $row->client_approved_at !== null);
                abort_if($explicit && $refusal !== null, 409, $refusal?->message());

                return $refusal === null;
            });
            $eligibleExpenses = $expenses->filter(function (ClientExpense $row) use ($actor, $explicit): bool {
                $refusal = SubmissionRules::withdrawRefusal($row->created_by_user_id, $actor->id, $row->status, $row->submitted_at !== null, $row->client_approved_at !== null);
                abort_if($explicit && $refusal !== null, 409, $refusal?->message());

                return $refusal === null;
            });

            $clear = ['submitted_at' => null, 'submitted_by_user_id' => null, 'lock_version' => DB::raw('lock_version + 1')];
            // Still unapproved, still draft and still submitted: a decision that
            // landed first leaves the row untouched, and the count tells us.
            $this->conditional(
                ClientTimeEntry::query()->where('user_id', $actor->id)->where('status', SubmissionRules::DRAFT)->whereNotNull('submitted_at')->whereNull('client_approved_at'),
                $workspace, $eligibleTime, $clear,
            );
            $this->conditional(
                ClientExpense::query()->where('created_by_user_id', $actor->id)->where('status', SubmissionRules::DRAFT)->whereNotNull('submitted_at')->whereNull('client_approved_at'),
                $workspace, $eligibleExpenses, $clear,
            );

            return $this->handles($eligibleTime, $eligibleExpenses);
        });
    }

    /**
     * @param  list<string>  $timeIds
     * @param  list<string>  $expenseIds
     * @return array{bool, ApprovalPeriod|null}
     */
    private function shape(?string $periodKey, array $timeIds, array $expenseIds): array
    {
        $explicit = $timeIds !== [] || $expenseIds !== [];
        $period = $periodKey === null ? null : ApprovalPeriod::fromKey($periodKey);
        abort_if(! $explicit && $period === null, 422, 'Name a month, or the rows to submit.');
        abort_if($periodKey !== null && $period === null, 422, 'A month is written as YYYY-MM.');

        return [$explicit, $explicit ? null : $period];
    }

    /**
     * The actor's own time, in projects they can see.
     *
     * @param  list<string>  $ids
     * @return Collection<int, ClientTimeEntry>
     */
    private function timeRows(Workspace $workspace, User $actor, ?ApprovalPeriod $period, ?string $projectPublicId, array $ids, bool $explicit): Collection
    {
        if ($explicit && $ids === []) {
            return new Collection;
        }

        $rows = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->tap(fn (Builder $query) => $this->scoped($query, $workspace, $actor, $projectPublicId))
            ->when($explicit, fn (Builder $query) => $query->whereIn('public_id', $ids))
            ->when(! $explicit && $period instanceof ApprovalPeriod, fn (Builder $query) => $query->whereBetween('worked_on', [$period?->start(), $period?->end()]))
            ->orderBy('worked_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        abort_if($rows->count() > self::LIMIT, 422, 'That is more than '.self::LIMIT.' rows. Submit a shorter span.');
        abort_if($explicit && $rows->count() !== count(array_unique($ids)), 404);

        return $rows;
    }

    /**
     * The actor's own expenses, in projects they can see.
     *
     * @param  list<string>  $ids
     * @return Collection<int, ClientExpense>
     */
    private function expenseRows(Workspace $workspace, User $actor, ?ApprovalPeriod $period, ?string $projectPublicId, array $ids, bool $explicit): Collection
    {
        if ($explicit && $ids === []) {
            return new Collection;
        }

        $rows = ClientExpense::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_by_user_id', $actor->id)
            ->tap(fn (Builder $query) => $this->scoped($query, $workspace, $actor, $projectPublicId))
            ->when($explicit, fn (Builder $query) => $query->whereIn('public_id', $ids))
            ->when(! $explicit && $period instanceof ApprovalPeriod, fn (Builder $query) => $query->whereBetween('spent_on', [$period?->start(), $period?->end()]))
            ->orderBy('spent_on')->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();

        abort_if($rows->count() > self::LIMIT, 422, 'That is more than '.self::LIMIT.' rows. Submit a shorter span.');
        abort_if($explicit && $rows->count() !== count(array_unique($ids)), 404);

        return $rows;
    }

    /**
     * Narrow to the projects this person can see, and to one project if named.
     * A row with no project (an expense) is outside any project scope a member
     * holds, and inside an owner's or admin's.
     *
     * @param  Builder<ClientTimeEntry>|Builder<ClientExpense>  $query
     */
    private function scoped(Builder $query, Workspace $workspace, User $actor, ?string $projectPublicId): void
    {
        $viewable = $this->access->viewableProjectIds($actor, $workspace);
        if ($viewable !== null) {
            $query->whereIn('client_project_id', $viewable);
        }
        if ($projectPublicId !== null) {
            $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectPublicId)->first();
            abort_if($project === null, 404);
            $query->where('client_project_id', $project->id);
        }
    }

    /**
     * One conditional write for the rows that passed the rule, rolled back
     * (409) if any of them changed since they were read.
     *
     * @param  Builder<ClientTimeEntry>|Builder<ClientExpense>  $guard  the facts the rule relied on, again
     * @param  Collection<int, ClientTimeEntry>|Collection<int, ClientExpense>  $rows
     * @param  array<string, mixed>  $values
     */
    private function conditional(Builder $guard, Workspace $workspace, Collection $rows, array $values): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $changed = $guard
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $rows->pluck('id')->all())
            ->update($values);

        abort_unless($changed === $rows->count(), 409, 'Some of those rows changed while you were working. Reload and try again.');
    }

    /**
     * @param  Collection<int, ClientTimeEntry>  $time
     * @param  Collection<int, ClientExpense>  $expenses
     * @return list<array{kind: 'time'|'expense', id: string}>
     */
    private function handles(Collection $time, Collection $expenses): array
    {
        $handles = [];
        foreach ($time as $row) {
            $handles[] = ['kind' => 'time', 'id' => $row->public_id];
        }
        foreach ($expenses as $row) {
            $handles[] = ['kind' => 'expense', 'id' => $row->public_id];
        }

        return $handles;
    }
}
