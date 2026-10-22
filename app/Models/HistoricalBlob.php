<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['workspace_id', 'historical_entry_id', 'filename', 'object_key', 'bytes', 'sha256'])]
#[Hidden(['id', 'workspace_id', 'historical_entry_id', 'object_key', 'sha256'])]
class HistoricalBlob extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Historical file metadata is read-only.');
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
