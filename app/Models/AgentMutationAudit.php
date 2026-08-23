<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'oauth_client_id', 'workspace_id', 'operation', 'affected_public_ids', 'request_id', 'outcome', 'error_category'])]
class AgentMutationAudit extends Model
{
    protected function casts(): array
    {
        return ['affected_public_ids' => 'array'];
    }
}
