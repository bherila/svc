<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Mcp\McpFeatureFlags;
use Bherila\McpLaravelBridge\Capabilities\Availability;

/**
 * The two evaluations of the one operation registry.
 *
 * - {@see self::rest()} gates REST routes: write cutovers (which the signed-in
 *   website passes) and scopes. Which workspace and record a caller may touch
 *   stays with the controllers, so the coarse manager rule is not applied.
 * - {@see self::agents()} is what agents are offered - MCP discovery and the
 *   withheld tools in context.get: cutovers without the website bypass, the MCP
 *   switches, scopes and the workspace-manager rule.
 */
final class AgentAvailability
{
    public function __construct(
        private readonly AgentOperationCatalog $catalog,
        private readonly McpFeatureFlags $mcp,
    ) {}

    public function rest(): Availability
    {
        return new Availability($this->catalog->registry(), AgentDeploymentFlags::forRest());
    }

    public function agents(): Availability
    {
        return new Availability($this->catalog->registry(), $this->agentFlags(), new AgentOperationPolicy($this->catalog));
    }

    public function agentFlags(): AgentDeploymentFlags
    {
        return AgentDeploymentFlags::forAgents($this->mcp);
    }
}
