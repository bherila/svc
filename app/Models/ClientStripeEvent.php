<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'stripe_event_id', 'event_type', 'object_id', 'payload_hash',
    'status', 'error_summary', 'processed_at',
])]
#[Hidden(['id', 'workspace_id', 'stripe_event_id', 'object_id', 'payload_hash', 'error_summary'])]
class ClientStripeEvent extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
