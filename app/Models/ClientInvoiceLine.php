<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property string $type
 * @property string $description
 * @property numeric-string $quantity
 * @property numeric-string|null $hours
 * @property CarbonImmutable|null $line_date
 * @property int|null $client_project_id
 * @property int $unit_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property int $sort_order
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'type', 'description', 'quantity',
    'client_project_id', 'unit_amount', 'tax_amount', 'total_amount', 'sort_order',
    // Restored ledger detail. Without these here a model create silently drops
    // them, which is how the recurring-item quantity went missing for so long.
    'hours', 'line_date', 'client_agreement_id', 'client_agreement_recurring_item_id',
])]
// The agreement references are internal auto-increment ids. InvoiceController
// serializes line models straight to JSON for the client portal, so leaving
// them visible published another tenant-scoped table's primary keys.
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'client_agreement_id', 'client_agreement_recurring_item_id'])]
class ClientInvoiceLine extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'hours' => 'decimal:4',
            'line_date' => 'date',
            'unit_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    /** @return BelongsToMany<ClientTimeEntry, $this> */
    public function timeEntries(): BelongsToMany
    {
        return $this->belongsToMany(
            ClientTimeEntry::class,
            'client_invoice_line_time_entries',
            'client_invoice_line_id',
            'client_time_entry_id',
        )->withPivot('workspace_id')->withTimestamps();
    }

    /**
     * Milestone tasks billed on this line.
     *
     * A single column rather than a pivot, unlike time entries: a milestone is
     * one deliverable at one price and can never be split across lines.
     *
     * @return HasMany<ClientTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ClientTask::class, 'client_invoice_line_id');
    }
}
