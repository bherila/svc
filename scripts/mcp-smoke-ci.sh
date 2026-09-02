#!/usr/bin/env bash

set -euo pipefail

mcp_smoke_credentials="$(php scripts/mcp-smoke-credentials.php)"
mcp_smoke_token="$(php -r '$credentials = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR); echo $credentials["token"];' "$mcp_smoke_credentials")"
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 > storage/logs/mcp-smoke-server.log 2>&1 &
mcp_smoke_server_pid=$!
cleanup() {
    kill "$mcp_smoke_server_pid" 2>/dev/null || true
    wait "$mcp_smoke_server_pid" 2>/dev/null || true
}
trap cleanup EXIT

for mcp_smoke_attempt in {1..30}; do
    if curl --silent --output /dev/null http://127.0.0.1:8088/api/v1/mcp; then
        break
    fi
    sleep 1
done

MCP_SMOKE_URL=http://127.0.0.1:8088/api/v1/mcp \
MCP_SMOKE_BEARER_TOKEN="$mcp_smoke_token" \
composer mcp:smoke
