#!/usr/bin/env bash
#
# Runner-side verification performed before the shared atomic action commits a
# release as healthy. It verifies the rendered home page, production OAuth redirect, protected
# resource discovery, authentication, operation scopes and MCP session
# isolation. Smoke credentials are short-lived, data-blind and always revoked.
set -euo pipefail

: "${DEPLOY_SSH_TARGET:?}"
: "${DEPLOY_PHP_BINARY:?}"
: "${DEPLOY_STABLE_DIR:?}"
: "${DEPLOY_SITE_URL:?}"
: "${DEPLOY_SOURCE_COMMIT:?}"
: "${DEPLOY_LIVE_COMMIT:?}"
: "${DEPLOY_LIVE_STATE:?}"

test "$DEPLOYMENT_MODE" = atomic
test "$DEPLOY_STABLE_DIR" = svc-laravel
test "$DEPLOY_SITE_URL" = https://svc.bherila.net
test "$DEPLOY_LIVE_COMMIT" = "$DEPLOY_SOURCE_COMMIT"
test "$DEPLOY_LIVE_STATE" = serving

# /up and API probes can succeed while stale cached view paths break Inertia.
# Do not follow redirects: the public welcome page itself must render HTML.
home_response=$(curl --silent --show-error --connect-timeout 5 --max-time 20 \
    --output /dev/null --write-out '%{http_code} %{content_type}' "$DEPLOY_SITE_URL/")
read -r home_status home_content_type <<<"$home_response"
test "$home_status" = 200 || { echo 'Public home page did not return HTTP 200.' >&2; exit 1; }
case "${home_content_type,,}" in
    text/html | text/html\;*) ;;
    *) echo 'Public home page did not render HTML.' >&2; exit 1 ;;
esac

redirect_url=$(curl --fail --silent --show-error --max-time 20 \
    --output /dev/null --write-out '%{redirect_url}' \
    "$DEPLOY_SITE_URL/oauth/redirect")
case $redirect_url in
    https://id.bherila.net/oauth/authorize\?*) ;;
    *) echo "OAuth redirect did not target the configured identity provider." >&2; exit 1 ;;
esac

remote_artisan="cd ~/$DEPLOY_STABLE_DIR && $DEPLOY_PHP_BINARY artisan svc:mcp:deploy-smoke-credentials"
cleanup() {
    status=$?
    trap - EXIT
    if ! ssh "$DEPLOY_SSH_TARGET" "$remote_artisan --revoke"; then
        echo "::error::Could not revoke the production MCP smoke credentials." >&2
        test "$status" -ne 0 || status=1
    fi
    exit "$status"
}
trap cleanup EXIT

# Arm cleanup before issuance: if the remote command creates a credential and
# then loses its SSH response, revocation still runs instead of relying on TTL.
credentials=$(ssh "$DEPLOY_SSH_TARGET" "$remote_artisan --ttl=15")

authorized=$(printf '%s' "$credentials" | jq -er '.authorized_token | select(type == "string" and length > 0)')
wrong_scope=$(printf '%s' "$credentials" | jq -er '.wrong_scope_token | select(type == "string" and length > 0)')
echo "::add-mask::$authorized"
echo "::add-mask::$wrong_scope"

MCP_SMOKE_URL="$DEPLOY_SITE_URL/api/v1/mcp" \
MCP_SMOKE_EXPECTED_RESOURCE="$DEPLOY_SITE_URL/api/v1" \
MCP_SMOKE_BEARER_TOKEN="$authorized" \
MCP_SMOKE_WRONG_SCOPE_BEARER_TOKEN="$wrong_scope" \
    node scripts/mcp-smoke.mjs
