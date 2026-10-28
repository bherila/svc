<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationTransportService;
use App\Support\AgentApi\FirstPartySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentCollaborationController extends Controller
{
    public function __construct(private readonly CollaborationTransportService $collaboration, private readonly AgentMutationContextFactory $contexts) {}

    public function topicsList(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->read($request, $workspace, $client);
    }

    public function topicsGet(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->read($request, $workspace, $client);
    }

    public function topicsCreate(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    public function topicsUpdate(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    public function topicsDelete(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    public function commentsList(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->read($request, $workspace, $client);
    }

    public function commentsCreate(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    public function commentsUpdate(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    public function commentsDelete(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client);
    }

    private function read(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);
        $topic = $this->parameter($request, 'topic');
        $parentType = $this->parameter($request, 'parentType');
        $parentPublicId = $this->parameter($request, 'parentPublicId');
        $operation = $request->routeIs('*.collaboration.topics.index') ? 'topics.list' : ($topic !== null ? 'topics.get' : 'comments.list');

        return response()->json($this->collaboration->read($actor, $workspace, $client, $operation, $topic ?? $parentPublicId, $parentType, $request->query(), FirstPartySession::is($request) ? CollaborationAudience::Internal : $this->collaboration->agentAudience($actor, $workspace),
            includeFiles: $actor->tokenCan('files:read') && $actor->tokenCan('collaboration:read')));
    }

    private function write(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $topic = $this->parameter($request, 'topic');
        $comment = $this->parameter($request, 'comment');
        $parentType = $this->parameter($request, 'parentType');
        $parentPublicId = $this->parameter($request, 'parentPublicId');
        $topicOperation = $request->routeIs('*.collaboration.topics.*');
        $operation = ($topicOperation ? 'topics.' : 'comments.').match ($request->method()) {
            'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
            default => abort(405),
        };
        $method = FirstPartySession::is($request) ? 'mutateInternal' : 'mutate';
        $result = $this->collaboration->{$method}($context->user, $workspace, $client, $context->oauthClientId, $context->idempotencyKey,
            $operation, $topic ?? $comment ?? $parentPublicId, $parentType, $request->all());

        return response()->json($result, $request->isMethod('POST') ? 201 : 200);
    }

    private function parameter(Request $request, string $name): ?string
    {
        $value = $request->route($name);

        return is_string($value) ? $value : null;
    }
}
