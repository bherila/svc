<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'workspace_id', 'oauth_client_id', 'operation', 'idempotency_key', 'request_digest', 'status', 'result_public_ids', 'completed_at'])]
/**
 * @property string $request_digest
 * @property string $status
 * @property list<string> $result_public_ids
 */
class AgentMutationReceipt extends Model
{
    protected function casts(): array
    {
        return ['result_public_ids' => 'array', 'completed_at' => 'immutable_datetime'];
    }
}
