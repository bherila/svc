<?php

namespace App\Services\AgentApi\Operations;

use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationPolicy;
use Bherila\McpLaravelBridge\Capabilities\Principal;

/**
 * A manager-only operation can never succeed for someone who manages no
 * workspace, so it is not offered to them. Coarse discovery eligibility only:
 * the domain actions still decide each workspace and record.
 */
final class AgentOperationPolicy implements OperationPolicy
{
    public const string WORKSPACE_MANAGER = 'workspace_manager';

    public function __construct(private readonly AgentOperationCatalog $catalog) {}

    public function withhold(Principal $principal, Operation $operation): ?string
    {
        if (! $this->catalog->isManagerOnly($operation->id)) {
            return null;
        }

        return $principal instanceof AgentOperationPrincipal && $principal->managesWorkspace()
            ? null
            : self::WORKSPACE_MANAGER;
    }
}
