#!/usr/bin/env bash

set -euo pipefail

mcp_smoke_credentials="$(php scripts/mcp-smoke-credentials.php)"
mcp_smoke_token="$(php -r '$credentials = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR); echo $credentials["token"];' "$mcp_smoke_credentials")"
mcp_smoke_workspace_id="$(php -r '$credentials = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR); echo $credentials["workspace_id"];' "$mcp_smoke_credentials")"
mcp_smoke_agreement_id="$(php -r '$credentials = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR); echo $credentials["agreement_id"];' "$mcp_smoke_credentials")"
mcp_smoke_wrong_scope_token="$(php -r '$credentials = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR); echo $credentials["wrong_scope_token"];' "$mcp_smoke_credentials")"
mcp_smoke_disabled_server_pid=""
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --host=127.0.0.1 --port=8088 > storage/logs/mcp-smoke-server.log 2>&1 &
mcp_smoke_server_pid=$!
cleanup() {
    mcp_smoke_status=$?
    if [ "$mcp_smoke_status" -ne 0 ]; then
        tail -n 200 storage/logs/mcp-smoke-server.log >&2 || true
        tail -n 200 storage/logs/mcp-smoke-disabled-server.log >&2 || true
    fi
    kill "$mcp_smoke_server_pid" 2>/dev/null || true
    wait "$mcp_smoke_server_pid" 2>/dev/null || true
    if [ -n "$mcp_smoke_disabled_server_pid" ]; then
        kill "$mcp_smoke_disabled_server_pid" 2>/dev/null || true
        wait "$mcp_smoke_disabled_server_pid" 2>/dev/null || true
    fi
    trap - EXIT
    exit "$mcp_smoke_status"
}
trap cleanup EXIT

for mcp_smoke_attempt in {1..30}; do
    if curl --silent --output /dev/null http://127.0.0.1:8088/api/v1/mcp; then
        break
    fi
    sleep 1
done

MCP_SMOKE_URL=http://localhost:8088/api/v1/mcp \
MCP_SMOKE_BEARER_TOKEN="$mcp_smoke_token" \
MCP_SMOKE_WRONG_SCOPE_BEARER_TOKEN="$mcp_smoke_wrong_scope_token" \
MCP_SMOKE_WORKSPACE_ID="$mcp_smoke_workspace_id" \
MCP_SMOKE_AGREEMENT_ID="$mcp_smoke_agreement_id" \
node scripts/mcp-smoke.mjs

AGENT_API_MCP_ENABLED=false PHP_CLI_SERVER_WORKERS=2 php artisan serve --no-reload --host=127.0.0.1 --port=8089 > storage/logs/mcp-smoke-disabled-server.log 2>&1 &
mcp_smoke_disabled_server_pid=$!

for mcp_smoke_attempt in {1..30}; do
    if curl --silent --output /dev/null http://127.0.0.1:8089/api/v1/mcp; then
        break
    fi
    sleep 1
done

MCP_SMOKE_URL=http://localhost:8089/api/v1/mcp \
MCP_SMOKE_BEARER_TOKEN="$mcp_smoke_token" \
MCP_SMOKE_EXPECT_DISABLED=1 \
node scripts/mcp-smoke.mjs
