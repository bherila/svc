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
# `bash -s --` scripts fed over SSH run locally against the fixture host's home and crontab.
cat >"$temporary/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$FIXTURE_SSH_LOG"
case "$2" in
    'bash -s -- '*) HOME=$FIXTURE_REMOTE_HOME exec bash -c "$2" ;;
esac
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
case "$*" in
    *verify-hashed-assets.mjs*)
        printf '%s\n' assets >>"$FIXTURE_NODE_LOG"
        [ -z "${FIXTURE_ASSET_FAIL:-}" ] || exit 1
        if [ -n "${ASSET_MANIFEST_PATH:-}" ]; then
            grep -Fq fixture-manifest "$ASSET_MANIFEST_PATH" || exit 1
        fi
        ;;
    # Drain the piped redirect: an unread pipe can fail the writer under pipefail.
    *verify-oauth-redirect.mjs*) cat >/dev/null; printf '%s\n' redirect >>"$FIXTURE_NODE_LOG" ;;
    *) printf '%s\n' smoke >>"$FIXTURE_NODE_LOG" ;;
esac
MOCK
cat >"$temporary/bin/crontab" <<'MOCK'
#!/usr/bin/env bash
[ "${1:-}" = -l ] || { printf 'write\n' >>"$FIXTURE_CRONTAB_WRITES"; exit 97; }
[ -f "$FIXTURE_CRONTAB" ] || { echo 'no crontab for fixture' >&2; exit 1; }
cat "$FIXTURE_CRONTAB"
MOCK
chmod +x "$temporary/bin/"{curl,ssh,jq,node,crontab}

remote_home=$temporary/remote-home
crontab_file=$temporary/crontab
# shellcheck disable=SC2016 # $HOME is literal in a crontab.
canonical_cron='* * * * * cd "$HOME/svc-laravel" && /fixture/php -d memory_limit=1G artisan schedule:run > /dev/null 2>&1 # JOB:svc-laravel-scheduler'
mkdir -p "$remote_home/svc-laravel/public/build"
printf '%s\n' '{"fixture-manifest":true}' >"$remote_home/svc-laravel/public/build/manifest.json"
export FIXTURE_REMOTE_HOME=$remote_home FIXTURE_CRONTAB=$crontab_file FIXTURE_CRONTAB_WRITES=$temporary/crontab-writes
printf '%s\n' "$canonical_cron" >"$crontab_file"

run_live_verify() {
    PATH="$temporary/bin:$PATH" \
        DEPLOYMENT_MODE=atomic DEPLOY_SSH_TARGET=fixture-host \
        DEPLOY_PHP_BINARY=/fixture/php DEPLOY_STABLE_DIR=svc-laravel \
        DEPLOY_SITE_URL=https://svc.bherila.net DEPLOY_SOURCE_COMMIT=fixture-commit \
        DEPLOY_LIVE_COMMIT=fixture-commit DEPLOY_LIVE_STATE=serving \
        FIXTURE_HOME_RESPONSE="$1" FIXTURE_SSH_LOG="$temporary/ssh.log" \
        FIXTURE_NODE_LOG="$temporary/node.log" \
        bash -c 'cd "$1" && bash scripts/deploy/verify-live.sh' _ "$repository"
}

for response in '500 text/html' '302 text/html' '200 application/json'; do
    rm -f "$temporary/ssh.log" "$temporary/node.log"
    if run_live_verify "$response" >/dev/null 2>&1; then
        fail "broken home page response '$response' was accepted"
    fi
    [ ! -e "$temporary/ssh.log" ] || fail 'credentials were issued before home page verification passed'
    [ ! -e "$temporary/node.log" ] || fail 'API smoke hid a broken home page'
done

# A page that renders but whose hashed assets are missing, mistyped or stale must stop the
# deployment before any credential is issued.
rm -f "$temporary/ssh.log" "$temporary/node.log"
if FIXTURE_ASSET_FAIL=1 run_live_verify '200 text/html' >/dev/null 2>&1; then
    fail 'a failing hashed asset audit was accepted'
fi
[ ! -e "$temporary/ssh.log" ] || fail 'credentials were issued after the hashed asset audit failed'
grep -qx assets "$temporary/node.log" || fail 'the hashed asset audit did not run'
grep -qx smoke "$temporary/node.log" && fail 'API smoke ran after the hashed asset audit failed'

run_live_verify '200 text/html; charset=utf-8' >/dev/null || fail 'rendered HTML home page was rejected'
grep -Fq -- '--ttl=15' "$temporary/ssh.log" || fail 'successful home probe skipped credential issuance'
grep -Fq -- '--revoke' "$temporary/ssh.log" || fail 'successful verification skipped credential cleanup'
[ -f "$temporary/node.log" ] || fail 'successful home probe skipped MCP smoke'
grep -Fq 'bash -s -- svc-laravel /fixture/php' "$temporary/ssh.log" || fail 'verification skipped the cron audit'

