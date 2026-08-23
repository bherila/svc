<?php

return [
    // Read tools may be enabled independently of the eventual authoritative
    // write-path cutover. Mutation tools must opt in explicitly.
    'writes_enabled' => (bool) env('AGENT_API_WRITES_ENABLED', false),
    'mcp_max_body_bytes' => (int) env('AGENT_API_MCP_MAX_BODY_BYTES', 262_144),
    'mcp_session_ttl_seconds' => (int) env('AGENT_API_MCP_SESSION_TTL_SECONDS', 1800),
    'mcp_allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_ORIGINS', '')),
    ))),
    'dynamic_client_retention_days' => (int) env('AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS', 30),
];
