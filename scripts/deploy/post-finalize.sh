#!/usr/bin/env bash
#
# Runner-side, read-only diagnostic the shared action runs after it has finalized and unlocked a
# healthy atomic release (post-finalize-script). The action's finalized runtime audit already
# proves identity, canonical paths, serving state, no deploy.lock, an empty transaction inventory,
# zero pending migrations and a persistent database. This adds the one SVC-specific assertion it
# leaves to the application: exactly one canonical scheduler cron line. It must stay read-only and
# safe to repeat; the action retries it once.
set -euo pipefail

: "${DEPLOY_SSH_TARGET:?}"
: "${DEPLOY_PHP_BINARY:?}"
: "${DEPLOY_STABLE_DIR:?}"
: "${DEPLOY_SOURCE_COMMIT:?}"
: "${DEPLOY_LIVE_COMMIT:?}"
: "${DEPLOY_LIVE_STATE:?}"

test "$DEPLOYMENT_MODE" = atomic
test "$DEPLOY_STABLE_DIR" = svc-laravel
test "$DEPLOY_LIVE_COMMIT" = "$DEPLOY_SOURCE_COMMIT"
test "$DEPLOY_LIVE_STATE" = serving

# shellcheck disable=SC2029 # The arguments expand on the runner and are escaped for remote Bash.
ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' "$DEPLOY_STABLE_DIR" "$DEPLOY_PHP_BINARY")" \
    <scripts/deploy/cron-audit.sh
