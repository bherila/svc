<?php

return [
    // Broad workflow mutations remain off until the authoritative write-path cutover.
    'writes_enabled' => (bool) env('AGENT_API_WRITES_ENABLED', false),
    // This independent switch is authoritative so it remains an emergency cutoff
    // even after the broader workflow write surface is enabled.
    'time_entry_writes_enabled' => (bool) env('AGENT_API_TIME_ENTRY_WRITES_ENABLED', true),
    // Nested inside the cutover above rather than independent of it: invoice
    // writes require both. The boundary follows blast radius - a task is
    // recoverable bookkeeping, while issuing allocates time irreversibly and
    // sending puts a document in front of a paying client (#242).
    'payment_writes_enabled' => (bool) env('AGENT_API_PAYMENT_WRITES_ENABLED', false),

    'invoice_writes_enabled' => (bool) env('AGENT_API_INVOICE_WRITES_ENABLED', false),
    // Expense recording/editing requires this switch and the outer workflow switch.
    'expense_writes_enabled' => (bool) env('AGENT_API_EXPENSE_WRITES_ENABLED', false),
    // Client company and agreement writes require this switch and the outer workflow
    // switch. Nothing is ever deleted through them: "delete" is archive/terminate.
    'client_writes_enabled' => (bool) env('AGENT_API_CLIENT_WRITES_ENABLED', false),
    // Global MCP emergency stop and optional reviewed capability kill switches.
    'mcp_enabled' => (bool) env('AGENT_API_MCP_ENABLED', true),
    'mcp_feature_flags' => [],
    'mcp_rate_limits' => [
        'mcp-read' => 120,
        'mcp-write' => 20,
    ],
    'mcp_concurrency_limit' => (int) env('AGENT_API_MCP_CONCURRENCY_LIMIT', 4),
    'mcp_concurrency_lock_seconds' => (int) env('AGENT_API_MCP_CONCURRENCY_LOCK_SECONDS', 60),
    'accept_legacy_cursors' => env('AGENT_API_ACCEPT_LEGACY_CURSORS', true),
    'mcp_max_body_bytes' => (int) env('AGENT_API_MCP_MAX_BODY_BYTES', 262_144),
    'mcp_max_result_bytes' => (int) env('AGENT_API_MCP_MAX_RESULT_BYTES', 262_144),
    'mcp_max_response_body_bytes' => (int) env('AGENT_API_MCP_MAX_RESPONSE_BODY_BYTES', 1_048_576),
    'mcp_session_ttl_seconds' => (int) env('AGENT_API_MCP_SESSION_TTL_SECONDS', 1800),
    'mcp_allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_ORIGINS', '')),
    ))),
    'mcp_allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => trim($host),
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_HOSTS', '')),
    ))),
    'dynamic_client_retention_days' => (int) env('AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS', 30),
];
