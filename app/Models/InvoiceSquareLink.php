<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Square\SquareMatchMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An SVC invoice linked to its Square counterpart (#438).
 *
 * The Square side is what the operator confirmed at link time - its id, its
 * number and the series key derived from that number - not a mirror of Square.
 * Unlinking is soft: the row stays as history with `unlinked_at`, and its
 * `active_*` slots are cleared so either side can be linked again.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property string $square_invoice_id
 * @property string $square_invoice_number
 * @property string|null $series_key
 * @property string $currency
 * @property SquareMatchMethod $match_method
 * @property int|null $score
 * @property list<string>|null $reasons
 * @property int|null $active_invoice_slot
 * @property string|null $active_square_invoice_slot
 * @property int|null $linked_by_user_id
 * @property CarbonImmutable $linked_at
 * @property int|null $unlinked_by_user_id
 * @property CarbonImmutable|null $unlinked_at
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'square_invoice_id', 'square_invoice_number', 'series_key', 'currency',
    'match_method', 'score', 'reasons', 'active_invoice_slot', 'active_square_invoice_slot',
    'linked_by_user_id', 'linked_at', 'unlinked_by_user_id', 'unlinked_at',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'active_invoice_slot', 'active_square_invoice_slot', 'linked_by_user_id', 'unlinked_by_user_id'])]
class InvoiceSquareLink extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'match_method' => SquareMatchMethod::class,
            'score' => 'integer',
            'reasons' => 'array',
            'linked_at' => 'immutable_datetime',
            'unlinked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
