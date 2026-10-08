<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityKind;
use App\Services\Mcp\Registry\McpCapabilityRegistry;
use Closure;

/** Uses the same registry, deployment gates and discovery role rule as tools/list. */
final class AgentWithheldTools
{
    public function __construct(private readonly AgentMcpCapabilityRegistryFactory $factory, private readonly McpFeatureFlags $flags) {}

    /** @param Closure(string):bool $allowsScope
     * @return list<array{name:string,reason:string,scope?:string}> */
    public function for(Closure $allowsScope, bool $hasManagedWorkspace): array
    {
        $enabled = array_fill_keys(array_map(fn ($definition): string => $definition->name,
            $this->registry(false)->ofKind(McpCapabilityKind::Tool)), true);
        $all = $this->registry(true)->ofKind(McpCapabilityKind::Tool);
        $withheld = [];
        foreach ($all as $definition) {
            if (! isset($enabled[$definition->name]) || ! $this->flags->enabled($definition)) {
                $withheld[] = ['name' => $definition->name, 'reason' => 'deployment_disabled'];

                continue;
            }
            $missing = array_values(array_filter($definition->requiredScopes, fn (string $scope): bool => ! $allowsScope($scope)));
            if ($missing !== []) {
                $withheld[] = ['name' => $definition->name, 'reason' => 'scope_not_granted', 'scope' => $missing[0]];
            } elseif ($definition->policyAbility === 'AgentAccess::isWorkspaceManager' && ! $hasManagedWorkspace) {
                $withheld[] = ['name' => $definition->name, 'reason' => 'role'];
            }
        }
        usort($withheld, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $withheld;
    }

    private function registry(bool $includeDisabled): McpCapabilityRegistry
    {
        return $this->factory->make(app(AgentMcpReadTools::class), app(AgentMcpContextResource::class),
            app(AgentMcpAgreementTools::class), app(AgentMcpAgreementResource::class),
            app(AgentMcpBillingScheduleTools::class), app(AgentMcpCapacityLedgerTools::class),
            app(AgentMcpBillingAuditTools::class), app(AgentMcpPrompts::class),
            app(AgentMcpWriteTools::class), app(AgentMcpClientTools::class), app(AgentMcpClientWriteTools::class), $includeDisabled);
    }
}
