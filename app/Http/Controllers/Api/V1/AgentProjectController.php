<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentProjectMemberReadService;
use App\Services\AgentApi\AgentProjectMutationAction;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentProjectController extends Controller
{
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->mutate($request, $workspace, 'projects.create', null, 201);
    }

    public function update(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        return $this->mutate($request, $workspace, 'projects.update', $project);
    }

    public function archive(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        return $this->mutate($request, $workspace, 'projects.archive', $project);
    }

    public function updateMember(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        return $this->mutate($request, $workspace, 'projects.members.update', $project);
    }

    public function members(Request $request, Workspace $workspace, string $project, AgentProjectMemberReadService $members): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:2048']]);

        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal || $actor instanceof User, 401);

        return response()->json($members->list($actor, $workspace, $project, $request->integer('limit', 25), $request->query('cursor')));
    }

    private function mutate(Request $request, Workspace $workspace, string $operation, ?string $projectId, int $status = 200): JsonResponse
    {
        $context = app(AgentMutationContextFactory::class)->from($request);
        $project = app(AgentProjectMutationAction::class)->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $projectId, $request->all());

        return response()->json(['data' => app(AgentProjectPresenter::class)->present($workspace, $project, true)], $status);
    }
}
