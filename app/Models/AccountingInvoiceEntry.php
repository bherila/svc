<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property array<string, mixed> $payload
 */
#[Fillable(['workspace_id', 'client_invoice_id', 'source_key', 'effective_on', 'payload', 'created_at', 'updated_at'])]
class AccountingInvoiceEntry extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Accounting records are reversed, never updated.'));
        static::deleting(static fn (): never => throw new LogicException('Accounting records are reversed, never deleted.'));
    }
}
