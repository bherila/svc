<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_project_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property bool $is_visible_to_client
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable(['workspace_id', 'client_project_id', 'title', 'description', 'status', 'is_visible_to_client', 'completed_at'])]
class ClientTask extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'is_visible_to_client' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
