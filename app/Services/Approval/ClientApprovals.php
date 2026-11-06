<?php

namespace App\Services\Approval;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientTimeEntry;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Queries\Approval\PortalApprovalQueue;
use App\Services\Access\AccessAuditLog;
use App\Services\Authorization\PortalAccess;
use App\Services\Review\ReviewerGrants;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Approval\ClientApprovalSource;
use App\Support\Approval\SelfApproval;
use App\Support\Concurrency\Locks;
use App\Support\Submission\SubmissionRules;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client contact's own decisions on hours and expenses: approve a month, a
 * handful of rows, or one row; or dispute a row with a note
 * (docs/ux-overhaul.md sections 7b, 7b' and 7c).
 *
 * Every target is found through {@see PortalApprovalQueue}, the same rows the
 * screen shows, so nothing is approved that was not on offer to this person in
 * this workspace. Cross-tenant ids simply do not resolve.
 *
 * Nobody approves their own work. A row the deciding person authored - a
 * workspace member who is also the client's contact - is refused outright when
 * they name it, and left alone when a whole month is approved at once; the
 * count of those left is returned so the screen can say so.
 *
 * A dispute is a statement and an approval is a statement; each clears the
 * other, so a row is never both. Disputing does not touch the invoice: the
 * operator's way to act on an issued one is the correction flow.
 */
final class ClientApprovals
{
    public function __construct(
        private readonly PortalApprovalAuthority $authority,
        private readonly PortalApprovalQueue $queue,
        private readonly PortalAccess $access,
        private readonly ReviewerGrants $grants,
        private readonly AccessAuditLog $audit,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Approve what is named, or the whole month when only a month is named.
     *
     * @param  list<string>  $timeIds  public ids; with $expenseIds, exactly these rows
     * @param  list<string>  $expenseIds
     * @return array{time: int, expenses: int, skipped_own: int}
     */
    public function approve(ClientCompany $company, User $viewer, ?string $periodKey, array $timeIds = [], array $expenseIds = []): array
    {
        abort_unless($this->authority->may($company, $viewer), 403, 'You do not approve hours and expenses for this client.');

        return $this->decide($company, $viewer, $this->access->visibleProjectIds($company, $viewer), ClientApprovalSource::Portal, null, $periodKey, $timeIds, $expenseIds);
    }

    /**
     * The same approval, made through a reviewer grant instead of a portal
     * membership. The rows are the grant's client's, in the granting workspace,
     * narrowed to its project; the approval is recorded there, against those
     * rows, with the reviewer as the person who approved. A grant that does not
     * reach this person - never given, revoked, or not theirs - is a 404.
     *
     * @param  list<string>  $timeIds
     * @param  list<string>  $expenseIds
     * @return array{time: int, expenses: int, skipped_own: int}
     */
    public function approveThroughGrant(User $viewer, string $grantPublicId, ?string $periodKey, array $timeIds = [], array $expenseIds = []): array
    {
        $grant = $this->reachingGrant($viewer, $grantPublicId);

        return $this->decide($this->companyOf($grant), $viewer, $grant->projectScope(), ClientApprovalSource::Reviewer, $grant, $periodKey, $timeIds, $expenseIds);
    }

    /**
     * Dispute one row, with or without a note.
     *
     * @param  'time'|'expense'  $kind
     */
    public function dispute(ClientCompany $company, User $viewer, string $kind, string $id, ?string $note): void
    {
        abort_unless($this->authority->may($company, $viewer), 403, 'You do not approve hours and expenses for this client.');

        $this->dispose($company, $viewer, $this->access->visibleProjectIds($company, $viewer), null, $kind, $id, $note);
    }

    /**
     * Dispute one row through a reviewer grant.
     *
     * @param  'time'|'expense'  $kind
     */
    public function disputeThroughGrant(User $viewer, string $grantPublicId, string $kind, string $id, ?string $note): void
    {
        $grant = $this->reachingGrant($viewer, $grantPublicId);

        $this->dispose($this->companyOf($grant), $viewer, $grant->projectScope(), $grant, $kind, $id, $note);
    }

    private function reachingGrant(User $viewer, string $grantPublicId): ReviewerGrant
    {
        $grant = $this->grants->reachingOne($viewer, $grantPublicId);
        abort_if($grant === null, 404);

        return $grant;
    }

    private function companyOf(ReviewerGrant $grant): ClientCompany
    {
        $company = $grant->company;
        abort_if($company === null || $grant->granterWorkspace === null, 404);
        // The granting workspace is the client's; no second lookup is needed to stamp its clock.
        $company->setRelation('workspace', $grant->granterWorkspace);

        return $company;
    }

    /**
     * @param  list<int>|null  $scope
     * @param  list<string>  $timeIds
     * @param  list<string>  $expenseIds
     * @return array{time: int, expenses: int, skipped_own: int}
     */
    private function decide(ClientCompany $company, User $viewer, ?array $scope, ClientApprovalSource $source, ?ReviewerGrant $grant, ?string $periodKey, array $timeIds, array $expenseIds): array
    {
        $explicit = $timeIds !== [] || $expenseIds !== [];
        $period = $periodKey === null ? null : ApprovalPeriod::fromKey($periodKey);
        abort_if(! $explicit && $period === null, 422, 'Name a month, or the rows to approve.');
        abort_if($periodKey !== null && $period === null, 422, 'A month is written as YYYY-MM.');

        return DB::transaction(function () use ($company, $viewer, $period, $timeIds, $expenseIds, $explicit, $scope, $source, $grant): array {
            // The grant is read again as the first statement of the transaction,
            // so a revocation that committed before this one began always wins.
            $this->stillReaches($viewer, $grant);
            $time = $this->resolveTime($company, $scope, $period, $timeIds, $explicit);
            $expenses = $this->resolveExpenses($company, $scope, $period, $expenseIds, $explicit);

            $staff = $this->isStaffOfSubmitted($company, $viewer, $time, $expenses);
            $ownTime = $time->filter(fn (ClientTimeEntry $row): bool => $this->refused($row->user_id, $row->status, $viewer, $staff));
            $ownExpenses = $expenses->filter(fn (ClientExpense $row): bool => $this->refused($row->created_by_user_id, $row->status, $viewer, $staff));
            if ($explicit) {
                abort_if($ownTime->isNotEmpty(), 409, $this->refusalMessage(SelfApproval::TIME_MESSAGE, $ownTime->contains(fn (ClientTimeEntry $row): bool => SelfApproval::refuses($row->user_id, $viewer->id))));
                abort_if($ownExpenses->isNotEmpty(), 409, $this->refusalMessage(SelfApproval::EXPENSE_MESSAGE, $ownExpenses->contains(fn (ClientExpense $row): bool => SelfApproval::refuses($row->created_by_user_id, $viewer->id))));
            }

            $stamp = [
                'client_approved_at' => $this->clock->now($company->workspace),
                'client_approved_by_user_id' => $viewer->id,
                'client_approval_source' => $source->value,
                'client_disputed_at' => null,
                'client_disputed_by_user_id' => null,
                'client_dispute_note' => null,
                'lock_version' => DB::raw('lock_version + 1'),
            ];

            $result = [
                'time' => $this->write(ClientTimeEntry::query(), $company, $time->reject(fn (ClientTimeEntry $row): bool => $this->refused($row->user_id, $row->status, $viewer, $staff)), $stamp),
                'expenses' => $this->write(ClientExpense::query(), $company, $expenses->reject(fn (ClientExpense $row): bool => $this->refused($row->created_by_user_id, $row->status, $viewer, $staff)), $stamp),
                'skipped_own' => $ownTime->count() + $ownExpenses->count(),
            ];
            $this->record($grant, $viewer, $company, AccessAuditAction::ReviewerApproved, $result);

            return $result;
        });
    }

    /**
     * @param  list<int>|null  $scope
     * @param  'time'|'expense'  $kind
     */
    private function dispose(ClientCompany $company, User $viewer, ?array $scope, ?ReviewerGrant $grant, string $kind, string $id, ?string $note): void
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        DB::transaction(function () use ($company, $viewer, $kind, $id, $scope, $note, $grant): void {
            $this->stillReaches($viewer, $grant);
            $time = $kind === 'time' ? $this->resolveTime($company, $scope, null, [$id], true) : collect();
            $expenses = $kind === 'expense' ? $this->resolveExpenses($company, $scope, null, [$id], true) : collect();

            $staff = $this->isStaffOfSubmitted($company, $viewer, $time, $expenses);
            abort_if($time->contains(fn (ClientTimeEntry $row): bool => $this->refused($row->user_id, $row->status, $viewer, $staff)), 409,
                $this->refusalMessage(SelfApproval::TIME_MESSAGE, $time->contains(fn (ClientTimeEntry $row): bool => SelfApproval::refuses($row->user_id, $viewer->id))));
            abort_if($expenses->contains(fn (ClientExpense $row): bool => $this->refused($row->created_by_user_id, $row->status, $viewer, $staff)), 409,
                $this->refusalMessage(SelfApproval::EXPENSE_MESSAGE, $expenses->contains(fn (ClientExpense $row): bool => SelfApproval::refuses($row->created_by_user_id, $viewer->id))));

            $stamp = [
                'client_disputed_at' => $this->clock->now($company->workspace),
                'client_disputed_by_user_id' => $viewer->id,
                'client_dispute_note' => $note,
                'client_approved_at' => null,
                'client_approved_by_user_id' => null,
                'client_approval_source' => null,
                'lock_version' => DB::raw('lock_version + 1'),
            ];
            $this->write(ClientTimeEntry::query(), $company, $time, $stamp);
            $this->write(ClientExpense::query(), $company, $expenses, $stamp);
            $this->record($grant, $viewer, $company, AccessAuditAction::ReviewerDisputed, ['kind' => $kind]);
        });
    }

    /**
     * Is the row off limits to this person: their own work, or a submission
     * their own workspace made? Nobody approves their own work, and the
     * provider's staff do not vouch for what the provider submitted.
     */
    private function refused(?int $authorId, string $status, User $viewer, bool $viewerIsStaff): bool
    {
        return SelfApproval::refuses($authorId, $viewer->id) || ! SubmissionRules::mayDecide($status, $viewerIsStaff);
    }

    private function refusalMessage(string $own, bool $isOwn): string
    {
        return $isOwn ? $own : SubmissionRules::STAFF_MESSAGE;
    }

    /**
     * Does the viewer belong to the provider's workspace, asked only when a
     * draft (a submission) is among the rows. One workspace-scoped read, no lock.
     *
     * @param  Collection<int, ClientTimeEntry>  $time
     * @param  Collection<int, ClientExpense>  $expenses
     */
    private function isStaffOfSubmitted(ClientCompany $company, User $viewer, Collection $time, Collection $expenses): bool
    {
        $drafts = $time->contains(fn (ClientTimeEntry $row): bool => $row->status === SubmissionRules::DRAFT)
            || $expenses->contains(fn (ClientExpense $row): bool => $row->status === SubmissionRules::DRAFT);

        return $drafts && WorkspaceMembership::query()->where('workspace_id', $company->workspace_id)->where('user_id', $viewer->id)->exists();
    }

    private function stillReaches(User $viewer, ?ReviewerGrant $grant): void
    {
        abort_if($grant !== null && $this->grants->reachingOne($viewer, $grant->public_id) === null, 404);
    }

    /**
     * What a grant did, written in the granting workspace in the same
     * transaction as the decision. A portal decision (no grant) writes nothing
     * here, as before.
     *
     * @param  array<string, mixed>  $outcome  counts and kinds only
     */
    private function record(?ReviewerGrant $grant, User $viewer, ClientCompany $company, AccessAuditAction $action, array $outcome): void
    {
        if ($grant === null) {
            return;
        }

        $this->audit->record(AccessMutationContext::user($viewer), $company->workspace, $action, $viewer, null, ['grant' => $grant->public_id] + $outcome, $company->public_id);
    }

    /**
     * The rows this decision is about, locked, and only those still waiting.
     *
     * @param  list<int>|null  $scope
     * @param  list<string>  $ids
     * @return Collection<int, ClientTimeEntry>
     */
    private function resolveTime(ClientCompany $company, ?array $scope, ?ApprovalPeriod $period, array $ids, bool $explicit): Collection
    {
        if ($explicit && $ids === []) {
            return collect();
        }

        $rows = $this->queue->timeQuery($company, $scope)
            ->when($explicit, fn ($query) => $query->whereIn('public_id', $ids))
            ->when(! $explicit && $period instanceof ApprovalPeriod, fn ($query) => $query->whereBetween('worked_on', [$period?->start(), $period?->end()]))
            ->with('invoiceLines.invoice')
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get()
            ->filter(fn (ClientTimeEntry $row): bool => $this->queue->verdictOfTime($row)->awaitsClient())
            ->values();

        // A named row that is not on offer is a stale screen, not a no-op.
        abort_if($explicit && $rows->count() !== count(array_unique($ids)), 409, 'Some of those rows are no longer waiting for your approval. Reload and try again.');

        return $rows;
    }

    /**
     * @param  list<int>|null  $scope
     * @param  list<string>  $ids
     * @return Collection<int, ClientExpense>
     */
    private function resolveExpenses(ClientCompany $company, ?array $scope, ?ApprovalPeriod $period, array $ids, bool $explicit): Collection
    {
        if ($explicit && $ids === []) {
            return collect();
        }

        $rows = $this->queue->expenseQuery($company, $scope)
            ->when($explicit, fn ($query) => $query->whereIn('public_id', $ids))
            ->when(! $explicit && $period instanceof ApprovalPeriod, fn ($query) => $query->whereBetween('spent_on', [$period?->start(), $period?->end()]))
            ->with('invoiceLine.invoice')
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get()
            ->filter(fn (ClientExpense $row): bool => $this->queue->verdictOfExpense($row)->awaitsClient())
            ->values();

        abort_if($explicit && $rows->count() !== count(array_unique($ids)), 409, 'Some of those rows are no longer waiting for your approval. Reload and try again.');

        return $rows;
    }

    /**
     * @param  Builder<ClientTimeEntry>|Builder<ClientExpense>  $query
     * @param  Collection<int, ClientTimeEntry>|Collection<int, ClientExpense>  $rows
     * @param  array<string, mixed>  $stamp
     */
    private function write($query, ClientCompany $company, Collection $rows, array $stamp): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        return $query
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->whereIn('id', $rows->pluck('id')->all())
            ->update($stamp);
    }
}
