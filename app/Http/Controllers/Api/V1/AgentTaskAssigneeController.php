<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskAssigneeReadService;
use App\Services\Tasks\TaskMutationOrigin;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentTaskAssigneeController extends Controller
{
    public function index(Request $request, Workspace $workspace, string $project, TaskAssigneeReadService $reads): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return response()->json($reads->list($actor, $workspace, $project, $input['q'] ?? '', FirstPartySession::is($request) ? TaskMutationOrigin::Internal : TaskMutationOrigin::Agent));
    }
}
