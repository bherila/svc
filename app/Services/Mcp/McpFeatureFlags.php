<?php

namespace App\Services\Mcp;

/**
 * Configuration-backed MCP kill switches: the transport as a whole
 * (`agent_api.mcp_enabled`) and `agent_api.mcp_feature_flags`, keyed by a
 * capability group (`mcp.read`, `mcp.write.clients`...) or by a tool, resource
 * or prompt name. A key that is absent is on; only `true` counts as on.
 */
final class McpFeatureFlags
{
    /** The read-only tools' group. */
    public const string READ = 'mcp.read';

    /** The write tools' group. */
    public const string WRITE = 'mcp.write';

    public function transportEnabled(): bool
    {
        return (bool) config('agent_api.mcp_enabled', true);
    }

    public function switchedOn(string $key): bool
    {
        $flags = config('agent_api.mcp_feature_flags', []);

        return ! is_array($flags) || ($flags[$key] ?? true) === true;
    }

    /**
     * The decision for a capability reached from inside another one -
     * approval folded into time_entries.log must stop when the switch that
     * withdraws time_entries.approve is thrown.
     */
    public function enabledFor(string $name, string $group): bool
    {
        return $this->transportEnabled() && $this->switchedOn($group) && $this->switchedOn($name);
    }
}
