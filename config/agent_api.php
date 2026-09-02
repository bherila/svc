<?php

return [
    // Broad workflow mutations remain off until the authoritative write-path cutover.
    'writes_enabled' => (bool) env('AGENT_API_WRITES_ENABLED', false),
    // This independent switch is authoritative so it remains an emergency cutoff
    // even after the broader workflow write surface is enabled.
    'time_entry_writes_enabled' => (bool) env('AGENT_API_TIME_ENTRY_WRITES_ENABLED', true),
    // Global MCP emergency stop and optional reviewed capability kill switches.
    'mcp_enabled' => (bool) env('AGENT_API_MCP_ENABLED', true),
    'mcp_feature_flags' => [],
    'mcp_rate_limits' => [
        'mcp-read' => 120,
        'mcp-write' => 20,
    ],
    'accept_legacy_cursors' => env('AGENT_API_ACCEPT_LEGACY_CURSORS', true),
    'mcp_max_body_bytes' => (int) env('AGENT_API_MCP_MAX_BODY_BYTES', 262_144),
    'mcp_session_ttl_seconds' => (int) env('AGENT_API_MCP_SESSION_TTL_SECONDS', 1800),
    'mcp_allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_ORIGINS', '')),
    ))),
    'dynamic_client_retention_days' => (int) env('AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS', 30),
];
