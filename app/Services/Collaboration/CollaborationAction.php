<?php

namespace App\Services\Collaboration;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Activity\ClientActivityRecorder;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/** Shared web/REST/MCP mutation boundary; every adapter supplies an explicit audience. */
final class CollaborationAction
{
    public function __construct(private readonly CollaborationRepository $records, private readonly WorkspaceClock $clock = new WorkspaceClock, private readonly ClientActivityRecorder $activity = new ClientActivityRecorder) {}

    public function createTopic(User $actor, Workspace $workspace, ClientCompany $company, TopicDraft $draft, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientCollaborationTopic
    {
        abort_unless($company->workspace_id === $workspace->id, 404);

        return DB::transaction(function () use ($actor, $workspace, $company, $draft, $audience, $origin): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $project = $draft->projectId === null ? null : $this->records->project($workspace, $company, $draft->projectId, true);
            $facts = $this->facts($actor, $workspace, $project, $audience, $origin);
            abort_unless($facts->canPublish(), 403);

            $topic = $this->records->createTopic($workspace, ['client_company_id' => $company->id, 'client_project_id' => $project?->id,
                'kind' => $draft->kind, 'title' => $draft->title, 'body' => $draft->body, 'author_user_id' => $actor->id,
                'author_name' => $actor->name, 'is_visible_to_client' => $draft->visibleToClient]);
            $this->activity->record($workspace, $company, 'collaboration.topic_created', $topic, [], $actor, 'revision:'.$topic->lock_version);

            return $topic;
        });
    }

    public function updateTopic(User $actor, Workspace $workspace, ClientCompany $company, string $id, ContentPatch $patch, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientCollaborationTopic
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $patch, $audience, $origin): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $topic = $this->records->topic($workspace, $company, $id, true);
            $project = $this->records->lockParentProject($workspace, $company, $topic);
            $facts = $this->facts($actor, $workspace, $project, $audience, $origin);
            $publisher = $facts->canPublish();
            $author = $publisher && $topic->author_user_id === $actor->id;
            $content = array_diff_key($patch->changes, ['is_visible_to_client' => true]);
            abort_unless($publisher, 403);
            abort_unless($content === [] || $author, 403);
            abort_unless($topic->deleted_at === null && $facts->canView(), 404);
            $this->version($topic, $patch->expectedVersion);
            $this->records->save($workspace, $topic, [...$patch->changes, 'edited_at' => $content === [] ? $topic->edited_at : $this->clock->now($workspace)->utc(), 'lock_version' => $topic->lock_version + 1]);

            $this->activity->record($workspace, $company, 'collaboration.topic_updated', $topic, [], $actor, 'revision:'.$topic->lock_version);

