<?php

return [
    // Broad workflow mutations remain off until the authoritative write-path cutover.
    'writes_enabled' => (bool) env('AGENT_API_WRITES_ENABLED', false),
    // Time-entry drafts are a narrower, independently deployable write surface.
    // The global flag above still enables these operations as part of a full cutover.
    'time_entry_writes_enabled' => (bool) env('AGENT_API_TIME_ENTRY_WRITES_ENABLED', true),
    'mcp_max_body_bytes' => (int) env('AGENT_API_MCP_MAX_BODY_BYTES', 262_144),
    'mcp_session_ttl_seconds' => (int) env('AGENT_API_MCP_SESSION_TTL_SECONDS', 1800),
    'mcp_allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_ORIGINS', '')),
    ))),
    'dynamic_client_retention_days' => (int) env('AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS', 30),
];
