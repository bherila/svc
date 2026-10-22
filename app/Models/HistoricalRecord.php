<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['workspace_id', 'historical_source_id', 'source_table', 'source_key', 'fingerprint', 'snapshot', 'target_type', 'target_public_id'])]
#[Hidden(['id', 'workspace_id', 'historical_source_id', 'source_table', 'source_key', 'fingerprint', 'snapshot', 'target_type', 'target_public_id'])]
class HistoricalRecord extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['workspace_id', 'historical_source_id', 'source_table', 'source_key', 'fingerprint', 'snapshot'])) {
                throw new LogicException('Original source records cannot be overwritten.');
            }
            if ($record->getOriginal('target_public_id') !== null && $record->isDirty(['target_type', 'target_public_id'])) {
                throw new LogicException('A preserved source record cannot be linked to another destination.');
            }
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
