<?php

namespace App\Services\Approval;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Queries\Approval\PortalApprovalQueue;
use App\Services\Authorization\PortalAccess;
use App\Support\Approval\ApprovalPeriod;
use App\Support\Approval\ClientApprovalSource;
use App\Support\Approval\SelfApproval;
use App\Support\Concurrency\Locks;
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
        $explicit = $timeIds !== [] || $expenseIds !== [];
        $period = $periodKey === null ? null : ApprovalPeriod::fromKey($periodKey);
        abort_if(! $explicit && $period === null, 422, 'Name a month, or the rows to approve.');
        abort_if($periodKey !== null && $period === null, 422, 'A month is written as YYYY-MM.');

        $scope = $this->access->visibleProjectIds($company, $viewer);

        return DB::transaction(function () use ($company, $viewer, $period, $timeIds, $expenseIds, $explicit, $scope): array {
            $time = $this->resolveTime($company, $scope, $period, $timeIds, $explicit);
            $expenses = $this->resolveExpenses($company, $scope, $period, $expenseIds, $explicit);

            $ownTime = $time->filter(fn (ClientTimeEntry $row): bool => SelfApproval::refuses($row->user_id, $viewer->id));
            $ownExpenses = $expenses->filter(fn (ClientExpense $row): bool => SelfApproval::refuses($row->created_by_user_id, $viewer->id));
            if ($explicit) {
                abort_if($ownTime->isNotEmpty(), 409, SelfApproval::TIME_MESSAGE);
                abort_if($ownExpenses->isNotEmpty(), 409, SelfApproval::EXPENSE_MESSAGE);
            }

            $stamp = [
                'client_approved_at' => $this->clock->now($company->workspace),
                'client_approved_by_user_id' => $viewer->id,
                'client_approval_source' => ClientApprovalSource::Portal->value,
                'client_disputed_at' => null,
                'client_disputed_by_user_id' => null,
                'client_dispute_note' => null,
                'lock_version' => DB::raw('lock_version + 1'),
            ];

            return [
                'time' => $this->write(ClientTimeEntry::query(), $company, $time->reject(fn (ClientTimeEntry $row): bool => SelfApproval::refuses($row->user_id, $viewer->id)), $stamp),
                'expenses' => $this->write(ClientExpense::query(), $company, $expenses->reject(fn (ClientExpense $row): bool => SelfApproval::refuses($row->created_by_user_id, $viewer->id)), $stamp),
                'skipped_own' => $ownTime->count() + $ownExpenses->count(),
            ];
        });
    }

    /**
     * Dispute one row, with or without a note.
     *
     * @param  'time'|'expense'  $kind
     */
    public function dispute(ClientCompany $company, User $viewer, string $kind, string $id, ?string $note): void
    {
        abort_unless($this->authority->may($company, $viewer), 403, 'You do not approve hours and expenses for this client.');
        $scope = $this->access->visibleProjectIds($company, $viewer);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        DB::transaction(function () use ($company, $viewer, $kind, $id, $scope, $note): void {
            $time = $kind === 'time' ? $this->resolveTime($company, $scope, null, [$id], true) : collect();
            $expenses = $kind === 'expense' ? $this->resolveExpenses($company, $scope, null, [$id], true) : collect();

            abort_if($time->contains(fn (ClientTimeEntry $row): bool => SelfApproval::refuses($row->user_id, $viewer->id)), 409, SelfApproval::TIME_MESSAGE);
            abort_if($expenses->contains(fn (ClientExpense $row): bool => SelfApproval::refuses($row->created_by_user_id, $viewer->id)), 409, SelfApproval::EXPENSE_MESSAGE);

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
        });
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
