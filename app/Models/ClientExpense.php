<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\Expenses\ExpenseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reimbursable client expense: an amount, a date, a company, and optionally a
 * project.
 *
 * Reads here are deliberately thin. The row is written through
 * {@see WorkspaceExpenses}, which is the only place that
 * resolves a company or a project for a workspace, so a caller cannot assemble
 * an expense out of ids it did not check. Nothing on this model transitions the
 * lifecycle either: a transition needs a row lock and a re-read under it, and a
 * model method is reachable from anywhere, including from outside a
 * transaction. The boundary owns the moves.
 *
 * ## Why `status` is not cast to its enum
 *
 * Laravel's backed-enum cast throws on a value the enum does not know, so a
 * single unrecognised row would take down every list that touches it - and this
 * column is exactly where a value from an older release or a hand-run statement
 * shows up. {@see ExpenseStatus} therefore stays a vocabulary with fail-closed
 * readers over the stored string, matching `client_time_entries` and
 * `client_invoices`, both of which keep `status` a string for the same reason.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int|null $client_project_id
 * @property int|null $created_by_user_id
 * @property CarbonImmutable $spent_on
 * @property int $amount
 * @property string $currency
 * @property string $description
 * @property string $status
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'client_project_id', 'created_by_user_id',
    'spent_on', 'amount', 'currency', 'description',
    'status', 'approved_by_user_id', 'approved_at',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'created_by_user_id', 'approved_by_user_id'])]
class ClientExpense extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return [
            'spent_on' => 'immutable_date',
            'amount' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Expenses a manager has passed.
     *
     * `invoiced` is included for the reason {@see ExpenseStatus::approved()}
     * gives: billing rewrites the status, so reading the literal `approved`
     * alone hides every expense already charged to the client.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereIn('status', ExpenseStatus::approved());
    }

    /** Has a manager passed this expense? Fail-closed on an unrecognised status. */
    public function isApproved(): bool
    {
        return ExpenseStatus::isApprovedValue($this->getAttribute('status'));
    }

    /** Is this expense already on a client's bill? Fail-closed on an unrecognised status. */
    public function hasBeenInvoiced(): bool
    {
        return ExpenseStatus::hasBeenInvoicedValue($this->getAttribute('status'));
    }

    /**
     * Carry the workspace into every update and delete this row performs.
     *
     * Eloquent keys a save by primary key alone, so `save()` on a model read
     * through a workspace-scoped query still emits `where id = ?`. The write is
     * not reachable across tenants - the id came from a scoped read holding
     * `FOR UPDATE` inside the same transaction, so nothing can move it - but
     * "not reachable" is an argument about the caller, and the rule this
     * repository states is about the statement: every tenant-owned write is
     * workspace-scoped. This makes that true of the SQL rather than of the
     * reasoning around it, which is the difference between a guarantee and a
     * paragraph.
     *
     * `setKeysForSaveQuery()` is the seam Laravel provides for exactly this,
     * and taking it keeps `save()`: casts, timestamps and model events all
     * still run, where hand-writing the update statements would have traded a
     * scoping guarantee for three subtler ways to be wrong. It backs the update
     * `save()` issues, both delete paths and `restore()`, so there is no fifth
     * write to remember.
     *
     * The predicate is the *stored* workspace, not the in-memory attribute. A
     * save that had its `workspace_id` rewritten in memory then matches no row
     * instead of reaching for one in the tenant it was pointed at.
     *
     * The parent is called for its effect and `$query` is returned rather than
     * its result. Both are the same object - it configures the builder it was
     * handed and hands it back - but the analyser reads the parent's return as
     * `Builder<Model>` and loses the `static` this signature promises. Keeping
     * the parent call is still the point: the key predicate stays the
     * framework's to define, and this adds one clause to it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);

        return $query->where(
            'workspace_id',
            $this->getRawOriginal('workspace_id', $this->getAttribute('workspace_id')),
        );
    }
}
