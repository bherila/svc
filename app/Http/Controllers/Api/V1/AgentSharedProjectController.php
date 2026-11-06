<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidAgentApiCursor;
use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\SharedProjects\SharedProjectAccess;
use App\Services\SharedProjects\SharedProjectCollaboration;
use App\Services\SharedProjects\SharedProjectContext;
use App\Services\SharedProjects\SharedProjectReadService;
use App\Support\AgentApi\AgentWriteCutover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The shared project REST operations: read the projects a workspace hosts or
 * takes part in, list a project's tasks and a task's comments, hand a task to
 * people across workspaces, and comment on it.
 *
 * Whoever calls, the project is resolved by {@see SharedProjectAccess}, so a
 * workspace that is neither host nor active participant gets 404. Granting,
 * revoking and leaving are not operations here by decision; they are browser
 * actions (SharedProjectController).
 */
final class AgentSharedProjectController extends Controller
{
    public function __construct(
        private readonly SharedProjectAccess $access,
        private readonly SharedProjectReadService $reads,
        private readonly SharedProjectCollaboration $collaboration,
        private readonly AgentMutationContextFactory $contexts,
        private readonly AgentMutationExecutor $mutations,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => $this->reads->index($this->actor($request), $workspace)]);
    }

    public function show(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json(['data' => $this->reads->project($this->context($actor, $workspace, $project), $actor)]);
    }

    public function tasks(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        $actor = $this->actor($request);
        $context = $this->context($actor, $workspace, $project);
        $query = $this->page($request);

        return response()->json($this->paged(fn (): array => $this->reads->tasks($context, $actor, $query['limit'], $query['cursor'])));
    }

    public function comments(Request $request, Workspace $workspace, string $project, string $task): JsonResponse
    {
        $actor = $this->actor($request);
        $context = $this->context($actor, $workspace, $project);
        $query = $this->page($request);
        $page = $this->paged(fn (): ?array => $this->reads->comments($context, $actor, $task, $query['limit'], $query['cursor']));
        abort_if($page === null, 404);

        return response()->json($page);
    }

    public function commentCreate(Request $request, Workspace $workspace, string $project, string $task): JsonResponse
    {
        abort_unless(AgentWriteCutover::collaboration(), 404);
        $mutation = $this->contexts->from($request);
        $input = $request->validate(['body' => ['required', 'string', 'max:50000']]);

        $ids = $this->mutations->run($mutation->user, $workspace, $mutation->oauthClientId, 'shared_projects.comments.create', $mutation->idempotencyKey,
            ['project_id' => $project, 'task_id' => $task, 'body' => $input['body']],
            fn (): array => [(string) $this->collaboration->comment($mutation->user, $workspace, $project, $task, $input['body'])['id']]);

        // Read back under the caller's standing now, so a replay after a revoke is a 404.
        $row = $this->reads->comment($this->context($mutation->user, $workspace, $project), $mutation->user, $task, $ids[0]);
        abort_if($row === null, 404);

        return response()->json(['data' => $row], 201);
    }

    public function assign(Request $request, Workspace $workspace, string $project, string $task): JsonResponse
    {
        abort_unless(AgentWriteCutover::collaboration(), 404);
        $mutation = $this->contexts->from($request);
        $input = $request->validate([
            'assignees' => ['present', 'array', 'max:'.SharedProjectCollaboration::ASSIGNEES],
            'assignees.*.user_id' => ['required', 'uuid'],
            'assignees.*.workspace_id' => ['required', 'uuid'],
        ]);

        $this->mutations->runIdempotentByPath($mutation->user, $workspace, $mutation->oauthClientId, 'shared_projects.tasks.assign',
            function () use ($mutation, $workspace, $project, $task, $input): array {
                $this->collaboration->assign($mutation->user, $workspace, $project, $task, $input['assignees']);

                return [$task];
            });

        $context = $this->context($mutation->user, $workspace, $project);
        $row = $this->reads->findTask($context, $mutation->user, $task);
        abort_if($row === null, 404);

        return response()->json(['data' => ['task_id' => $row->public_id, 'assignees' => $this->reads->taskAssignees($context, $row)]]);
    }

    private function context(User|AgentPrincipal $actor, Workspace $workspace, string $project): SharedProjectContext
    {
        $context = $this->access->resolve($actor, $workspace, $project);
        abort_if($context === null || ! $context->viewer->canViewProject(), 404);

        return $context;
    }

    private function actor(Request $request): User|AgentPrincipal
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return $actor;
    }

    /** @return array{limit: int, cursor: ?string} */
    private function page(Request $request): array
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:'.SharedProjectReadService::PAGE], 'cursor' => ['sometimes', 'nullable', 'string', 'max:2048']]);

        return ['limit' => (int) ($data['limit'] ?? SharedProjectReadService::PAGE), 'cursor' => $data['cursor'] ?? null];
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $read
     * @return T
     */
    private function paged(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (InvalidAgentApiCursor $invalid) {
            throw ValidationException::withMessages(['cursor' => $invalid->getMessage()]);
        }
    }
}
