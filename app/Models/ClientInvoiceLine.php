<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'workspace_id', 'client_invoice_id', 'type', 'description', 'quantity',
    'unit_amount', 'tax_amount', 'total_amount', 'sort_order',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id'])]
class ClientInvoiceLine extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
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
}
