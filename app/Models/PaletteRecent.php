<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record a person opened from the command palette, in one workspace.
 *
 * Only the kind and the record's public id are kept; the palette resolves the
 * title and link again through the authorized queries when it reads the list.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $kind
 * @property string $record_public_id
 * @property CarbonImmutable $opened_at
 */
#[Fillable(['workspace_id', 'user_id', 'kind', 'record_public_id', 'opened_at'])]
#[Hidden(['id', 'workspace_id', 'user_id'])]
class PaletteRecent extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime'];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
