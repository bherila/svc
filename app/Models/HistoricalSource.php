<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['workspace_id', 'system', 'identity_hash', 'label'])]
#[Hidden(['id', 'workspace_id', 'identity_hash'])]
class HistoricalSource extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            if ($source->isDirty(['workspace_id', 'system', 'identity_hash'])) {
                throw new LogicException('A historical source identity cannot be changed.');
            }
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
