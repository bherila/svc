<?php

namespace App\Services\Collaboration;

use App\Models\AgentPrincipal;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentPageQuery;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentSharingNativeView;
use App\Services\Files\AttachmentSharingTransport;
use App\Services\Files\CollaborationAttachmentPreview;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\CursorPage;

final class CollaborationReadService
{
    public function __construct(private readonly CollaborationRepository $records, private readonly CollaborationAccess $access,
        private readonly CollaborationAttachmentPreview $filePreviews, private readonly AttachmentSharingNativeView $nativeFiles,
        private readonly AttachmentSharingTransport $files) {}

    public function taskThreadHref(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ClientTask $task, CollaborationAudience $audience): ?string
    {
        return $this->access->canViewParent($actor, $workspace, $company, $task, $audience)
            ? $this->url('comments.index', $workspace, $company, $audience, ['parentType' => 'task', 'parentPublicId' => $task->public_id])
            : null;
    }

    /** @return array<string, mixed> */
    public function listing(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience, ?TopicKind $kind, ?string $projectId, int $page): array
    {
        $companyWide = $this->access->companyWide($actor, $workspace, $company, $audience);
        abort_unless(($audience === CollaborationAudience::Portal && $this->access->hasPortalMembership($actor, $workspace, $company)) || $companyWide || $this->access->visibleProjects($actor, $workspace, $company, $audience)->exists(), 403);
        $query = $this->access->visibleTopics($actor, $workspace, $company, $audience);
        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }
        if ($projectId !== null) {
            $project = $this->access->visibleProjects($actor, $workspace, $company, $audience)->where('public_id', $projectId)->firstOrFail();
            $query->where('client_project_id', $project->id);
        }
        $rows = $query->orderByDesc('id')->simplePaginate(30, ['*'], 'page', $page);
        $projects = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->whereIn('id', array_filter(array_map(fn (ClientCollaborationTopic $topic): ?int => $topic->client_project_id, $rows->items())))
            ->get()->keyBy('id');
        $params = ['kind' => $kind?->value, 'project_id' => $projectId];
        $createScopes = [];
        if ($audience === CollaborationAudience::Internal) {
            if ($companyWide) {
                $createScopes[] = ['id' => '', 'label' => 'Company-wide'];
            }
            foreach ($this->access->managedProjects($actor, $workspace, $company)->orderBy('name')->limit(100)->get() as $project) {
                $createScopes[] = ['id' => $project->public_id, 'label' => $project->name];
            }
        }