# The scheduler is the only background runner: no line, a duplicate or a legacy line must stop the
# deployment before it is healthy and before any credential is issued.
for cron in '' "$canonical_cron"$'\n'"$canonical_cron" \
    "$canonical_cron"$'\n''* * * * * cd ~/svc-laravel && php artisan schedule:run'; do
    printf '%s\n' "$cron" >"$crontab_file"
    rm -f "$temporary/ssh.log" "$temporary/node.log"
    if run_live_verify '200 text/html' >"$temporary/output" 2>&1; then
        fail 'verification accepted a crontab without exactly one canonical scheduler line'
    fi
    grep -Fq 'bash -s -- svc-laravel /fixture/php' "$temporary/ssh.log" || fail 'verification failed before the cron audit ran'
    grep -Fq -- '--ttl=15' "$temporary/ssh.log" && fail 'credentials were issued after the cron audit failed'
    grep -qx smoke "$temporary/node.log" && fail 'API smoke ran after the cron audit failed'
    grep -Fq 'artisan' "$temporary/output" && fail 'cron audit failure relayed a crontab line'
done
[ ! -e "$temporary/crontab-writes" ] || fail 'verification wrote the crontab'
printf '%s\n' "$canonical_cron" >"$crontab_file"

# Post-finalizer diagnostic: read-only cron proof for the exact finalized, serving release.
run_post_finalize() {
    PATH="$temporary/bin:$PATH" \
        DEPLOYMENT_MODE=atomic DEPLOY_SSH_TARGET=fixture-host \
        DEPLOY_PHP_BINARY=/fixture/php DEPLOY_STABLE_DIR=svc-laravel \
        DEPLOY_SOURCE_COMMIT=fixture-commit DEPLOY_LIVE_COMMIT="${1:-fixture-commit}" \
        DEPLOY_LIVE_STATE="${2:-serving}" FIXTURE_SSH_LOG="$temporary/ssh.log" \
        bash -c 'cd "$1" && bash scripts/deploy/post-finalize.sh' _ "$repository"
}
run_post_finalize >/dev/null || fail 'post-finalizer rejected the canonical cron line'
run_post_finalize other-commit >/dev/null 2>&1 && fail 'post-finalizer accepted identity drift'
run_post_finalize fixture-commit maintenance >/dev/null 2>&1 && fail 'post-finalizer accepted a release in maintenance'
printf '%s\n' "$canonical_cron" "$canonical_cron" >"$crontab_file"
run_post_finalize >/dev/null 2>&1 && fail 'post-finalizer accepted a duplicate scheduler line'
printf '%s\n' "$canonical_cron" >"$crontab_file"

# Standalone read-only diagnostic. The pinned runtime audit is a fixture that records its
# arguments and answers with the outcome under test.
commit=0123456789abcdef0123456789abcdef01234567
release=0123456789ab-42-1
diagnostic_cron=${canonical_cron/\/fixture\/php//opt/cpanel/ea-php85/root/usr/bin/php}
printf '%s\n' "$diagnostic_cron" >"$crontab_file"
cat >"$temporary/audit.sh" <<'MOCK'
printf '%s\n' "$*" >>"$FIXTURE_AUDIT_ARGS"
if [ "$7" = generation ]; then
    echo "runtime-audit generation=${FIXTURE_GENERATION:-current}"
    exit 0
fi
case $FIXTURE_AUDIT in
    exact)
        echo 'runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=finalized'
        echo 'operational-audit pending_migrations=0 queue_driver=database queue_applicability=database pending_total=0 failed_applicability=database failed_total=0' ;;
    superseded) echo 'runtime-audit generation=superseded' ;;
    pending)
        echo 'runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=finalized'
        echo 'operational-audit pending_migrations=3 queue_driver=database' ;;
    stale)
        echo 'runtime-audit key=view.compiled root=stable type=directory reason=stale-release' >&2
        echo 'DB_PASSWORD=SYNTHETIC_AUDIT_SECRET_DO_NOT_PRINT' >&2
        echo 'SYNTHETIC_AUDIT_SECRET_DO_NOT_PRINT'
        exit 1 ;;
esac
MOCK
run_diagnose() {
    PATH="$temporary/bin:$PATH" \
        DIAG_SSH_TARGET=fixture-host DIAG_RELEASE_ID="${DIAG_RELEASE_ID:-$release}" \
        DIAG_COMMIT="${DIAG_COMMIT:-$commit}" DIAG_AUDIT_SCRIPT="$temporary/audit.sh" \
        FIXTURE_AUDIT="${FIXTURE_AUDIT:-exact}" FIXTURE_AUDIT_ARGS="$temporary/audit-args" \
        FIXTURE_SSH_LOG="$temporary/ssh.log" FIXTURE_NODE_LOG="$temporary/node.log" \
        bash -c 'cd "$1" && bash scripts/deploy/diagnose-live.sh' _ "$repository"
}
expect_diagnose_failure() {
    local label=$1 forbidden
    if run_diagnose >"$temporary/output" 2>&1; then
        fail "diagnostic accepted $label"
    fi
    for forbidden in SYNTHETIC_AUDIT_SECRET_DO_NOT_PRINT fixture-manifest artisan "$remote_home"; do
        if grep -Fq -- "$forbidden" "$temporary/output"; then
            fail "diagnostic output for $label relayed host content"
        fi
    done
    [ ! -e "$temporary/crontab-writes" ] || fail "diagnostic for $label wrote the crontab"
    if grep -Fq -- '--ttl=15' "$temporary/ssh.log" 2>/dev/null; then
        fail "diagnostic for $label issued credentials"
    fi
}

