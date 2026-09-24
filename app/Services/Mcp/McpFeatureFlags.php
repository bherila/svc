<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;

/** Configuration-backed global and per-capability MCP kill switches. */
final class McpFeatureFlags
{
    public function enabled(McpCapabilityDefinition $capability): bool
    {
        return $this->enabledFor($capability->name, $capability->featureFlag);
    }

    /**
     * The same decision for a capability reached from inside another one -
     * approval folded into time_entries.log must stop when the switch that
     * withdraws time_entries.approve is thrown.
     */
    public function enabledFor(string $name, string $featureFlag): bool
    {
        if (! (bool) config('agent_api.mcp_enabled', true)) {
            return false;
        }

        $flags = config('agent_api.mcp_feature_flags', []);
        if (! is_array($flags)) {
            return true;
        }

        $value = $flags[$featureFlag] ?? $flags[$name] ?? true;

        return $value === true;
    }
}
