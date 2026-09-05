<?php

namespace App\Queries\Expenses;

use App\Exceptions\CrossTenantReference;
use App\Exceptions\ExpenseTransitionRefused;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\Expenses\ExpenseStatus;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Every read and write of `client_expenses`, scoped to one workspace.
 *
 * The point of the type is that the workspace is supplied once, at
 * construction, and nothing that follows can widen it. Expenses are money owed
 * back to whoever paid it and they carry a client's receipts, so the surfaces
 * that will read them - a manager list, an approval screen, the invoicing hook
 * in #75's third slice - should be reading through a scope none of them wrote.
 *
 * That is not a stylistic preference. The single most-repeated finding across
 * this port was a tenant-owned row reached on its parent's authority, fixed nine
 * times in nine places. `docs/client-management/tenant-foreign-keys.md` records
 * the schema half of the answer; this is the application half for this table,
 * arriving with it rather than after the third surface has already gone its own
 * way.
 *
 * ## Every transition re-reads under its own lock
 *
 * The lifecycle moves live here too, and each one takes `FOR UPDATE` on the row
 * and then decides from the *locked* copy - never from the model the caller
 * handed in. That model was read before the request that carries it, and two
 * managers looking at the same expense list are exactly the case: both hold a
 * `draft`, both press approve, and a guard that trusted its argument would let
 * the second write silently replace the first approver and timestamp. Deciding
 * under the lock turns that into a refusal the loser can see.
 *
 * The locks go through {@see Locks::forUpdate()} so the acquisition order is
 * recorded rather than assumed; `docs/client-management/concurrency.md` carries
 * the registry and the check-then-act inventory these moves are listed in.
 *
 * Still absent: the `approved` -> `invoiced` move, which belongs to the
 * invoicing hook in #75's third slice, and the claim/release rules that go with
 * regenerating a draft invoice. {@see ExpenseStatus} knows that edge is legal;
 * nothing here makes it.
 */
