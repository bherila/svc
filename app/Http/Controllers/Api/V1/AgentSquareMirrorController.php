<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Square\Mirror\SquareMirrorPresenter;
use App\Services\Square\Mirror\SquareMirrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP adapter for svc's read-only mirror of Square (#438). Authorization,
 * the workspace binding and the receipts live in {@see SquareMirrorService},
 * which the MCP tools reach through this same route.
 */
final class AgentSquareMirrorController extends Controller
{
    public function __construct(
        private readonly SquareMirrorService $mirror,
        private readonly SquareMirrorPresenter $presenter,
    ) {}

    public function sync(Request $request, Workspace $workspace, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $run = $this->mirror->sync($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey);

        return response()->json(['data' => $this->presenter->run($run)]);
    }
}
