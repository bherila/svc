<?php

namespace App\Services\Collaboration;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/** Shared web/REST/MCP mutation boundary; every adapter supplies an explicit audience. */
final class CollaborationAction
{
    public function __construct(private readonly CollaborationRepository $records, private readonly CollaborationAccess $access, private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function createTopic(User $actor, Workspace $workspace, ClientCompany $company, TopicDraft $draft, CollaborationAudience $audience): ClientCollaborationTopic
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $draft, $audience): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $project = $draft->projectId === null ? null : $this->records->project($workspace, $company, $draft->projectId);
            abort_unless($this->access->canPublish($actor, $workspace, $company, $project, $audience), 403);

            return $this->records->createTopic($workspace, ['client_company_id' => $company->id, 'client_project_id' => $project?->id,
                'kind' => $draft->kind, 'title' => $draft->title, 'body' => $draft->body, 'author_user_id' => $actor->id,
                'author_name' => $actor->name, 'is_visible_to_client' => $draft->visibleToClient]);
        });
    }

    public function updateTopic(User $actor, Workspace $workspace, ClientCompany $company, string $id, ContentPatch $patch, CollaborationAudience $audience): ClientCollaborationTopic
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $patch, $audience): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $topic = $this->records->topic($workspace, $company, $id, true);
            $publisher = $this->access->canPublish($actor, $workspace, $company, $this->records->topicProject($workspace, $company, $topic), $audience);
            $content = array_diff_key($patch->changes, ['is_visible_to_client' => true]);
            abort_unless($publisher || $this->access->canEditTopic($actor, $workspace, $company, $topic, $audience), 403);
            abort_unless($content === [] || $this->access->canEditTopic($actor, $workspace, $company, $topic, $audience), 403);
            abort_unless(! array_key_exists('is_visible_to_client', $patch->changes) || $publisher, 403);
            abort_unless($topic->deleted_at === null && $this->access->canViewParent($actor, $workspace, $company, $topic, $audience), 404);
            $this->version($topic, $patch->expectedVersion);
            $this->records->save($workspace, $topic, [...$patch->changes, 'edited_at' => $content === [] ? $topic->edited_at : $this->clock->now($workspace)->utc(), 'lock_version' => $topic->lock_version + 1]);

            return $topic;
        });
    }

    public function deleteTopic(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience): ClientCollaborationTopic
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $version, $audience): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $topic = $this->records->topic($workspace, $company, $id, true);
            abort_unless($this->access->canPublish($actor, $workspace, $company, $this->records->topicProject($workspace, $company, $topic), $audience), 403);
            $this->version($topic, $version);
            abort_unless($topic->deleted_at === null, 404);
            $this->records->save($workspace, $topic, ['deleted_at' => $this->clock->now($workspace)->utc()]);

            return $topic;
        });
    }

    public function createComment(User $actor, Workspace $workspace, ClientCompany $company, string $parentType, string $parentId, CommentDraft $draft, CollaborationAudience $audience): ClientComment
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $parentType, $parentId, $draft, $audience): ClientComment {
            $this->records->lockAccess($workspace);
            $parent = $this->records->parent($workspace, $company, $parentType, $parentId, true);
            abort_unless($this->access->canComment($actor, $workspace, $company, $parent, $audience), 403);
            $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);
            abort_unless(! $draft->visibleToClient || $this->access->canPublish($actor, $workspace, $company, $project, $audience), 403);
            $this->version($parent, $draft->expectedVersion);
            $comment = $this->records->createComment($workspace, ['client_company_id' => $company->id, 'client_project_id' => $parent->client_project_id,
                'client_task_id' => $parent instanceof ClientTask ? $parent->id : null,
                'client_collaboration_topic_id' => $parent instanceof ClientCollaborationTopic ? $parent->id : null,
                'body' => $draft->body, 'author_user_id' => $actor->id, 'author_name' => $actor->name, 'is_visible_to_client' => $draft->visibleToClient]);

            return $comment;
        });
    }

    public function updateComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, ContentPatch $patch, CollaborationAudience $audience): ClientComment
    {
        return $this->mutateComment($actor, $workspace, $company, $id, $patch->expectedVersion, $audience, $patch);
    }

    public function deleteComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience): ClientComment
    {
        return $this->mutateComment($actor, $workspace, $company, $id, $version, $audience, null);
    }

    private function mutateComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience, ?ContentPatch $patch): ClientComment
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $version, $audience, $patch): ClientComment {
            $this->records->lockAccess($workspace);
            $initial = $this->records->comment($workspace, $company, $id);
            $parent = $this->records->commentParent($workspace, $company, $initial, true);
            $comment = $this->records->comment($workspace, $company, $id, true);
            abort_unless($this->access->canComment($actor, $workspace, $company, $parent, $audience), 403);
            $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);
            $publisher = $this->access->canPublish($actor, $workspace, $company, $project, $audience);
            $author = $this->access->canEditComment($actor, $workspace, $company, $comment, $parent, $audience);
            abort_unless($author || $publisher, 403);
            abort_unless($comment->deleted_at === null, 404);
            if ($patch !== null) {
                abort_unless(! array_key_exists('body', $patch->changes) || $author, 403);
                abort_unless(! array_key_exists('is_visible_to_client', $patch->changes) || $publisher, 403);
            }
            $this->version($comment, $version);
            $this->records->save($workspace, $comment, $patch === null
                ? ['deleted_at' => $this->clock->now($workspace)->utc()]
                : [...$patch->changes, 'edited_at' => array_key_exists('body', $patch->changes) ? $this->clock->now($workspace)->utc() : $comment->edited_at, 'lock_version' => $comment->lock_version + 1]);

            return $comment;
        });
    }

    private function version(ClientTask|ClientCollaborationTopic|ClientComment $record, string $version): void
    {
        abort_unless(AgentApiVersion::matches($record, $version), 409, 'The content changed. Read it again before saving.');
    }
}