rm -f "$temporary/ssh.log" "$temporary/node.log" "$temporary/audit-args"
run_diagnose >"$temporary/output" 2>&1 || { cat "$temporary/output" >&2; fail 'diagnostic rejected a healthy reviewed release'; }
identity="svc-laravel /opt/cpanel/ea-php85/root/usr/bin/php 1G $release $commit storage"
printf '%s\n' "$identity finalized" "$identity generation" | cmp -s - "$temporary/audit-args" \
    || fail 'diagnostic did not audit the reviewed identity first and re-prove its generation last'

# A deploy that begins after the runtime audit supersedes the reviewed release; the later checks
# must not then be reported as proof about it.
FIXTURE_GENERATION=superseded expect_diagnose_failure 'a release superseded during the diagnostic'
grep -Fq 'did not stay current for the whole diagnostic' "$temporary/output" || fail 'diagnostic did not name the mid-run supersession'
grep -Fq 'exactly one canonical SVC scheduler cron line' "$temporary/output" || fail 'diagnostic skipped the cron audit'
grep -qx assets "$temporary/node.log" || fail 'diagnostic skipped the hashed asset audit'
grep -Fq -e '--ttl=15' -e '--revoke' -e 'storage:status' "$temporary/ssh.log" && fail 'diagnostic ran a credential or storage write command'
grep -qx smoke "$temporary/node.log" && fail 'diagnostic ran the MCP smoke'

# Identity input is exact and reviewed: never a short SHA, a mismatched release or a branch.
for identity in "$release:${commit:0:12}" "fedcba987654-42-1:$commit" "$release:main" "latest:$commit" \
    "$release:${commit^^}" "0123456789ab-0-1:$commit" "$release:$commit;id"; do
    rm -f "$temporary/ssh.log"
    DIAG_RELEASE_ID=${identity%%:*} DIAG_COMMIT=${identity#*:} expect_diagnose_failure "identity '$identity'"
    [ ! -e "$temporary/ssh.log" ] || fail "diagnostic contacted the host for rejected identity '$identity'"
done

# Identity drift, a failed runtime audit and pending migrations each fail, and every check still runs.
for outcome in superseded stale pending; do
    rm -f "$temporary/ssh.log" "$temporary/node.log"
    FIXTURE_AUDIT=$outcome expect_diagnose_failure "runtime audit outcome '$outcome'"
    grep -qx assets "$temporary/node.log" || fail "a failed runtime audit ($outcome) skipped the remaining checks"
done
FIXTURE_AUDIT=superseded run_diagnose >"$temporary/output" 2>&1 || true
grep -Fq 'the live deployment is not the reviewed release' "$temporary/output" \
    || fail 'diagnostic did not name identity drift'
FIXTURE_AUDIT=stale run_diagnose >"$temporary/output" 2>&1 || true
grep -Fqx 'runtime-audit key=view.compiled root=stable type=directory reason=stale-release' "$temporary/output" \
    || fail 'diagnostic dropped the fixed runtime audit failure label'

# Cron: zero lines, duplicates and a legacy line.
for cron in '' "$diagnostic_cron"$'\n'"$diagnostic_cron" '* * * * * cd ~/svc-laravel && php artisan schedule:run'; do
    printf '%s\n' "$cron" >"$crontab_file"
    expect_diagnose_failure 'a crontab without exactly one canonical scheduler line'
done
printf '%s\n' "$diagnostic_cron" >"$crontab_file"

# Assets: a failing audit, and a deployed manifest that is missing, oversized or reached through a
# symlink.
FIXTURE_ASSET_FAIL=1 expect_diagnose_failure 'a failing hashed asset audit'
manifest=$remote_home/svc-laravel/public/build/manifest.json
mv "$manifest" "$temporary/manifest.json"
ln -s "$temporary/manifest.json" "$manifest"
expect_diagnose_failure 'a symlinked build manifest'
rm "$manifest"
expect_diagnose_failure 'a missing build manifest'
cp "$temporary/manifest.json" "$manifest"
mv "$remote_home/svc-laravel/public/build" "$temporary/build"
ln -s "$temporary/build" "$remote_home/svc-laravel/public/build"
expect_diagnose_failure 'a symlinked build directory'
rm "$remote_home/svc-laravel/public/build"
mv "$temporary/build" "$remote_home/svc-laravel/public/build"
{ cat "$temporary/manifest.json"; head -c 1048577 /dev/zero | tr '\0' ' '; } >"$manifest"
expect_diagnose_failure 'an oversized build manifest'
mv "$temporary/manifest.json" "$manifest"
run_diagnose >/dev/null 2>&1 || fail 'diagnostic did not recover once the manifest was restored'

echo 'Deployment hook tests passed.'
