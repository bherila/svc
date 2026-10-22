<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Services\Collaboration\TopicKind;
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
 * @property TopicKind $kind
 * @property string $title
 * @property string $body
 * @property int|null $author_user_id
 * @property string $author_name
 * @property bool $is_visible_to_client
 * @property int $lock_version
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['workspace_id', 'client_company_id', 'client_project_id', 'kind', 'title', 'body', 'author_user_id', 'author_name', 'is_visible_to_client'])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'author_user_id'])]
class ClientCollaborationTopic extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return ['kind' => TopicKind::class, 'is_visible_to_client' => 'boolean', 'edited_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $topic): void {
            if ($topic->isDirty(['public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'kind', 'author_user_id', 'author_name'])) {
                throw new LogicException('Collaboration lineage and authorship are immutable.');
            }
        });
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
