#!/usr/bin/env bash
#
# Read-only diagnostic of the live SVC deployment against a reviewed, exact release identity.
# Run on the runner by .github/workflows/deployment-diagnostic.yml. It changes nothing on the
# host: no `svc:storage:status --write`, no OAuth or MCP credentials, no crontab write, no
# provisioning. Every check runs, and the run fails if any of them does.
#
#   1. The pinned shared action's finalized runtime audit: the selected release is the reviewed
#      release and commit, canonical runtime paths, writable runtime directories, serving state,
#      no deploy.lock, an empty durable transaction inventory, zero pending migrations and a
#      persistent database configuration and location.
#   2. Exactly one canonical SVC scheduler cron line (scripts/deploy/cron-audit.sh).
#   3. The rendered home page references the deployed manifest's hashed JS and CSS, and each is
#      served with the right MIME type (scripts/deploy/verify-hashed-assets.mjs).
#
# Environment:
#   DIAG_SSH_TARGET    SSH alias written by the pinned action's configure-ssh.sh
#   DIAG_RELEASE_ID    reviewed release id: <first 12 of the commit>-<run id>-<attempt>
#   DIAG_COMMIT        reviewed full 40-character lowercase source commit
#   DIAG_AUDIT_SCRIPT  the pinned action's scripts/operational-audit.sh
#
# Output is fixed labels only: never host output, configuration values, paths or crontab lines.
set -euo pipefail

stable_dir=svc-laravel
php=/opt/cpanel/ea-php85/root/usr/bin/php
memory=1G
persistent=storage
site_url=https://svc.bherila.net
manifest_limit=1048576

reject() {
    echo "::error::SVC deployment diagnostic: $1." >&2
    exit 2
}

target=${DIAG_SSH_TARGET:-}
release=${DIAG_RELEASE_ID:-}
commit=${DIAG_COMMIT:-}
audit=${DIAG_AUDIT_SCRIPT:-}
[[ $target =~ ^[A-Za-z0-9._-]+$ ]] || reject 'the SSH target is malformed'
[[ $commit =~ ^[0-9a-f]{40}$ ]] || reject 'the reviewed commit must be a full lowercase 40-character SHA'
[[ $release =~ ^([0-9a-f]{12})-[1-9][0-9]*-[1-9][0-9]*$ ]] || reject 'the reviewed release id is malformed'
[ "${BASH_REMATCH[1]}" = "${commit:0:12}" ] || reject 'the reviewed release id does not belong to the reviewed commit'
[ -f "$audit" ] || reject 'the pinned runtime audit is unavailable'

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
failures=0

remote() {
    timeout --signal=TERM --kill-after=5s 70s ssh "$target" "$@"
}

passed() {
    echo "PASS: $1."
}

failed() {
    echo "::error::FAIL: $1." >&2
    failures=$((failures + 1))
}

runtime_audit() {
    local status=0 expected
    expected="runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=finalized"
    remote "bash -s -- $(printf '%q ' "$stable_dir" "$php" "$memory" "$release" "$commit" "$persistent" finalized)" \
        <"$audit" >"$work/audit" 2>"$work/audit-error" || status=$?

    # The action's own failure labels are fixed key/root/type/reason words; nothing else is relayed.
    LC_ALL=C grep -E '^runtime-audit key=[a-z.]+ root=[a-z-]+ type=[a-z-]+ reason=[a-z-]+$' \
        "$work/audit-error" | head -n 5 >&2 || true

    if [ "$status" -ne 0 ]; then
        failed 'runtime audit (identity, paths, serving, lock, transactions, migrations, database)'
    elif [ "$(cat "$work/audit")" = 'runtime-audit generation=superseded' ]; then
        failed 'runtime audit: the live deployment is not the reviewed release'
    elif [ "$(wc -l <"$work/audit")" -ne 2 ] \
        || [ "$(sed -n 1p "$work/audit")" != "$expected" ] \
        || ! sed -n 2p "$work/audit" | LC_ALL=C grep -Eq '^operational-audit pending_migrations=0 '; then
        failed 'runtime audit output was not the exact finalized proof'
    else
        passed 'reviewed release and commit are selected and serving'
        passed 'canonical runtime paths and writable runtime directories'
        passed 'no deploy.lock and an empty durable transaction inventory'
        passed 'zero pending migrations'
        passed 'persistent database configuration and location'
    fi
}

cron_audit() {
    local status=0
    remote "bash -s -- $(printf '%q ' "$stable_dir" "$php")" <scripts/deploy/cron-audit.sh \
        >"$work/cron" 2>"$work/cron-error" || status=$?
    LC_ALL=C grep -E '^SVC scheduler cron: [A-Za-z0-9 ;,.-]+\.$' "$work/cron-error" | head -n 1 >&2 || true

    if [ "$status" -eq 0 ] && [ "$(cat "$work/cron")" = 'SVC scheduler cron: exactly one canonical line.' ]; then
        passed 'exactly one canonical SVC scheduler cron line'
    else
        failed 'SVC scheduler cron'
    fi
}

asset_audit() {
    local status=0
    # The deployed manifest is read, never printed: it must be a bounded regular file reached
    # through real directories only, so a symlink cannot substitute another release's build.
    remote "bash -s -- $(printf '%q ' "$stable_dir" "$manifest_limit")" >"$work/manifest.json" 2>/dev/null <<'REMOTE' || status=$?
set -euo pipefail
root="$HOME/$1"
for directory in "$root" "$root/public" "$root/public/build"; do
    [ -d "$directory" ] && [ ! -L "$directory" ] || exit 1
done
for manifest in "$root/public/build/manifest.json" "$root/public/build/.vite/manifest.json"; do
    [ -e "$manifest" ] || [ -L "$manifest" ] || continue
    [ -f "$manifest" ] && [ ! -L "$manifest" ] || exit 1
    [ "$(wc -c <"$manifest")" -le "$2" ] || exit 1
    exec head -c "$2" -- "$manifest"
done
exit 1
REMOTE

    if [ "$status" -ne 0 ] || [ ! -s "$work/manifest.json" ] || [ "$(wc -c <"$work/manifest.json")" -gt "$manifest_limit" ]; then
        failed 'the deployed build manifest could not be read safely'
        return
    fi

    status=0
    ASSET_SITE_URL="$site_url" ASSET_MANIFEST_PATH="$work/manifest.json" \
        node scripts/deploy/verify-hashed-assets.mjs || status=$?
    if [ "$status" -eq 0 ]; then
        passed 'the home page serves the deployed hashed JS and CSS with the right MIME types'
    else
        failed 'hashed asset audit'
    fi
}

runtime_audit
cron_audit
asset_audit

if [ "$failures" -ne 0 ]; then
    echo "::error::SVC deployment diagnostic failed $failures check(s)." >&2
    exit 1
fi
echo 'SVC deployment diagnostic passed for the reviewed release.'