        return ['company' => ['id' => $company->public_id, 'name' => $company->name], 'audience' => $audience->value,
            'topics' => array_map(fn (ClientCollaborationTopic $topic): array => ['id' => $topic->public_id, 'title' => $topic->title, 'kind_label' => $topic->kind->label(),
                'scope_label' => $topic->client_project_id === null ? 'Company-wide' : $projects->get($topic->client_project_id)?->name,
                'author_name' => $topic->author_name, 'href' => $this->url('show', $workspace, $company, $audience, ['topic' => $topic->public_id])], $rows->items()),
            'filters' => $params, 'filter_href' => $this->url('index', $workspace, $company, $audience),
            'create_href' => $createScopes !== [] ? $this->url('store', $workspace, $company, $audience) : null,
            'create_scopes' => $createScopes,
            'next_href' => $rows->hasMorePages() ? $this->url('index', $workspace, $company, $audience, [...$params, 'page' => $page + 1]) : null,
            'previous_href' => $page > 1 ? $this->url('index', $workspace, $company, $audience, [...$params, 'page' => $page - 1]) : null];
    }

    /** @return array<string, mixed> */
    public function detail(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, string $id, CollaborationAudience $audience, int $page = 1, ?string $focusId = null, bool $includeThread = true, bool $includeFiles = false, bool $agentFiles = false): array
    {
        $topic = $this->records->topic($workspace, $company, $id);
        abort_unless($this->access->canViewParent($actor, $workspace, $company, $topic, $audience), 404);
        $project = $this->records->topicProject($workspace, $company, $topic);
        $publisher = $this->access->canPublish($actor, $workspace, $company, $project, $audience);
        $deleted = $topic->deleted_at !== null;
        $href = $this->url('show', $workspace, $company, $audience, ['topic' => $topic->public_id]);

        $result = ['company' => ['id' => $company->public_id, 'name' => $company->name], 'audience' => $audience->value,
            'topic' => ['id' => $topic->public_id, 'title' => $deleted ? 'Removed '.$topic->kind->label() : $topic->title, 'kind' => $topic->kind->value, 'kind_label' => $topic->kind->label(),
                'project_id' => $project?->public_id, 'scope_label' => $project === null ? 'Company-wide' : $project->name,
                'body' => $deleted ? null : $topic->body, 'author_name' => $topic->author_name, 'created_at' => $topic->created_at?->toISOString(),
                'edited_at' => $topic->edited_at?->toISOString(), 'deleted' => $deleted, 'version' => AgentApiVersion::for($topic),
                'is_visible_to_client' => $audience === CollaborationAudience::Internal ? $topic->is_visible_to_client : null,
                'effective_visible_to_client' => $audience === CollaborationAudience::Internal ? $this->access->effectiveVisible($workspace, $company, $topic) : null,
                'href' => $href,
                'update_href' => $this->access->canEditTopic($actor, $workspace, $company, $topic, $audience) ? $this->url('update', $workspace, $company, $audience, ['topic' => $topic->public_id]) : null,
                'visibility_update_href' => $publisher && ! $deleted ? $this->url('update', $workspace, $company, $audience, ['topic' => $topic->public_id]) : null,
                'delete_href' => $publisher && ! $deleted ? $this->url('destroy', $workspace, $company, $audience, ['topic' => $topic->public_id]) : null],
            'thread' => $deleted || ! $includeThread ? null : $this->thread($actor, $workspace, $company, 'topic', $id, $audience, $page, $focusId,
                includeFiles: $includeFiles, agentFiles: $agentFiles)];
        if ($includeFiles && ! $deleted) {
            $reference = new AttachmentParentReference('topic', $id, $company->public_id);
            if ($agentFiles) {
                $filePage = $this->files->listing($actor, $workspace, $reference, $this->fileAudience($audience), AttachmentPageQuery::fromInput(['limit' => 3]));
                $cursor = $filePage['meta']['next_cursor'];
                $result['topic']['attachments'] = ['data' => $filePage['data'], 'parent_version' => $filePage['parent_version'],
                    'next_href' => $cursor === null ? null : route('agent-api.v1.collaboration.attachments.index', ['workspace' => $workspace,
                        'client' => $company->public_id, 'recordType' => 'topic', 'recordPublicId' => $id, 'limit' => 3, 'cursor' => $cursor], absolute: false)];
            } else {
                abort_unless($actor instanceof User, 401);
                $result['topic']['attachment_panel'] = $this->nativeFiles->collaboration($actor, $workspace, $reference, $this->fileAudience($audience));
            }
        }

        return $result;
    }

    /** Shared task/topic thread; task pages may embed this DTO without extra route assumptions.
     * @return array<string, mixed>
     */
    public function thread(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, string $type, string $id, CollaborationAudience $audience, int $page = 1, ?string $focusId = null, int $pageSize = 30, bool $includeFiles = false, bool $agentFiles = false): array
    {
        abort_unless($pageSize >= 1 && $pageSize <= 30, 422);
        $parent = $this->records->parent($workspace, $company, $type, $id);
        abort_unless($this->access->canViewParent($actor, $workspace, $company, $parent, $audience), 404);
        abort_if($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null, 404);
        $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);
        $publisher = $this->access->canPublish($actor, $workspace, $company, $project, $audience);
        $canComment = $this->access->canComment($actor, $workspace, $company, $parent, $audience);
        $query = $this->records->comments($workspace, $parent);
        if ($audience === CollaborationAudience::Portal) {
            $query->where('is_visible_to_client', true);
        }
        if ($focusId !== null) {
            $focused = (clone $query)->where('public_id', $focusId)->firstOrFail();
            $page = intdiv((clone $query)->where('id', '<', $focused->id)->count(), $pageSize) + 1;
        }
        $rows = $query->orderBy('id')->simplePaginate($pageSize, ['*'], 'comments_page', $page);
        $href = $parent instanceof ClientCollaborationTopic
            ? $this->url('show', $workspace, $company, $audience, ['topic' => $id])
            : $this->url('comments.index', $workspace, $company, $audience, ['parentType' => $type, 'parentPublicId' => $id]);
        $effective = $this->access->effectiveVisible($workspace, $company, $parent);
        $previews = $includeFiles ? $this->filePreviews->comments($actor, $workspace, $company, $rows->items(),
            $this->fileAudience($audience), $publisher, $effective, $agentFiles) : [];

        return ['parent_kind' => $parent instanceof ClientTask ? 'task' : $parent->kind->value, 'parent_title' => $parent->title,
            'parent_version' => AgentApiVersion::for($parent), 'href' => $href,
            'store_href' => $canComment ? $this->url('comments.store', $workspace, $company, $audience, ['parentType' => $type, 'parentPublicId' => $id]) : null,
            'visibility_store_href' => $publisher && $canComment ? $this->url('comments.store', $workspace, $company, $audience, ['parentType' => $type, 'parentPublicId' => $id]) : null,
            'comments' => array_map(fn (ClientComment $comment): array => $this->withFiles(
                $this->presentComment($comment, $workspace, $company, $audience, $actor, $canComment, $publisher, $href, $effective),
                $previews[$comment->public_id] ?? null, $agentFiles), $rows->items()),
            'next_href' => $rows->hasMorePages() ? $href.'?comments_page='.($page + 1) : null,
            'previous_href' => $page > 1 ? $href.'?comments_page='.($page - 1) : null];
    }

    /** Authorized Agent/CLI comments in stable ascending order; byte bounds never skip unreturned rows.
     * @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string},parent_version:string}
     */
    public function commentPage(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, string $type, string $id, CollaborationAudience $audience, int $limit, ?string $cursor, bool $includeFiles = false, bool $agentFiles = false): array
    {
        abort_unless($limit >= 1 && $limit <= 30, 422);
        $parent = $this->records->parent($workspace, $company, $type, $id);
        abort_unless($this->access->canViewParent($actor, $workspace, $company, $parent, $audience), 404);
        abort_if($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null, 404);
        $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);
        $publisher = $this->access->canPublish($actor, $workspace, $company, $project, $audience);
        $canComment = $this->access->canComment($actor, $workspace, $company, $parent, $audience);
        $query = $this->records->comments($workspace, $parent);
        if ($audience === CollaborationAudience::Portal) {
            $query->where('is_visible_to_client', true);
        }
        $href = $parent instanceof ClientCollaborationTopic
            ? $this->url('show', $workspace, $company, $audience, ['topic' => $id])
            : $this->url('comments.index', $workspace, $company, $audience, ['parentType' => $type, 'parentPublicId' => $id]);
        $effective = $this->access->effectiveVisible($workspace, $company, $parent);
        $key = implode(':', ['collaboration.comments', $company->public_id, $type, $id, $actor->public_id, $audience->value, 'id-asc-tombstones-v1']);
        if ($includeFiles) {
            $key .= $agentFiles ? ':agent-files' : ':native-files';
        }
        $previews = [];
        $result = CursorPage::run($workspace, $key, $limit, $cursor,
            function (int $take, ?int $after) use ($query, $actor, $workspace, $company, $audience, $publisher, $effective, $includeFiles, $agentFiles, &$previews) {
                $rows = (clone $query)->when($after !== null, fn ($rows) => $rows->where('id', '>', $after))->orderBy('id')->limit($take)->get();
                if ($includeFiles) {
                    $previews = $this->filePreviews->comments($actor, $workspace, $company, $rows->all(), $this->fileAudience($audience), $publisher, $effective, $agentFiles);
                }

                return $rows;
            },
            function (ClientComment $comment) use ($workspace, $company, $audience, $actor, $canComment, $publisher, $href, $effective, $agentFiles, &$previews): array {
                return $this->withFiles($this->presentComment($comment, $workspace, $company, $audience, $actor, $canComment, $publisher, $href, $effective),
                    $previews[$comment->public_id] ?? null, $agentFiles);
            },
            maxDataBytes: AgentPageBudget::dataBytes(), allowLegacyCursor: false);

        return [...$result, 'parent_version' => AgentApiVersion::for($parent)];
    }

    private function fileAudience(CollaborationAudience $audience): AttachmentAudience
    {
        return $audience === CollaborationAudience::Portal ? AttachmentAudience::Portal : AttachmentAudience::Internal;
    }

    /** @param array<string,mixed> $content
     * @param array<string,mixed>|null $preview
     * @return array<string,mixed> */
    private function withFiles(array $content, ?array $preview, bool $agent): array
    {
        return $preview === null ? $content : [...$content, $agent ? 'attachments' : 'attachment_panel' => $preview];
    }

    /** @return array<string,mixed> */
    private function presentComment(ClientComment $comment, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience, User|AgentPrincipal $actor, bool $canComment, bool $publisher, string $href, bool $effective): array
    {
        $live = $comment->deleted_at === null;
        $author = $comment->author_user_id === $actor->id && $canComment;
        $params = ['comment' => $comment->public_id];

        return ['id' => $comment->public_id, 'body' => $live ? $comment->body : null, 'author_name' => $comment->author_name,
            'created_at' => $comment->created_at?->toISOString(), 'edited_at' => $comment->edited_at?->toISOString(), 'deleted' => ! $live,
            'version' => AgentApiVersion::for($comment), 'href' => $href.'?comment='.$comment->public_id.'#comment-'.$comment->public_id,
            'is_visible_to_client' => $audience === CollaborationAudience::Internal ? $comment->is_visible_to_client : null,
            'effective_visible_to_client' => $audience === CollaborationAudience::Internal ? $effective && $comment->is_visible_to_client && $live : null,
            'update_href' => $live && $author ? $this->url('comments.update', $workspace, $company, $audience, $params) : null,
            'visibility_update_href' => $live && $publisher ? $this->url('comments.update', $workspace, $company, $audience, $params) : null,
            'delete_href' => $live && ($author || $publisher) ? $this->url('comments.destroy', $workspace, $company, $audience, $params) : null];
    }

    /** @param array<string, mixed> $params */
    private function url(string $operation, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience, array $params = []): string
    {
        return route(($audience === CollaborationAudience::Portal ? 'portal.' : '').'collaboration.'.$operation,
            [...($audience === CollaborationAudience::Internal ? ['workspace' => $workspace] : []), 'clientCompany' => $company, ...$params], absolute: false);
    }
}
