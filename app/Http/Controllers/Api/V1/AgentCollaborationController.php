<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Collaboration\CollaborationTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentCollaborationController extends Controller
{
    public function read(Request $request, Workspace $workspace, string $client, CollaborationTransportService $collaboration): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);
        $topic = $this->parameter($request, 'topic');
        $parentType = $this->parameter($request, 'parentType');
        $parentPublicId = $this->parameter($request, 'parentPublicId');
        $operation = $request->routeIs('*.collaboration.topics.index') ? 'topics.list' : ($topic !== null ? 'topics.get' : 'comments.list');

        return response()->json($collaboration->read($actor, $workspace, $client, $operation, $topic ?? $parentPublicId, $parentType, $request->query(), $collaboration->agentAudience($actor, $workspace)));
    }

    public function write(Request $request, Workspace $workspace, string $client, CollaborationTransportService $collaboration, AgentMutationContextFactory $contexts): JsonResponse
    {
        $context = $contexts->from($request);
        $topic = $this->parameter($request, 'topic');
        $comment = $this->parameter($request, 'comment');
        $parentType = $this->parameter($request, 'parentType');
        $parentPublicId = $this->parameter($request, 'parentPublicId');
        $topicOperation = $request->routeIs('*.collaboration.topics.*');
        $operation = ($topicOperation ? 'topics.' : 'comments.').match ($request->method()) {
            'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
            default => abort(405),
        };
        $result = $collaboration->mutate($context->user, $workspace, $client, $context->oauthClientId, $context->idempotencyKey,
            $operation, $topic ?? $comment ?? $parentPublicId, $parentType, $request->all());

        return response()->json($result, $request->isMethod('POST') ? 201 : 200);
    }

    private function parameter(Request $request, string $name): ?string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : null;
    }
}