final class WorkspaceExpenses
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Expenses in this workspace, and nothing else.
     *
     * @return Builder<ClientExpense>
     */
    public function query(): Builder
    {
        return ClientExpense::query()->where('workspace_id', $this->workspace->id);
    }

    /**
     * One expense by its public id, or null.
     *
     * Null covers both "no such expense" and "that expense is another
     * tenant's", because a caller must not be able to tell the two apart: a
     * boundary that answers differently for a row it refuses to show has
     * confirmed the row exists.
     */
    public function find(string $publicId): ?ClientExpense
    {
        return $this->query()->where('public_id', $publicId)->first();
    }

    /**
     * Record a draft expense against a company, optionally attributed to one of
     * its projects.
     *
     * The company and the project are checked against this workspace here, and
     * the project against the company, before anything is written. The database
     * refuses a cross-tenant company on its own - `cex_ws_company_fk` - but it
     * cannot refuse a cross-tenant project: that column is nullable with an
     * `ON DELETE SET NULL` rule, which InnoDB will not carry inside a composite
     * key over the NOT NULL `workspace_id` (errno 1830). So the project check is
     * this method's to make, and the exemption is registered in
     * `App\Support\Tenancy\TenantReferenceInventory` so the audit command keeps
     * counting it.
     *
     * The company check is not redundant beside its key either. It names the
     * mistake where it was made instead of surfacing a driver-level constraint
     * error from three layers down, and it still holds on a database migrated
     * from before those keys existed.
     *
     * Expenses start as drafts. Passing an approved one in is not possible:
     * {@see NewExpense} carries no status.
     */
    public function record(
        ClientCompany $company,
        ?ClientProject $project,
        NewExpense $expense,
        ?User $recordedBy = null,
    ): ClientExpense {
        if ($company->workspace_id !== $this->workspace->id) {
            throw new CrossTenantReference('That client company belongs to another workspace.');
        }

        if ($project !== null && $project->workspace_id !== $this->workspace->id) {
            throw new CrossTenantReference('That project belongs to another workspace.');
        }

        if ($project !== null && $project->client_company_id !== $company->id) {
            throw new CrossTenantReference('That project belongs to another client company.');
        }

        return ClientExpense::query()->create([
            ...$expense->attributes(),
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project?->id,
            'created_by_user_id' => $recordedBy?->id,
            'status' => ExpenseStatus::Draft->value,
        ]);
    }

    /**
     * Rewrite the facts of a draft expense.
     *
     * Only a draft. Once a manager has passed an expense, the amount they
     * passed is the amount the client is billed, so editing it afterwards
     * changes what was approved without anyone approving it again. An approved
     * expense that turns out to be wrong goes back through
     * {@see unapprove()} first, which is a move somebody has to make on
     * purpose and which the row records.
     *
     * The facts arrive as a whole {@see NewExpense} rather than as a patch of
     * changed columns, so an edit is checked by the same constructor a new
     * expense is - a partial update cannot smuggle past a rule that only runs
     * on the fields it happens to carry.
     *
     * Attribution is separate from the facts and opt-in. `NewExpense` carries
     * no tenant reference by design, so the project is checked here the way
     * {@see record()} checks it; and `$reattribute` is what distinguishes
     * "leave it alone" from "clear it", which a null project cannot say on its
     * own. Without that distinction an edit to the money alone would silently
     * detach the expense from its project - a form reporting a saved
     * attribution the write discarded.
     */
    public function update(
        ClientExpense $expense,
        NewExpense $facts,
        ?ClientProject $project = null,
        bool $reattribute = false,
    ): ClientExpense {
        // The project is checked here rather than folded into the facts, for
        // the same reason {@see record()} checks it: it is a tenant reference
        // and `NewExpense` deliberately carries none. A cross-tenant project
        // is refused before anything is locked.
        if ($reattribute && $project !== null) {
            if ($project->workspace_id !== $this->workspace->id) {
                throw new CrossTenantReference('That project belongs to another workspace.');
            }

            if ($project->client_company_id !== $expense->client_company_id) {
                throw new CrossTenantReference('That project belongs to another client company.');
            }
        }

        return $this->mutate($expense, static function (ClientExpense $locked) use ($facts, $project, $reattribute): ClientExpense {
            $status = $locked->getAttribute('status');

            if (! ExpenseStatus::isEditableValue($status)) {
                throw ExpenseTransitionRefused::edit($status);
            }

            // `$reattribute` distinguishes "leave the attribution alone" from
            // "clear it", which a null project alone cannot: a caller editing
            // only the money must not silently detach the expense from its
            // project, and a caller clearing the project must be able to.
            $attributes = $facts->attributes();

            if ($reattribute) {
                $attributes['client_project_id'] = $project?->id;
            }

            $locked->forceFill($attributes)->save();

            return $locked;
        });
    }

    /**
     * Pass a draft expense, stamping who passed it and when.
     *
     * The approver is checked for membership of this workspace before anything
     * is locked. It is the same class of mistake the company and project checks
     * in {@see record()} catch, on the column those checks do not cover:
     * `approved_by_user_id` points at `users`, which is not a tenant-owned
     * table, so no composite key can refuse a stranger and nothing but this
     * would notice one signing off another workspace's money.
     *
     * Membership is read outside the lock, and outside the transaction, on
     * purpose. The contended row is the expense: a membership that changed in
     * the microseconds either side of this read is a different question - about
     * revoking someone's access, not about two approvals racing - and holding a
     * lock on it for the length of an approval would serialise every manager in
     * the workspace behind one row to answer a question nobody is asking.
     */
    public function approve(ClientExpense $expense, User $approver): ClientExpense
    {
        if (! $this->workspace->memberships()->where('user_id', $approver->id)->exists()) {
            throw new CrossTenantReference('That approver is not a member of this workspace.');
        }

        return $this->move($expense, ExpenseStatus::Approved, fn (): array => [
            'approved_by_user_id' => $approver->id,
            'approved_at' => $this->clock->now($this->workspace),
        ]);
    }

    /**
     * Send an approved expense back to draft.
     *
     * The stamps are cleared rather than kept as history. Keeping them would
     * leave a draft that names an approver and a time it was approved, and the
     * next reader of that row - a list, an export, the invoicing hook - has to
     * know to disbelieve two columns that are populated. An expense whose
     * approval was withdrawn has not been approved.
     *
     * `invoiced` does not come back this way, and {@see ExpenseStatus} is where
     * that is stated: the expense is on a client's bill, and the way back is to
     * change the bill.
     */
    public function unapprove(ClientExpense $expense): ClientExpense
    {
        return $this->move($expense, ExpenseStatus::Draft, static fn (): array => [
            'approved_by_user_id' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * Withdraw an expense that has not reached a bill.
     *
     * A soft delete, so the row survives for the audit trail and for anything
     * that has already quoted it. Refused once the expense has been invoiced -
     * and refused through {@see ExpenseStatus::hasBeenInvoicedValue()}, which
     * answers yes to a status it does not recognise. That is the direction to
     * be wrong in here: the cost of refusing a discard is that somebody asks a
     * human, and the cost of allowing one is a line on an issued invoice
     * pointing at a row that is gone.
     */
    public function discard(ClientExpense $expense): void
    {
        $this->mutate($expense, static function (ClientExpense $locked): ClientExpense {
            $status = $locked->getAttribute('status');

            if (ExpenseStatus::hasBeenInvoicedValue($status)) {
                throw ExpenseTransitionRefused::discard($status);
            }

            $locked->delete();

            return $locked;
        });
    }

    /**
     * Move a locked expense to a new status, with whatever else that move
     * writes.
     *
     * The legality question is asked of the locked row's status, not of the
     * caller's copy, and {@see ExpenseStatus::mayTransitionValue()} answers no
     * for a status it cannot place. So an expense that a migration or a
     * hand-run statement left holding an unrecognised value stops here rather
     * than being repaired into whichever state the caller happened to ask for.
     *
     * @param  Closure(): array<string, mixed>  $stamps
     */
    private function move(ClientExpense $expense, ExpenseStatus $to, Closure $stamps): ClientExpense
    {
        return $this->mutate($expense, static function (ClientExpense $locked) use ($to, $stamps): ClientExpense {
            $status = $locked->getAttribute('status');

            if (! ExpenseStatus::mayTransitionValue($status, $to)) {
                throw ExpenseTransitionRefused::move($status, $to);
            }

            $locked->forceFill([...$stamps(), 'status' => $to->value])->save();

            return $locked;
        });
    }

    /**
     * Take the row's lock, hand the write the copy that was read under it.
     *
     * The workspace check runs first, on the caller's model, because a row from
     * another tenant must be refused as a tenancy error rather than as a
     * missing row - the caller is holding an id it never checked, and telling
     * it "not found" invites a retry with a different lookup.
     *
     * Then the re-read. It goes through {@see query()}, so the lock statement
     * itself carries the workspace predicate: a boundary that locked by primary
     * key alone would take a real lock on another tenant's row for as long as
     * the transaction ran, which is a cross-tenant effect even though the write
     * that followed would be refused.
     *
     * A locked read that finds nothing means the row was deleted between the
     * caller's read and this one. That is `ModelNotFoundException` and not a
     * transition refusal: there is no status to refuse, and the caller's next
     * move is to stop rather than to re-read and retry.
     *
     * @param  Closure(ClientExpense): ClientExpense  $write
     */
    private function mutate(ClientExpense $expense, Closure $write): ClientExpense
    {
        if ($expense->workspace_id !== $this->workspace->id) {
            throw new CrossTenantReference('That expense belongs to another workspace.');
        }

        return DB::transaction(function () use ($expense, $write): ClientExpense {
            $locked = $this->query()->whereKey($expense->id)->tap(Locks::forUpdate())->first();

            if (! $locked instanceof ClientExpense) {
                throw (new ModelNotFoundException)->setModel(ClientExpense::class);
            }

            return $write($locked);
        });
    }
}
