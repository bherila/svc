<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'oauth_client_id', 'operation', 'idempotency_key', 'request_digest', 'result_public_ids'])]
/** @property list<string> $result_public_ids */
class AgentMutationReceipt extends Model
{
    protected function casts(): array
    {
        return ['result_public_ids' => 'array'];
    }
}
