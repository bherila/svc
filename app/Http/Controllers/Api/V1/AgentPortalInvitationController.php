<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccessMutationRefused;
use App\Exceptions\PortalInvitationFailed;
use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalInvitations;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPortalInvitationMutationAction;
use App\Services\AgentApi\AgentPortalInvitationReadService;
use App\Support\Access\AccessRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Portal invitations over REST and MCP (#430). Never returns the acceptance
 * link; managers copy it from the client's settings page.
 */
final class AgentPortalInvitationController extends Controller
{
    public function __construct(
        private readonly AgentPortalInvitationReadService $invitations,
        private readonly AgentPortalInvitationMutationAction $mutations,
        private readonly AgentMutationContextFactory $contexts,
    ) {}

    public function index(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        abort_unless(PortalInvitations::enabled(), 404);
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->invitations->list($actor, $workspace, $client, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function store(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client, null, AgentPortalInvitationMutationAction::CREATE, 201);
    }

    public function resend(Request $request, Workspace $workspace, string $client, string $invitation): JsonResponse
    {
        return $this->write($request, $workspace, $client, $invitation, AgentPortalInvitationMutationAction::RESEND, 200);
    }

    public function destroy(Request $request, Workspace $workspace, string $client, string $invitation): JsonResponse
    {
        return $this->write($request, $workspace, $client, $invitation, AgentPortalInvitationMutationAction::REVOKE, 200);
    }

    private function write(Request $request, Workspace $workspace, string $client, ?string $invitation, string $operation, int $status): JsonResponse
    {
        abort_unless(PortalInvitations::enabled(), 404);
        $context = $this->contexts->from($request);
        try {
            $data = $this->mutations->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $client, $invitation, $request->all());
        } catch (AccessMutationRefused $refused) {
            abort_if($refused->reason === AccessRefusal::NotAuthorized, 403, $refused->getMessage());
            abort_if($refused->reason === AccessRefusal::InvitationNotOpen, 409, $refused->getMessage());
            throw $refused;
        } catch (PortalInvitationFailed $failed) {
            if ($failed->status === 422) {
                throw ValidationException::withMessages($failed->errors);
            }
            abort($failed->status, $failed->getMessage(), $failed->headers());
        }

        return response()->json(['data' => $data], $status);
    }
}
