<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An actor-owned reservation; a workspace does not exist until creation succeeds.
 * @property string $request_digest
 * @property int|null $created_workspace_id
 */
#[Fillable(['user_id', 'oauth_client_id', 'idempotency_key', 'request_digest', 'created_workspace_id'])]
final class AgentWorkspaceCreation extends Model
{
    public $timestamps = false;
}
