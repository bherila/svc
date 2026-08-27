<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
 * @property int $lock_version
 */
#[Fillable(['workspace_id', 'client_project_id', 'title', 'description', 'status', 'is_visible_to_client', 'completed_at', 'milestone_price_amount', 'client_invoice_line_id'])]
#[Hidden(['id', 'workspace_id', 'client_project_id'])]
class ClientTask extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'is_visible_to_client' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'milestone_price_amount' => 'integer',
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
