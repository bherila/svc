#!/usr/bin/env bash
set -euo pipefail

repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

make_process() {
    local pid=$1 cwd=$2
    shift 2
    mkdir -p "$temporary/proc/$pid"
    printf 'Name:\tfixture\nUid:\t123\t123\t123\t123\n' >"$temporary/proc/$pid/status"
    ln -s "$cwd" "$temporary/proc/$pid/cwd"
    ln -s "$temporary/bin/php" "$temporary/proc/$pid/exe"
    printf '%s\0' "$@" >"$temporary/proc/$pid/cmdline"
}

home=$temporary/home
candidate=$home/.deployments/svc-laravel/releases/candidate
stable_release=$home/.deployments/svc-laravel/releases/old
mkdir -p "$candidate" "$stable_release" "$temporary/bin" "$temporary/proc" "$temporary/sibling"
touch "$candidate/artisan" "$stable_release/artisan" "$temporary/bin/php"
chmod +x "$temporary/bin/php"
ln -s .deployments/svc-laravel/releases/old "$home/svc-laravel"

run_quiesce() {
    HOME=$home DEPLOY_QUIESCE_PROC_ROOT=$temporary/proc DEPLOY_QUIESCE_UID=123 \
        DEPLOY_QUIESCE_TIMEOUT_SECONDS=0 DEPLOY_QUIESCE_INTERVAL_SECONDS=1 \
        bash "$repository/scripts/deploy/quiesce.sh" \
        .deployments/svc-laravel/releases/candidate "$temporary/bin/php" svc-laravel
}

run_quiesce >/dev/null || fail 'an empty process inventory was rejected'

make_process 100 "$temporary/sibling" php artisan queue:work
run_quiesce >/dev/null || fail 'a sibling application process was treated as SVC-owned'

for invocation in bare relative; do
    case $invocation in
        bare) artisan=artisan ;;
        relative) artisan=./artisan ;;
    esac
    make_process 200 "$stable_release" php "$artisan" queue:work
    if run_quiesce >/dev/null 2>&1; then
        fail "$invocation SVC Artisan invocation was accepted"
    fi
    rm -r "$temporary/proc/200"
done

# An absolute script path identifies SVC ownership even when a supervisor starts
# PHP from another working directory.
make_process 201 "$temporary/sibling" php "$stable_release/artisan" queue:work
if run_quiesce >/dev/null 2>&1; then
    fail 'absolute SVC Artisan path outside the app cwd was accepted'
fi

cat >"$temporary/bin/curl" <<'MOCK'
#!/usr/bin/env bash
case "${!#}" in
    https://svc.bherila.net/) printf '%s\n' "$FIXTURE_HOME_RESPONSE" ;;
    https://svc.bherila.net/oauth/redirect) printf '%s\n' 'https://id.bherila.net/oauth/authorize?client_id=fixture' ;;
    *) exit 1 ;;
esac
MOCK
cat >"$temporary/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$FIXTURE_SSH_LOG"
case "$*" in
    *--ttl=15*) printf '%s\n' '{"authorized_token":"fixture-authorized","wrong_scope_token":"fixture-wrong-scope"}' ;;
    *--revoke*) ;;
    *) exit 1 ;;
esac
MOCK
cat >"$temporary/bin/jq" <<'MOCK'
#!/usr/bin/env bash
while IFS= read -r line; do :; done
printf '%s\n' fixture-token
MOCK
cat >"$temporary/bin/node" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' smoke >>"$FIXTURE_NODE_LOG"
MOCK
chmod +x "$temporary/bin/"{curl,ssh,jq,node}

run_live_verify() {
    PATH="$temporary/bin:$PATH" \
        DEPLOYMENT_MODE=atomic DEPLOY_SSH_TARGET=fixture-host \
        DEPLOY_PHP_BINARY=/fixture/php DEPLOY_STABLE_DIR=svc-laravel \
        DEPLOY_SITE_URL=https://svc.bherila.net DEPLOY_SOURCE_COMMIT=fixture-commit \
        DEPLOY_LIVE_COMMIT=fixture-commit DEPLOY_LIVE_STATE=serving \
        FIXTURE_HOME_RESPONSE="$1" FIXTURE_SSH_LOG="$temporary/ssh.log" \
        FIXTURE_NODE_LOG="$temporary/node.log" \
        bash "$repository/scripts/deploy/verify-live.sh"
}

for response in '500 text/html' '302 text/html' '200 application/json'; do
    rm -f "$temporary/ssh.log" "$temporary/node.log"
    if run_live_verify "$response" >/dev/null 2>&1; then
        fail "broken home page response '$response' was accepted"
    fi
    [ ! -e "$temporary/ssh.log" ] || fail 'credentials were issued before home page verification passed'
    [ ! -e "$temporary/node.log" ] || fail 'API smoke hid a broken home page'
done

run_live_verify '200 text/html; charset=utf-8' >/dev/null || fail 'rendered HTML home page was rejected'
grep -Fq -- '--ttl=15' "$temporary/ssh.log" || fail 'successful home probe skipped credential issuance'
grep -Fq -- '--revoke' "$temporary/ssh.log" || fail 'successful verification skipped credential cleanup'
[ -f "$temporary/node.log" ] || fail 'successful home probe skipped MCP smoke'

echo 'Deployment hook tests passed.'
