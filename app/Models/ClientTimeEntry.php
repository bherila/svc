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
    'worked_on', 'minutes', 'description', 'client_visible_description', 'job_type', 'split_from_time_entry_id', 'is_billable', 'is_deferred', 'is_visible_to_client', 'billing_rate_amount', 'billing_rate_source', 'currency',
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

    /**
     * Approved work only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        // `invoiced` counts. This schema collapsed the predecessor's separate
        // approval_status column into `status`, and issuing an invoice rewrites
        // approved work to `invoiced` - so reading the literal alone makes every
        // ledger rebuild forget the work it has already billed, inflating
        // rollover and understating the next overage.
        return $query->whereIn('status', ['approved', 'invoiced']);
    }

    /**
     * Work that may draw on retainer capacity.
     *
     * The predecessor excluded subcontractor work billed in modes that bypass
     * the retainer, and did it here - so all seven of its call sites got the
     * exclusion for free. This port dropped it, reasoning that the schema has
     * no `subcontractor_billing_mode` column. That was true of the column and
     * false of the concept: `subcontractor_cost_amount` is the flat-hourly
     * signal in this schema, and InvoiceLineComposer already bills off it.
     *
     * With the exclusion gone from here it was added back one caller at a time,
     * and reached only one of the three. The ledger and the interim generator
     * were still letting flat-hourly hours consume retainer pool that the
     * composer then billed again as its own line - charged once to the pool and
     * once on the invoice.
     *
     * It belongs here, where a caller cannot forget it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRetainerBillable(Builder $query): Builder
    {
        return $query->approved()->whereNull('subcontractor_cost_amount');
    }

    /**
     * Narrow to the work a given agreement is entitled to draw on.
     *
     * An agreement scoped to one project has its own retainer, and the
     * company's other projects draw on theirs. Pooling them makes each ledger
     * count work the other is paying for, and lets whichever agreement
     * generates first claim every project's time.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAgreementScope(Builder $query, ClientAgreement $agreement): Builder
    {
        return $query->when(
            $agreement->client_project_id !== null,
            fn (Builder $scoped): Builder => $scoped->where('client_project_id', $agreement->client_project_id),
        );
    }

    /**
     * Work that may appear on an invoice at all.
     *
     * Distinct from {@see scopeRetainerBillable()} in the predecessor, where it
     * excluded only directly-billed subcontractor work. This schema records no
     * billing mode, so the two currently coincide; they stay separate because
     * the generator asks two different questions of them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBillableForInvoicing(Builder $query): Builder
    {
        return $query->approved();
    }

    /**
     * Work not yet attached to any invoice line.
     *
     * The predecessor tested a null column; here the absence of a pivot row is
     * the equivalent, and the unique index on the pivot is what makes "no row"
     * and "not billed" the same statement.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->whereDoesntHave('invoiceLines');
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
