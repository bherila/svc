<?php

namespace App\Services\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalInvitations;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Portal invitation writes for agents: the same {@see PortalInvitations}
 * boundary as the settings page, behind ordinary agent receipts.
 *
 * The stored response - what a replay returns - is the presented invitation,
 * which carries no link. A replay never asks the provider again.
 */
final class AgentPortalInvitationMutationAction
{
    public const string CREATE = 'clients.portal_invitations.create';

    public const string RESEND = 'clients.portal_invitations.resend';

    public const string REVOKE = 'clients.portal_invitations.revoke';

    public function __construct(
        private readonly PortalInvitations $invitations,
        private readonly WorkspaceAccess $access,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentPortalInvitationReadService $reads,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, string $companyId, ?string $invitationId, array $payload): array
    {
        $context = AccessMutationContext::user($actor, hash('sha256', implode('|', [$workspace->public_id, $actor->public_id, $clientId, $operation, $key])));

        $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['client_id' => $companyId, 'invitation_id' => $invitationId, 'body' => $payload], function () use ($actor, $workspace, $companyId, $invitationId, $operation, $payload, $context): array {
            $company = $this->reads->company($actor, $workspace, $companyId);
            $rules = ['confirm' => ['required', 'boolean', 'accepted']];
            if ($operation === self::CREATE) {
                $rules += [
                    'email' => ['required', 'string', 'email', 'max:255'],
                    'scope' => ['required', Rule::enum(PortalAccessScope::class)],
                    'projects' => ['array', 'max:500', Rule::requiredIf(($payload['scope'] ?? null) === 'projects'),
                        Rule::prohibitedIf(($payload['scope'] ?? null) === 'company' && ($payload['projects'] ?? []) !== [])],
                    'projects.*' => ['required', 'uuid', 'distinct'],
                ];
            }
            $fields = array_values(array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.')));
            Validator::make(['body' => $payload], ['body' => ['required', 'array:'.implode(',', $fields)]])->validate();
            $data = Validator::make($payload, $rules)->validate();
            abort_unless($data['confirm'] === true, 422, 'Explicit confirmation is required.');

            $invitation = match ($operation) {
                self::CREATE => $this->invitations->invite($context, $company, (string) $data['email'], PortalAccessScope::from((string) $data['scope']),
                    array_values(array_map(strval(...), $data['projects'] ?? []))),
                self::RESEND => $this->invitations->resend($context, $company, $this->reads->find($workspace, $company, (string) $invitationId)),
                self::REVOKE => $this->invitations->revoke($context, $company, $this->reads->find($workspace, $company, (string) $invitationId)),
                default => throw new \InvalidArgumentException('Unsupported portal invitation mutation.'),
            };

            return [$company->public_id, $invitation->public_id];
        }, function (array $ids) use ($actor, $workspace, $context): void {
            $this->access->assertMayManage($context, $workspace);
            $this->reads->company($actor, $workspace, $ids[0]);
        }, responseSnapshot: fn (array $ids): array => $this->reads->get($actor, $workspace, $ids[0], $ids[1]));

        $receipt = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->where('oauth_client_id', $clientId)->where('operation', $operation)->where('idempotency_key', $key)->firstOrFail();

        return $receipt->response_snapshot ?? throw new \LogicException('The portal invitation mutation has no stored response.');
    }
}
