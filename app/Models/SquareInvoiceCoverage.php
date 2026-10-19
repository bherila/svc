<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A Square invoice covered by an svc invoice (#487): one svc invoice covers
 * what Square split into several, its primary Square invoice is linked, and
 * each other one is recorded here. Not a link - nothing collects, reconciles
 * or records a payment through it; it tells the billing audit the Square
 * invoice is accounted for.
 *
 * Clearing is soft: the row stays as history with `cleared_at`, and its
 * active slot is emptied so the Square invoice may be covered or linked again.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property string $square_invoice_id
 * @property string $square_invoice_number
 * @property string|null $active_square_invoice_slot
 * @property int|null $covered_by_user_id
 * @property CarbonImmutable $covered_at
 * @property int|null $cleared_by_user_id
 * @property CarbonImmutable|null $cleared_at
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'square_invoice_id', 'square_invoice_number', 'active_square_invoice_slot',
    'covered_by_user_id', 'covered_at', 'cleared_by_user_id', 'cleared_at',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'active_square_invoice_slot', 'covered_by_user_id', 'cleared_by_user_id'])]
class SquareInvoiceCoverage extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'covered_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
