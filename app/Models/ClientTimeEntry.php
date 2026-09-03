<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Billing\SubcontractorBillingMode;
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
 * @property string $description
 * @property string|null $client_visible_description
 * @property bool $is_visible_to_client
 * @property bool $is_billable
 * @property bool $is_deferred
 * @property int|null $billing_rate_amount
 * @property string|null $currency
 * @property string $status
 * @property CarbonImmutable|null $approved_at
 * @property SubcontractorBillingMode|null $subcontractor_billing_mode
 * @property int $lock_version
 */
#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'user_id',
    'worked_on', 'minutes', 'description', 'client_visible_description', 'job_type', 'split_from_time_entry_id', 'is_billable', 'is_deferred', 'is_visible_to_client', 'billing_rate_amount', 'billing_rate_source', 'currency',
    'status', 'approved_by_user_id', 'approved_at', 'subcontractor_billing_mode', 'subcontractor_cost_amount', 'subcontractor_cost_currency',
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
            'subcontractor_billing_mode' => SubcontractorBillingMode::class,
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
     * Consultant time (no mode) and retainer-mode subcontractor time draw on
     * the pool. Flat-hourly time bills separately; direct time is only tracked.
     * A legacy/inconsistent row with a cost but no mode is excluded fail-closed
     * so it cannot consume capacity and then be charged again as a separate
     * line. The migration labels every such existing row flat-hourly.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRetainerBillable(Builder $query): Builder
    {
        return $query->approved()->where(
            fn (Builder $mode): Builder => $mode
                ->where('subcontractor_billing_mode', SubcontractorBillingMode::Retainer->value)
                ->orWhere(
                    fn (Builder $consultant): Builder => $consultant
                        ->whereNull('subcontractor_billing_mode')
                        ->whereNull('subcontractor_cost_amount'),
                ),
        );
    }

    /**
     * Can this persisted entry be billed at an agreement's rate?
     *
     * Replay snapshots use the in-memory form of the same contract as
     * {@see scopeRetainerBillable()} so a proof can distinguish ordinary or
     * retainer-mode work from flat-hourly, direct, and malformed source rows
     * without issuing another query.
     */
    public function isAgreementRateBillable(): bool
    {
        $rawMode = $this->getRawOriginal('subcontractor_billing_mode');
        $mode = $rawMode === null ? null : SubcontractorBillingMode::tryFrom((string) $rawMode);

        return in_array($this->status, ['approved', 'invoiced'], true)
            && $this->is_billable
            && ($mode === SubcontractorBillingMode::Retainer
                || ($rawMode === null && $this->subcontractor_cost_amount === null));
    }

    /**
     * Would approving this draft draw on a retainer?
     *
     * The same conditions {@see scopeRetainerBillable} and
     * {@see scopeDeferredOnlyOnceAllocated} impose, asked of work that has
     * not been approved yet - so a screen reporting what is poised to consume
     * capacity reports the same set the ledger will count. Subcontractor time
     * bills at its own cost and deferred time waits for an invoice; neither
     * draws on the retainer, and counting them overstates the claim on it
     * before approval and leaves the total wrong after.
     */
    public function willDrawOnRetainerWhenApproved(): bool
    {
        return $this->status === 'draft'
            && $this->is_billable
            && ! $this->is_deferred
            && ($this->subcontractor_billing_mode === SubcontractorBillingMode::Retainer
                || ($this->subcontractor_billing_mode === null && $this->subcontractor_cost_amount === null));
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
     * Work whose hours have actually drawn on the retainer.
     *
     * Deferred work draws only once the allocator bills it, so a deferred entry
     * still waiting is excluded - counting it consumes pool nothing has taken,
     * manufactures a negative balance, and bills catch-up hours to restore
     * capacity that was never used.
     *
     * The subtlety is the other half: allocation attaches the entry to a
     * zero-value retainer line and never clears `is_deferred`. Four ledger
     * queries wrote `where('is_deferred', false)` by hand, so once the invoice
     * was issued those hours vanished from every later rebuild, the same pool
     * rolled forward a second time, and the overage that followed was
     * understated. Billed is billed, whatever the flag still says.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeferredOnlyOnceAllocated(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $entry): Builder => $entry
                ->where('is_deferred', false)
                // The allocation has to be this entry's own tenant's. Line,
                // pivot and invoice each carry a `workspace_id` and the schema
                // does not require them to agree, so an unscoped check lets
                // another workspace's row decide whether this one's deferred
                // time counts - and the totals it feeds are read as this
                // workspace's own.
                ->orWhereHas('invoiceLines', fn (Builder $lines): Builder => $lines
                    ->whereColumn('client_invoice_lines.workspace_id', 'client_time_entries.workspace_id')
                    ->whereColumn('client_invoice_line_time_entries.workspace_id', 'client_time_entries.workspace_id')
                    ->whereHas('invoice', fn (Builder $invoice): Builder => $invoice
                        ->whereColumn('client_invoices.workspace_id', 'client_time_entries.workspace_id'))),
        );
    }

    /**
     * Work that may appear on an invoice at all.
     *
     * Direct-mode subcontractor work is visible time but never ours to invoice.
     * Restrict to known values so a malformed mode fails closed rather than
     * becoming billable by accident.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBillableForInvoicing(Builder $query): Builder
    {
        return $query->approved()->where(
            fn (Builder $mode): Builder => $mode
                ->whereNull('subcontractor_billing_mode')
                ->orWhereIn('subcontractor_billing_mode', [
                    SubcontractorBillingMode::FlatHourly->value,
                    SubcontractorBillingMode::Retainer->value,
                ]),
        );
    }

    /**
     * Invoiceable work whose applicable immutable price snapshot is complete.
     * Consultant and retainer modes need the client billing rate; flat-hourly
     * needs its subcontractor cost pair. Direct and malformed combinations
     * remain excluded by this and {@see scopeBillableForInvoicing()}.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePricedForInvoicing(Builder $query): Builder
    {
        return $query->billableForInvoicing()->where(
            fn (Builder $priced): Builder => $priced
                ->where(
                    fn (Builder $ordinary): Builder => $ordinary
                        ->whereNull('subcontractor_cost_amount')
                        ->whereNotNull('billing_rate_amount')
                        ->where(
                            fn (Builder $mode): Builder => $mode
                                ->whereNull('subcontractor_billing_mode')
                                ->orWhere('subcontractor_billing_mode', SubcontractorBillingMode::Retainer->value),
                        ),
                )
                ->orWhere(
                    fn (Builder $flat): Builder => $flat
                        ->where('subcontractor_billing_mode', SubcontractorBillingMode::FlatHourly->value)
                        ->whereNotNull('subcontractor_cost_amount')
                        ->whereNotNull('subcontractor_cost_currency')
                        ->where('subcontractor_cost_currency', '!=', ''),
                ),
        );
    }

    /**
     * Approved work billed additively at its snapshotted subcontractor rate.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFlatHourlySubcontractor(Builder $query): Builder
    {
        return $query->approved()
            ->where('subcontractor_billing_mode', SubcontractorBillingMode::FlatHourly->value);
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
