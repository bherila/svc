<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Operations\AgentAvailability;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentOperationPrincipal;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Withheld;
use Bherila\McpLaravelBridge\Capabilities\WithheldReason;
use Closure;

/**
 * The tools an agent is not offered, and why (#382): the same evaluation that
 * picks the MCP tool list, so context.get and tools/list cannot disagree.
 *
 * Reasons keep their published names: `deployment_disabled` for a cutover or
 * MCP switch, `scope_not_granted` with the first missing scope, `role` for the
 * workspace-manager rule.
 */
final class AgentWithheldTools
{
    public function __construct(
        private readonly AgentAvailability $availability,
        private readonly AgentOperationCatalog $catalog,
    ) {}

    /** @param Closure(string):bool $allowsScope
     * @return list<array{name:string,reason:string,scope?:string}> */
    public function for(Closure $allowsScope, bool $hasManagedWorkspace): array
    {
        $principal = new AgentOperationPrincipal($allowsScope, static fn (): bool => $hasManagedWorkspace);
        $report = $this->availability->agents()->evaluate($principal);
        $registry = $this->catalog->registry();
        $withheld = [];
        foreach ($report->withheld as $entry) {
            $operation = $registry->find($entry->operationId);
            if ($operation?->mcp === null || $operation->mcp->kind !== McpKind::Tool) {
                continue;
            }
            $withheld[] = ['name' => (string) $operation->mcpName(), ...self::reason($entry)];
        }
        usort($withheld, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $withheld;
    }

    /** @return array{reason: string, scope?: string} */
    private static function reason(Withheld $withheld): array
    {
        return match ($withheld->reason) {
            WithheldReason::DeploymentFlag, WithheldReason::DependsOn => ['reason' => 'deployment_disabled'],
            WithheldReason::MissingScope => ['reason' => 'scope_not_granted', 'scope' => explode(' ', strtr($withheld->detail, '|', ' '))[0]],
            WithheldReason::Unauthenticated => ['reason' => 'scope_not_granted'],
            WithheldReason::MissingPermission, WithheldReason::GroupNotGranted, WithheldReason::Policy => ['reason' => 'role'],
        };
    }
}
