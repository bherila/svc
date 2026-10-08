<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\AgentApi\AgentClientMutationAction;
use App\Services\AgentApi\AgentClientReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REST adapter for the shared client and agreement workflows used by the web and MCP. */
final class AgentClientController extends Controller
{
    public function __construct(
        private readonly AgentClientReadService $clients,
        private readonly AgentAgreementReadService $agreements,
        private readonly AgentClientMutationAction $mutations,
        private readonly AgentMutationContextFactory $contexts,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,archived'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->clients->list($this->principal($request), $workspace, $data['status'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return response()->json(['data' => $this->clients->get($this->principal($request), $workspace, $client)]);
    }

    public function agreements(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:draft,active,terminated,expired,paused'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->agreements->list($this->principal($request), $workspace, $data['status'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function agreement(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        return response()->json(['data' => $this->agreements->get($this->principal($request), $workspace, $agreement)]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $context = $this->contexts->from($request);
        $id = $this->mutations->createClient($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $request->all());

        return response()->json(['data' => $this->clients->get($context->user, $workspace, $id)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $id = $this->mutations->updateClient($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $client, $request->all());

        return response()->json(['data' => $this->clients->get($context->user, $workspace, $id)]);
    }

    public function archive(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->setActive($request, $workspace, $client, false);
    }

    public function restore(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->setActive($request, $workspace, $client, true);
    }

    private function setActive(Request $request, Workspace $workspace, string $client, bool $active): JsonResponse
    {
        $context = $this->contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']]);
        $id = $this->mutations->setClientActive($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $client, $active, $data['expected_version']);

        return response()->json(['data' => $this->clients->get($context->user, $workspace, $id)]);
    }

    public function storeAgreement(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $context = $this->contexts->from($request);
        $id = $this->mutations->createAgreement($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $client, $request->all());

        return response()->json(['data' => $this->agreements->get($context->user, $workspace, $id)], 201);
    }

    public function updateAgreement(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        $context = $this->contexts->from($request);
        $id = $this->mutations->updateAgreement($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $agreement, $request->all());

        return response()->json(['data' => $this->agreements->get($context->user, $workspace, $id)]);
    }

    public function activate(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        $context = $this->contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'confirm' => ['required', 'boolean', 'accepted']]);
        abort_unless($request->input('confirm') === true, 422, 'Confirm must be true.');
        $id = $this->mutations->activateAgreement($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $agreement, $data['expected_version']);

        return response()->json(['data' => $this->agreements->get($context->user, $workspace, $id)]);
    }

    public function terminate(Request $request, Workspace $workspace, string $agreement): JsonResponse
    {
        $context = $this->contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'ends_on' => ['nullable', 'date_format:Y-m-d'], 'confirm' => ['required', 'boolean', 'accepted']]);
        abort_unless($request->input('confirm') === true, 422, 'Confirm must be true.');
        $id = $this->mutations->terminateAgreement($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $agreement, $data['ends_on'] ?? null, $data['expected_version']);

        return response()->json(['data' => $this->agreements->get($context->user, $workspace, $id)]);
    }

    private function principal(Request $request): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        return $principal;
    }
}
