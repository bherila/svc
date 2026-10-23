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
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int|null $client_project_id
 * @property int|null $client_task_id
 * @property int|null $client_collaboration_topic_id
 * @property string $body
 * @property int|null $author_user_id
 * @property string $author_name
 * @property bool $is_visible_to_client
 * @property int $lock_version
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_collaboration_topic_id', 'body', 'author_user_id', 'author_name', 'is_visible_to_client'])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_collaboration_topic_id', 'author_user_id'])]
class ClientComment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return ['is_visible_to_client' => 'boolean', 'edited_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $comment): void {
            if (($comment->client_task_id === null) === ($comment->client_collaboration_topic_id === null)) {
                throw new LogicException('A comment must have exactly one task or topic parent.');
            }
        });
        static::updating(function (self $comment): void {
            if ($comment->isDirty(['public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_collaboration_topic_id', 'author_user_id', 'author_name'])) {
                throw new LogicException('Comment lineage and authorship are immutable.');
            }
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
