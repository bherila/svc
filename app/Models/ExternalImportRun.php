<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'source_connection', 'source_identity_hash', 'mode', 'status',
    'source_high_water_marks', 'counts', 'fingerprints', 'started_at', 'completed_at',
])]
#[Hidden(['id', 'workspace_id', 'source_connection', 'source_identity_hash'])]
/**
 * One execution of the retired external importer: when it ran, against which
 * source identity, and what it counted.
 *
 * Retained history rather than live machinery - see {@see ExternalImportItem}
 * for why the ledger outlived the importer. The `source_identity_hash` is the
 * part worth keeping deliberately: it records *which* database a run read, so a
 * later reader can tell whether two runs saw the same source.
 */
class ExternalImportRun extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'source_high_water_marks' => 'array',
            'counts' => 'array',
            'fingerprints' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @param Builder<ExternalImportRun> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, int $workspaceId): void
    {
        $query->where('workspace_id', $workspaceId);
    }
}
