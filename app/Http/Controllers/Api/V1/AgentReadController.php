<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentReadService;
use App\Services\Authorization\AgentTokenScopes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HTTP adapter for the shared, tenant-scoped Agent read workflows. */
final class AgentReadController extends Controller
{
    public function __construct(
        private readonly AgentReadService $reads,
        private readonly AgentTokenScopes $scopes,
    ) {}

    public function context(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reads->context($this->user($request), $this->scope($request))]);
    }

    public function summary(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => $this->reads->summary($this->user($request), $workspace, $this->scope($request))]);
    }

    public function projects(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->projects(
            $this->user($request),
            $workspace,
            $this->limit($request),
            $request->query('cursor'),
            $request->string('status')->toString(),
            $request->string('query')->toString(),
        ));
    }

    public function project(Request $request, Workspace $workspace, string $project): JsonResponse
    {
        return response()->json(['data' => $this->reads->project(
            $this->user($request),
            $workspace,
            $project,
            $this->scopes->allows($request, 'tasks:read'),
        )]);
    }

    public function tasks(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->tasks(
            $this->user($request),
            $workspace,
            $request->string('project_id')->toString(),
            $this->limit($request),
            $request->query('cursor'),
        ));
    }

    public function task(Request $request, Workspace $workspace, string $task): JsonResponse
    {
        return response()->json(['data' => $this->reads->task($this->user($request), $workspace, $task)]);
    }

    public function timeEntries(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->timeEntries(
            $this->user($request),
            $workspace,
            $request->string('project_id')->toString(),
            $request->string('status')->toString(),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
            $this->limit($request),
            $request->query('cursor'),
        ));
    }

    public function invoices(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json($this->reads->invoices(
            $this->user($request),
            $workspace,
            $request->string('status')->toString(),
            $this->limit($request),
            $request->query('cursor'),
        ));
    }

    public function invoice(Request $request, Workspace $workspace, string $invoice): JsonResponse
    {
        return response()->json(['data' => $this->reads->invoice($this->user($request), $workspace, $invoice)]);
    }

    private function user(Request $request): User|AgentPrincipal
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return $user;
    }

    /** @return Closure(string): bool */
    private function scope(Request $request): Closure
    {
        return fn (string $scope): bool => $this->scopes->allows($request, $scope);
    }

    private function limit(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('limit', 25)));
    }
}
