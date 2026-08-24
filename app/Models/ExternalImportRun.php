<?php

namespace App\Models;

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
class ExternalImportRun extends Model
{
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
