<?php

namespace App\Services\Collaboration;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Builder;

/** Explicit tenant boundary. No source/history records participate in native collaboration. */
final class CollaborationRepository
{
    public function lockAccess(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->tap(Locks::forShare())->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function createTopic(Workspace $workspace, array $attributes): ClientCollaborationTopic
    {
        return ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    public function createComment(Workspace $workspace, array $attributes): ClientComment
    {
        return ClientComment::query()->create(['workspace_id' => $workspace->id, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    public function save(Workspace $workspace, ClientCollaborationTopic|ClientComment $content, array $attributes): void
    {
        abort_unless($content->workspace_id === $workspace->id, 404);
        $content->forceFill($attributes)->save();
    }

    public function company(Workspace $workspace, string $id): ClientCompany
    {
        return ClientCompany::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
    }

    public function project(Workspace $workspace, ClientCompany $company, string $id, bool $lock = false): ClientProject
    {
        $this->assertCompany($workspace, $company);
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('public_id', $id)
            ->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))->firstOrFail();

        return $project->setRelation('workspace', $workspace)->setRelation('clientCompany', $company);
    }

    public function lockParentProject(Workspace $workspace, ClientCompany $company, ClientTask|ClientCollaborationTopic $parent): ?ClientProject
    {
        if ($parent->client_project_id === null) {
            return null;
        }
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereKey($parent->client_project_id)
            ->tap(Locks::forUpdate())->firstOrFail();
        $project->setRelation('workspace', $workspace)->setRelation('clientCompany', $company);
        $parent->setRelation($parent instanceof ClientTask ? 'project' : 'collaborationProject', $project);

        return $project;
    }

    public function currentAccess(Workspace $workspace, ?ClientProject $project, User $actor): CollaborationAccessFacts
    {
        $staff = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->tap(Locks::forShare())->first();
        $grant = $project === null ? null : ClientProjectMembership::query()->where('workspace_id', $workspace->id)
            ->where('client_project_id', $project->id)->where('user_id', $actor->id)->tap(Locks::forShare())->first();
        // Read current membership rows directly; an ordinary company subquery
        // could consume an older outer RR snapshot. The composite FK binds tenant lineage.
        $portal = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->orderBy('id')->tap(Locks::forShare())->get();

        return new CollaborationAccessFacts($staff?->role, $grant?->role, $project === null, $portal->isNotEmpty());
    }

    /** @return Builder<ClientCollaborationTopic> */
    public function topics(Workspace $workspace, ClientCompany $company): Builder
    {
        $this->assertCompany($workspace, $company);

        return ClientCollaborationTopic::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id);
    }

    public function topic(Workspace $workspace, ClientCompany $company, string $id, bool $lock = false): ClientCollaborationTopic
    {
        $topic = $this->topics($workspace, $company)->where('public_id', $id)->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))->firstOrFail();
        $this->topicProject($workspace, $company, $topic);

        return $topic;
    }

    public function topicProject(Workspace $workspace, ClientCompany $company, ClientCollaborationTopic $topic): ?ClientProject
    {
        $this->assertCompany($workspace, $company);
        abort_unless($topic->workspace_id === $workspace->id && $topic->client_company_id === $company->id, 404);
        if ($topic->client_project_id === null) {
            return null;
        }
        if ($topic->relationLoaded('collaborationProject')) {
            /** @var ClientProject $project */
            $project = $topic->getRelation('collaborationProject');

            return $project;
        }
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->whereKey($topic->client_project_id)->firstOrFail();
        $project->setRelation('workspace', $workspace)->setRelation('clientCompany', $company);
        $topic->setRelation('collaborationProject', $project);

        return $project;
    }

    public function task(Workspace $workspace, ClientCompany $company, string $id, bool $lock = false): ClientTask
    {
        $this->assertCompany($workspace, $company);
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $id)
            ->whereHas('project', fn (Builder $query) => $query->where('workspace_id', $workspace->id)->where('client_company_id', $company->id))
            ->with(['project' => fn ($query) => $query->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)])
            ->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))->firstOrFail();
        $task->project->setRelation('workspace', $workspace)->setRelation('clientCompany', $company);

        return $task;
    }

    public function parent(Workspace $workspace, ClientCompany $company, string $type, string $id, bool $lock = false): ClientTask|ClientCollaborationTopic
    {
        return match ($type) {
            'task' => $this->task($workspace, $company, $id, $lock),
            'topic' => $this->topic($workspace, $company, $id, $lock),
            default => abort(404),
        };
    }

    public function comment(Workspace $workspace, ClientCompany $company, string $id, bool $lock = false): ClientComment
    {
        $this->assertCompany($workspace, $company);

        return ClientComment::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('public_id', $id)
            ->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))->firstOrFail();
    }

    public function commentParent(Workspace $workspace, ClientCompany $company, ClientComment $comment, bool $lock = false): ClientTask|ClientCollaborationTopic
    {
        abort_unless($comment->workspace_id === $workspace->id && $comment->client_company_id === $company->id, 404);
        abort_unless(($comment->client_task_id === null) !== ($comment->client_collaboration_topic_id === null), 404);
        $type = $comment->client_task_id !== null ? 'task' : 'topic';
        $query = $type === 'task' ? ClientTask::query() : ClientCollaborationTopic::query();
        $id = $query->where('workspace_id', $workspace->id)->whereKey($comment->client_task_id ?? $comment->client_collaboration_topic_id)->value('public_id');
        abort_unless(is_string($id), 404);
        $parent = $this->parent($workspace, $company, $type, $id, $lock);
        abort_unless($parent->client_project_id === $comment->client_project_id, 404);

        return $parent;
    }

    /** @return Builder<ClientComment> */
    public function comments(Workspace $workspace, ClientTask|ClientCollaborationTopic $parent): Builder
    {
        abort_unless($parent->workspace_id === $workspace->id, 404);

        return ClientComment::query()->where('workspace_id', $workspace->id)
            ->where('client_company_id', $parent instanceof ClientTask ? $parent->project->client_company_id : $parent->client_company_id)
            ->where('client_project_id', $parent->client_project_id)
            ->where($parent instanceof ClientTask ? 'client_task_id' : 'client_collaboration_topic_id', $parent->id)
            ->whereNull($parent instanceof ClientTask ? 'client_collaboration_topic_id' : 'client_task_id');
    }

    private function assertCompany(Workspace $workspace, ClientCompany $company): void
    {
        abort_unless((int) $company->workspace_id === $workspace->id, 404);
    }
}
