<?php

namespace App\Services\Collaboration;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentWriteCutover;
use Illuminate\Support\Facades\Validator;

/** Transport adapters share canonical validation, actions, receipts and current replay authority. */
final class CollaborationTransportService
{
    public const array OPERATIONS = ['topics.list', 'topics.get', 'topics.create', 'topics.update', 'topics.delete', 'comments.list', 'comments.create', 'comments.update', 'comments.delete'];

    public function __construct(private readonly CollaborationRepository $records, private readonly CollaborationReadService $read,
        private readonly CollaborationAction $actions, private readonly AgentMutationExecutor $mutations,
        private readonly AgentAccess $agents, private readonly ProjectAccess $projects) {}

    public function agentAudience(User|AgentPrincipal $actor, Workspace $workspace): CollaborationAudience
    {
        if ($this->agents->isWorkspaceClient($actor, $workspace)) {
            return CollaborationAudience::Portal;
        }
        abort_unless($this->projects->workspaceRole($actor, $workspace) !== null, 403);

        return CollaborationAudience::Internal;
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function read(User|AgentPrincipal $actor, Workspace $workspace, string $companyId, string $operation, ?string $id, ?string $parentType, array $query, CollaborationAudience $audience, bool $includeFiles = false): array
    {
        $company = $this->records->company($workspace, $companyId);
        /** @var array{kind?:string|null,project_id?:string|null,page?:int,cursor?:string|null,limit?:int} $data */
        $data = Validator::make($query, ['kind' => ['nullable', 'in:note,discussion'], 'project_id' => ['nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'comments_page' => ['missing'], 'comment' => ['missing'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:30']])->validate();
        if ($operation === 'topics.list') {
            $result = $this->read->listing($actor, $workspace, $company, $audience, isset($data['kind']) ? TopicKind::from($data['kind']) : null, $data['project_id'] ?? null, (int) ($data['page'] ?? 1));

            return ['data' => array_map(function (array $row) use ($workspace, $company): array {
                $row['web_url'] = $row['href'];
                $row['href'] = route('agent-api.v1.collaboration.topics.show', [$workspace, $company->public_id, $row['id']], absolute: false);

                return $row;
            }, $result['topics']), 'next_href' => $this->pageUrl('topics.index', $workspace, $company, $result['next_href'], $data),
                'previous_href' => $this->pageUrl('topics.index', $workspace, $company, $result['previous_href'], $data)];
        }
        abort_unless(is_string($id), 422);
        if ($operation === 'topics.get') {
            $result = $this->read->detail($actor, $workspace, $company, $id, $audience, includeThread: false, includeFiles: $includeFiles, agentFiles: true);

            return ['data' => $this->content($result['topic'])];
        }
        abort_unless($operation === 'comments.list' && in_array($parentType, ['task', 'topic'], true), 422);
        $result = $this->read->commentPage($actor, $workspace, $company, $parentType, $id, $audience, (int) ($data['limit'] ?? 1), $data['cursor'] ?? null, includeFiles: $includeFiles, agentFiles: true);
        $cursor = $result['meta']['next_cursor'];

        return ['data' => array_map($this->content(...), $result['data']), 'parent_version' => $result['parent_version'], 'meta' => $result['meta'],
            'next_href' => $cursor === null ? null : route('agent-api.v1.collaboration.comments.index', ['workspace' => $workspace, 'client' => $company->public_id,
                'parentType' => $parentType, 'parentPublicId' => $id, 'limit' => $data['limit'] ?? 1, 'cursor' => $cursor], absolute: false),
            'previous_href' => null];
    }

    /** Agent requests derive their audience; no body field can choose the operator view.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutate(User $actor, Workspace $workspace, string $companyId, string $clientId, string $key, string $operation, ?string $id, ?string $parentType, array $payload): array
    {
        abort_unless(AgentWriteCutover::collaboration(), 404);
        abort_unless($this->agentAudience($actor, $workspace) === CollaborationAudience::Internal, 403);

        return $this->execute($actor, $workspace, $companyId, $clientId, $key, $operation, $id, $parentType, $payload, CollaborationOrigin::Agent);
    }

    /** Native CLI is independent of agent deployment flags but uses the same role checks and receipts.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mutateNative(User $actor, Workspace $workspace, string $companyId, string $key, string $operation, ?string $id, ?string $parentType, array $payload): array
    {
        return $this->execute($actor, $workspace, $companyId, 'svc-cli:collaboration', $key, $operation, $id, $parentType, $payload, CollaborationOrigin::Native);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function execute(User $actor, Workspace $workspace, string $companyId, string $clientId, string $key, string $operation, ?string $id, ?string $parentType, array $payload, CollaborationOrigin $origin): array
    {
        abort_unless(in_array($operation, ['topics.create', 'topics.update', 'topics.delete', 'comments.create', 'comments.update', 'comments.delete'], true), 422);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:255']])->validate();
        $company = $this->records->company($workspace, $companyId);
        $name = 'collaboration.'.$operation;
        $ids = $this->mutations->run($actor, $workspace, $clientId, $name, $key,
            ['company_id' => $companyId, 'id' => $id, 'parent_type' => $parentType, 'body' => $payload],
            function () use ($actor, $workspace, $company, $operation, $id, $parentType, $payload, $origin): array {
                $audience = CollaborationAudience::Internal;
                $result = match ($operation) {
                    'topics.create' => $this->actions->createTopic($actor, $workspace, $company, TopicDraft::fromInput($payload), $audience, $origin),
                    'topics.update' => $this->actions->updateTopic($actor, $workspace, $company, $this->id($id), ContentPatch::fromInput($payload, true), $audience, $origin),
                    'topics.delete' => $this->actions->deleteTopic($actor, $workspace, $company, $this->id($id), $this->deletionVersion($payload), $audience, $origin),
                    'comments.create' => $this->actions->createComment($actor, $workspace, $company, $this->parentType($parentType), $this->id($id), CommentDraft::fromInput($payload), $audience, $origin),
                    'comments.update' => $this->actions->updateComment($actor, $workspace, $company, $this->id($id), ContentPatch::fromInput($payload, false), $audience, $origin),
                    'comments.delete' => $this->actions->deleteComment($actor, $workspace, $company, $this->id($id), $this->deletionVersion($payload), $audience, $origin),
                };

                return [$result->public_id];
            }, function (array $ids) use ($actor, $workspace, $company, $name, $payload, $origin): void {
                abort_unless(count($ids) === 1, 404);
                $this->actions->assertReplayAllowed($actor, $workspace, $company, $name, $ids[0], $payload, $origin);
            });
        if (str_starts_with($operation, 'topics.')) {
            return ['data' => $this->content($this->read->detail($actor, $workspace, $company, $ids[0], CollaborationAudience::Internal, includeThread: false)['topic'])];
        }
        $comment = $this->records->comment($workspace, $company, $ids[0]);
        $parent = $this->records->commentParent($workspace, $company, $comment);
        $thread = $this->read->thread($actor, $workspace, $company, $comment->client_task_id === null ? 'topic' : 'task', $parent->public_id, CollaborationAudience::Internal, focusId: $comment->public_id, pageSize: 1);
        foreach ($thread['comments'] as $row) {
            if ($row['id'] === $comment->public_id) {
                return ['data' => $this->content($row), 'parent_version' => $thread['parent_version']];
            }
        }
        abort(404);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function content(array $row): array
    {
        $row['web_url'] = $row['href'];
        unset($row['href'], $row['update_href'], $row['visibility_update_href'], $row['delete_href']);

        return $row;
    }

    /** @param array<string, mixed> $query
     * @param  array<string, string>  $path
     */
    private function pageUrl(string $route, Workspace $workspace, ClientCompany $company, ?string $webUrl, array $query, array $path = []): ?string
    {
        if ($webUrl === null) {
            return null;
        }
        parse_str((string) parse_url($webUrl, PHP_URL_QUERY), $page);
        unset($query['comment']);

        return route('agent-api.v1.collaboration.'.$route, ['workspace' => $workspace, 'client' => $company->public_id, ...$path, ...$query, ...$page], absolute: false);
    }

    private function id(?string $id): string
    {
        Validator::make(['id' => $id], ['id' => ['required', 'uuid']])->validate();
        abort_unless(is_string($id), 422);

        return $id;
    }

    private function parentType(?string $type): string
    {
        abort_unless(in_array($type, ['task', 'topic'], true), 422);

        return $type;
    }

    /** @param array<string, mixed> $payload */
    private function deletionVersion(array $payload): string
    {
        /** @var array{expected_version:string,confirm:true} $data */
        $data = Validator::make($payload, ['expected_version' => ['required', 'string', 'size:64'], 'confirm' => ['required', 'boolean', 'in:1']])->validate();
        abort_unless(($payload['confirm'] ?? null) === true, 422, 'Explicit confirm true is required.');

        return $data['expected_version'];
    }
}
