<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int $client_project_id
 * @property int|null $client_task_id
 * @property int $user_id
 * @property CarbonImmutable $worked_on
 * @property int $minutes
 * @property bool $is_billable
 * @property bool $is_deferred
 * @property int|null $billing_rate_amount
 * @property string|null $currency
 * @property string $status
 * @property int $lock_version
 */
#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'user_id',
    'worked_on', 'minutes', 'description', 'client_visible_description', 'job_type', 'is_billable', 'is_deferred', 'is_visible_to_client', 'billing_rate_amount', 'currency',
    'status', 'approved_by_user_id', 'approved_at', 'subcontractor_cost_amount', 'subcontractor_cost_currency',
    'subcontractor_cost_metadata',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'user_id', 'approved_by_user_id'])]
class ClientTimeEntry extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision, SoftDeletes;

    protected function casts(): array
    {
        return [
            'worked_on' => 'immutable_date',
            'minutes' => 'integer',
            'is_billable' => 'boolean',
            'is_deferred' => 'boolean',
            'is_visible_to_client' => 'boolean',
            'billing_rate_amount' => 'integer',
            'approved_at' => 'immutable_datetime',
            'subcontractor_cost_amount' => 'integer',
            'subcontractor_cost_metadata' => 'array',
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

    /** @return BelongsTo<ClientTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ClientTask::class, 'client_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** @return BelongsToMany<ClientInvoiceLine, $this> */
    /**
     * Approved work only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Work that may draw on retainer capacity.
     *
     * The predecessor also excluded subcontractor work billed in modes that
     * bypass the retainer. This schema has no billing mode - the source held
     * none - so the exclusion has nothing to act on and approval is the whole
     * condition. Restore the mode filter here if those modes come back.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRetainerBillable(Builder $query): Builder
    {
        return $query->approved();
    }

    // ── Billing engine surface ───────────────────────────────────────────────
    //
    // The allocation and splitting logic was ported rather than rewritten, so
    // the model presents the names that arithmetic reads. See ClientAgreement
    // for the same seam on retainer terms.

    /** The engine's name for duration. */
    public function getMinutesWorkedAttribute(): int
    {
        return (int) $this->minutes;
    }

    /** The engine's name for the work date. */
    public function getDateWorkedAttribute(): ?CarbonImmutable
    {
        return $this->worked_on;
    }

    /** The engine's name for the work description. */
    public function getNameAttribute(): string
    {
        return (string) $this->description;
    }

    /**
     * The invoice line this entry is billed on, if any.
     *
     * The predecessor stored this as a column on the entry. Here it is a pivot,
     * constrained to one line per entry, so the single row is the equivalent.
     * Eager-load `invoiceLines` when allocating in bulk or this costs a query
     * per entry.
     */
    public function getClientInvoiceLineIdAttribute(): ?int
    {
        if ($this->relationLoaded('invoiceLines')) {
            $line = $this->invoiceLines->first();

            return $line instanceof ClientInvoiceLine ? $line->id : null;
        }

        $id = $this->invoiceLines()->value('client_invoice_lines.id');

        return $id === null ? null : (int) $id;
    }

    /** @return BelongsToMany<ClientInvoiceLine, $this> */
    public function invoiceLines(): BelongsToMany
    {
        return $this->belongsToMany(ClientInvoiceLine::class, 'client_invoice_line_time_entries', 'client_time_entry_id', 'client_invoice_line_id')->withPivot('workspace_id')->withTimestamps();
    }
}
