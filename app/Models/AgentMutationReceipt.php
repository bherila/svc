<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $request_digest
 * @property string $status
 * @property list<string> $result_public_ids
 * @property array<string, mixed>|null $response_snapshot
 */
#[Fillable(['user_id', 'workspace_id', 'oauth_client_id', 'operation', 'idempotency_key', 'request_digest', 'status', 'result_public_ids', 'response_snapshot', 'completed_at'])]
class AgentMutationReceipt extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['result_public_ids' => 'array', 'response_snapshot' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