            return $topic;
        });
    }

    public function deleteTopic(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientCollaborationTopic
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $version, $audience, $origin): ClientCollaborationTopic {
            $this->records->lockAccess($workspace);
            $topic = $this->records->topic($workspace, $company, $id, true);
            $project = $this->records->lockParentProject($workspace, $company, $topic);
            $facts = $this->facts($actor, $workspace, $project, $audience, $origin);
            abort_unless($facts->canPublish(), 403);
            $this->version($topic, $version);
            abort_unless($topic->deleted_at === null, 404);
            $this->records->save($workspace, $topic, ['deleted_at' => $this->clock->now($workspace)->utc(), 'lock_version' => $topic->lock_version + 1]);
            $this->activity->record($workspace, $company, 'collaboration.topic_deleted', $topic, [], $actor, 'revision:'.$topic->lock_version);

            return $topic;
        });
    }

    public function createComment(User $actor, Workspace $workspace, ClientCompany $company, string $parentType, string $parentId, CommentDraft $draft, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientComment
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $parentType, $parentId, $draft, $audience, $origin): ClientComment {
            $this->records->lockAccess($workspace);
            $parent = $this->records->parent($workspace, $company, $parentType, $parentId, true);
            $project = $this->records->lockParentProject($workspace, $company, $parent);
            $facts = $this->facts($actor, $workspace, $project, $audience, $origin);
            abort_unless($facts->canComment(), 403);
            abort_if($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null, 403);
            abort_unless(! $draft->visibleToClient || $facts->canPublish(), 403);
            $this->version($parent, $draft->expectedVersion);
            $comment = $this->records->createComment($workspace, ['client_company_id' => $company->id, 'client_project_id' => $parent->client_project_id,
                'client_task_id' => $parent instanceof ClientTask ? $parent->id : null,
                'client_collaboration_topic_id' => $parent instanceof ClientCollaborationTopic ? $parent->id : null,
                'body' => $draft->body, 'author_user_id' => $actor->id, 'author_name' => $actor->name, 'is_visible_to_client' => $draft->visibleToClient]);

            $this->activity->record($workspace, $company, 'collaboration.comment_created', $comment, [], $actor, 'revision:'.$comment->lock_version);

            return $comment;
        });
    }

    public function updateComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, ContentPatch $patch, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientComment
    {
        return $this->mutateComment($actor, $workspace, $company, $id, $patch->expectedVersion, $audience, $patch, $origin);
    }

    public function deleteComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience, CollaborationOrigin $origin = CollaborationOrigin::Native): ClientComment
    {
        return $this->mutateComment($actor, $workspace, $company, $id, $version, $audience, null, $origin);
    }

    private function mutateComment(User $actor, Workspace $workspace, ClientCompany $company, string $id, string $version, CollaborationAudience $audience, ?ContentPatch $patch, CollaborationOrigin $origin): ClientComment
    {
        return DB::transaction(function () use ($actor, $workspace, $company, $id, $version, $audience, $patch, $origin): ClientComment {
            $this->records->lockAccess($workspace);
            $initial = $this->records->comment($workspace, $company, $id);
            $parent = $this->records->commentParent($workspace, $company, $initial, true);
            $comment = $this->records->comment($workspace, $company, $id, true);
            $project = $this->records->lockParentProject($workspace, $company, $parent);
            $facts = $this->facts($actor, $workspace, $project, $audience, $origin);
            abort_unless($facts->canComment(), 403);
            abort_if($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null, 403);
            $publisher = $facts->canPublish();
            $author = $comment->author_user_id === $actor->id;
            abort_unless($author || $publisher, 403);
            abort_unless($comment->deleted_at === null, 404);
            if ($patch !== null) {
                abort_unless(! array_key_exists('body', $patch->changes) || $author, 403);
                abort_unless(! array_key_exists('is_visible_to_client', $patch->changes) || $publisher, 403);
            }
            $this->version($comment, $version);
            $this->records->save($workspace, $comment, $patch === null
                ? ['deleted_at' => $this->clock->now($workspace)->utc(), 'lock_version' => $comment->lock_version + 1]
                : [...$patch->changes, 'edited_at' => array_key_exists('body', $patch->changes) ? $this->clock->now($workspace)->utc() : $comment->edited_at, 'lock_version' => $comment->lock_version + 1]);

            $this->activity->record($workspace, $company, $patch === null ? 'collaboration.comment_deleted' : 'collaboration.comment_updated', $comment, [], $actor, 'revision:'.$comment->lock_version);

            return $comment;
        });
    }

    private function facts(User $actor, Workspace $workspace, ?ClientProject $project, CollaborationAudience $audience, CollaborationOrigin $origin): CollaborationAccessFacts
    {
        abort_unless($audience === CollaborationAudience::Internal, 403);
        $facts = $this->records->currentAccess($workspace, $project, $actor);
        abort_if($origin === CollaborationOrigin::Agent && $facts->portalMember, 403);

        return $facts;
    }

    /** A receipt never substitutes for current authority, including under an old RR snapshot.
     * @param  array<string, mixed>  $payload
     */
    public function assertReplayAllowed(User $actor, Workspace $workspace, ClientCompany $company, string $operation, string $id, array $payload, CollaborationOrigin $origin): void
    {
        $this->records->lockAccess($workspace);
        if (str_starts_with($operation, 'collaboration.topics.')) {
            $topic = $this->records->topic($workspace, $company, $id, true);
            $project = $this->records->lockParentProject($workspace, $company, $topic);
            $facts = $this->facts($actor, $workspace, $project, CollaborationAudience::Internal, $origin);
            abort_unless($facts->canPublish(), 403);
            abort_if($topic->deleted_at !== null && $operation !== 'collaboration.topics.delete', 404);
            abort_unless($operation !== 'collaboration.topics.update' || ! array_key_exists('body', $payload) && ! array_key_exists('title', $payload) || $topic->author_user_id === $actor->id, 403);

            return;
        }
        $initial = $this->records->comment($workspace, $company, $id);
        $parent = $this->records->commentParent($workspace, $company, $initial, true);
        $comment = $this->records->comment($workspace, $company, $id, true);
        $project = $this->records->lockParentProject($workspace, $company, $parent);
        $facts = $this->facts($actor, $workspace, $project, CollaborationAudience::Internal, $origin);
        abort_unless($facts->canComment(), 403);
        abort_if($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null, 404);
        abort_if($comment->deleted_at !== null && $operation !== 'collaboration.comments.delete', 404);
        abort_unless($comment->author_user_id === $actor->id || $facts->canPublish(), 403);
        abort_unless($operation !== 'collaboration.comments.update' || ! array_key_exists('body', $payload) || $comment->author_user_id === $actor->id, 403);
        abort_unless(! array_key_exists('is_visible_to_client', $payload) || ! $payload['is_visible_to_client'] && $operation === 'collaboration.comments.create' || $facts->canPublish(), 403);
    }

    private function version(ClientTask|ClientCollaborationTopic|ClientComment $record, string $version): void
    {
        abort_unless(AgentApiVersion::matches($record, $version), 409, 'The content changed. Read it again before saving.');
    }
}
