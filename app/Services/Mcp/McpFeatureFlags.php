<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;

/** Configuration-backed global and per-capability MCP kill switches. */
final class McpFeatureFlags
{
    public function enabled(McpCapabilityDefinition $capability): bool
    {
        if (! (bool) config('agent_api.mcp_enabled', true)) {
            return false;
        }

        $flags = config('agent_api.mcp_feature_flags', []);
        if (! is_array($flags)) {
            return true;
        }

        $value = $flags[$capability->featureFlag] ?? $flags[$capability->name] ?? true;

        return $value === true;
    }
}
