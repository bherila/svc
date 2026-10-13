<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Square invoice as the last sync saw it (#438). Square is the source of
 * truth; this is the cache explore, series and matching read. Written only by
 * {@see SquareMirrorRepository}.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $square_invoice_id
 * @property int|null $square_version
 * @property string|null $square_location_id
 * @property string|null $square_order_id
 * @property string|null $square_customer_id
 * @property string|null $square_subscription_id
 * @property string|null $invoice_number
 * @property string|null $series_key
 * @property int|null $series_occurrence
 * @property string|null $title
 * @property string|null $recipient_name
 * @property string $status
 * @property string|null $currency
 * @property int|null $total_amount
 * @property int|null $paid_amount
 * @property int|null $next_payment_amount
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $sale_or_service_date
 * @property CarbonImmutable|null $square_created_at
 * @property CarbonImmutable|null $square_updated_at
 * @property string|null $public_url
 * @property bool $autopay
 * @property list<array<string, mixed>> $payment_requests
 * @property list<string>|null $payment_ids
 * @property string|null $payment_ids_basis
 * @property string $fingerprint
 * @property CarbonImmutable $synced_at
 * @property CarbonImmutable $last_seen_at
 * @property int|null $last_sync_run_id
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'workspace_id', 'square_invoice_id', 'square_version', 'square_location_id', 'square_order_id', 'square_customer_id',
    'square_subscription_id', 'invoice_number', 'series_key', 'series_occurrence', 'title', 'recipient_name', 'status',
    'currency', 'total_amount', 'paid_amount', 'next_payment_amount', 'due_on', 'scheduled_at', 'sale_or_service_date',
    'square_created_at', 'square_updated_at', 'public_url', 'autopay', 'payment_requests', 'payment_ids',
    'payment_ids_basis', 'fingerprint', 'synced_at', 'last_seen_at', 'last_sync_run_id',
])]
#[Hidden(['id', 'workspace_id', 'fingerprint', 'payment_ids_basis', 'deleted_at', 'last_sync_run_id'])]
class SquareInvoiceSnapshot extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'square_version' => 'integer',
            'series_occurrence' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'next_payment_amount' => 'integer',
            'due_on' => DateOnly::class,
            'scheduled_at' => 'immutable_datetime',
            'sale_or_service_date' => DateOnly::class,
            'square_created_at' => 'immutable_datetime',
            'square_updated_at' => 'immutable_datetime',
            'autopay' => 'boolean',
            'payment_requests' => 'array',
            'payment_ids' => 'array',
            'synced_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_sync_run_id' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
