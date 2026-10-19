<?php

namespace App\Services\AgentApi;

use App\Models\AgentMutationReceipt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalAccessOperation;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;

/**
 * Portal access writes over `/api/v1`, for the settings page, REST and MCP
 * alike: the shared {@see PortalAccessOperation} behind ordinary agent
 * receipts.
 */
final class AgentPortalAccessMutationAction
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly PortalAccessOperation $operation,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentPortalContactReadService $contacts,
    ) {}

    /**
     * @param  AccessMutationContext  $context  the operation and request ids every access audit carries
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(AccessMutationContext $context, User $actor, Workspace $workspace, string $clientId, string $key, string $operation, string $companyId, ?string $userId, array $payload): array
    {
        $this->mutations->run($actor, $workspace, $clientId, $operation, $key, ['client_id' => $companyId, 'user_id' => $userId, 'body' => $payload], function () use ($actor, $workspace, $companyId, $userId, $operation, $payload, $context): array {
            $company = $this->contacts->company($actor, $workspace, $companyId);
            $person = $this->operation->perform($context, $company, $operation, $userId, $payload);

            return [$company->public_id, $person->public_id];
        }, function (array $ids) use ($actor, $workspace, $context): void {
            $this->access->assertMayManage($context, $workspace);
            $this->contacts->company($actor, $workspace, $ids[0]);
        }, responseSnapshot: fn (array $ids): array => $operation === PortalAccessOperation::REVOKE
            ? ['client_id' => $ids[0], 'user_id' => $ids[1], 'revoked' => true]
            : $this->contacts->get($actor, $workspace, $ids[0], $ids[1]));

        $receipt = AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->where('oauth_client_id', $clientId)->where('operation', $operation)->where('idempotency_key', $key)->firstOrFail();

        return $receipt->response_snapshot ?? throw new \LogicException('The portal mutation has no stored response.');
    }
}
