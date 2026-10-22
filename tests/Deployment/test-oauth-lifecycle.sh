#!/usr/bin/env bash
# Exercise SVC's real preflight at the pinned action's failure/recovery boundary.
set -euo pipefail
repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
: "${SHARED_DEPLOY_ACTION_PATH:?checkout the workflow-pinned shared action}"
shared=$(cd "$SHARED_DEPLOY_ACTION_PATH" && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export FIXTURE_REAL_PHP
FIXTURE_REAL_PHP=$(command -v php)
export FIXTURE_ACCOUNT="$fixture/account" FIXTURE_CRONTAB="$fixture/crontab"
mkdir -p "$FIXTURE_ACCOUNT/.config/svc" "$fixture/bin"
cat >"$fixture/bin/php" <<'MOCK'
#!/usr/bin/env bash
for argument in "$@"; do
    [ "$argument" != -- ] || exec "$FIXTURE_REAL_PHP" "$@"
done
while [ "${1:-}" = -d ]; do shift 2; done
[ "${1:-}" != artisan ] || shift
case ${1:-} in
    -r) test -f storage/framework/down ;;
    down) mkdir -p storage/framework; : >storage/framework/down ;;
    up) rm -f storage/framework/down ;;
esac
MOCK
cat >"$fixture/bin/crontab" <<'MOCK'
#!/usr/bin/env bash
case ${1:-} in
    -l) test -f "$FIXTURE_CRONTAB" || { echo 'no crontab for fixture' >&2; exit 1; }; cat "$FIXTURE_CRONTAB" ;;
    -r) rm -f "$FIXTURE_CRONTAB" ;;
    *) cp "$1" "$FIXTURE_CRONTAB" ;;
esac
MOCK
cat >"$fixture/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
exec bash -c "$2"
MOCK
cat >"$fixture/bin/curl" <<'MOCK'
#!/usr/bin/env bash
test "${!#}" = https://svc.bherila.net/.well-known/oauth-authorization-server || exit 98
if test -f "$FIXTURE_ACCOUNT/svc-laravel/storage/framework/down"; then
    echo '503 text/html'
else
    echo "${FIXTURE_LIVE_RESPONSE:-200 application/json}"
fi
MOCK
chmod +x "$fixture/bin/"{php,crontab,ssh,curl}
php="$fixture/bin/php"
commit=0123456789abcdef0123456789abcdef01234567
selected="$FIXTURE_ACCOUNT/svc-laravel"
config="$FIXTURE_ACCOUNT/.config/svc/deployment.env"
mkdir -p "$selected/"{vendor,bootstrap,storage/framework,storage/app/private/oauth,public}
touch "$selected/artisan" "$selected/vendor/autoload.php" "$selected/bootstrap/app.php"
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"$config"
cp "$config" "$selected/.env"
touch "$FIXTURE_ACCOUNT/.config/svc/database.env" "$selected/storage/app/private/oauth/"{oauth-private.key,oauth-public.key}
chmod 600 "$FIXTURE_ACCOUNT/.config/svc/"*.env "$selected/storage/app/private/oauth/"*.key
# shellcheck disable=SC2016 # The scheduler retains the host's literal $HOME.
printf '%s\n' '* * * * * cd "$HOME/svc-laravel" && php artisan schedule:run # JOB:svc-laravel-scheduler' >"$FIXTURE_CRONTAB"
cp "$FIXTURE_CRONTAB" "$fixture/original-cron"

atomic() {
    HOME="$FIXTURE_ACCOUNT" PATH="$fixture/bin:$PATH" bash "$shared/scripts/atomic-release.sh" "$@"
}
begin() {
    release=$1
    # Match the workflow's default policy; maintenance is exercised separately below.
    atomic begin svc-laravel "$release" "$commit" 7200 0 "${2:-rollback}" "$commit" stable-directory storage >/dev/null
    candidate="$FIXTURE_ACCOUNT/.deployments/svc-laravel/releases/$release"
    mkdir -p "$candidate/"{vendor,bootstrap,storage/framework,public}
    test ! -e "$candidate/scripts" || fail 'fixture uploaded scripts outside the deployment payload'
    ln -s "$repository/vendor/autoload.php" "$candidate/vendor/autoload.php"
    touch "$candidate/artisan" "$candidate/bootstrap/app.php"
    cp "$config" "$candidate/.env"
    atomic preflight svc-laravel "$release" '' >/dev/null
}
preflight() {
    HOME="$FIXTURE_ACCOUNT" PATH="$fixture/bin:$PATH" TARGET=fixture \
        CANDIDATE_DIR=".deployments/svc-laravel/releases/$release" PHP_BINARY="$php" \
        STABLE_DIR=svc-laravel SCRIPT="$repository/scripts/deploy/preflight.sh" \
        bash "$shared/scripts/run-preflight-hook.sh"
}

# The actual incident's two configurations: live already paused, or a still-live
# app whose incoming environment would disable issuance at selection.
for scenario in live incoming; do
    printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"$config"
    [ "$scenario" != incoming ] || printf 'AGENT_API_OAUTH_SERVER_ENABLED=false\n' >"$config"
    begin "refused-$scenario"
    response='200 application/json'
    [ "$scenario" != live ] || response='404 application/json'
    FIXTURE_LIVE_RESPONSE="$response" preflight >"$fixture/output" 2>&1 && fail 'paused issuance passed preflight'
    test ! -e "$selected/storage/framework/down" || fail 'refused deployment stopped service'
    cmp -s "$FIXTURE_CRONTAB" "$fixture/original-cron" || fail 'refused deployment changed cron'
    grep -Fxq false "$FIXTURE_ACCOUNT/.deployments/svc-laravel/state/$release/risk_started" || fail 'refusal crossed database risk'
    atomic finalize svc-laravel "$release" "$php" >/dev/null
    test ! -e "$FIXTURE_ACCOUNT/.deployments/svc-laravel/deploy.lock" || fail 'refused deployment retained its lock'
    test ! -e "$selected/storage/framework/down" || fail 'refusal finalization stopped service'
done

# A corrected configuration retries without an operator turning the app back on.
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"$config"
begin ready-retry
preflight >/dev/null
atomic quiesce svc-laravel "$release" "$php" >/dev/null
test -f "$selected/storage/framework/down" || fail 'action did not pause HTTP issuance'
test ! -s "$FIXTURE_CRONTAB" || fail 'action did not pause the scheduler'
atomic finalize svc-laravel "$release" "$php" >/dev/null
test ! -e "$selected/storage/framework/down" || fail 'pre-risk recovery did not restore service'
cmp -s "$FIXTURE_CRONTAB" "$fixture/original-cron" || fail 'pre-risk recovery did not restore cron'
grep -Fxq AGENT_API_OAUTH_SERVER_ENABLED=true "$config" || fail 'deploy rewrote the operator flag'

# Under the workflow's rollback policy, failure after database risk restores the
# prior code and scheduler without changing the operator's OAuth setting.
begin failed-migration-rollback
preflight >/dev/null
atomic quiesce svc-laravel "$release" "$php" >/dev/null
atomic prepare svc-laravel "$release" "$php" >/dev/null
atomic risk svc-laravel "$release" "$php" >/dev/null
atomic finalize svc-laravel "$release" "$php" >/dev/null
test ! -e "$selected/storage/framework/down" || fail 'rollback did not restore service'
cmp -s "$FIXTURE_CRONTAB" "$fixture/original-cron" || fail 'rollback did not restore cron'
grep -Fxq AGENT_API_OAUTH_SERVER_ENABLED=true "$config" || fail 'rollback rewrote the operator flag'
begin rollback-retry
preflight >/dev/null
atomic finalize svc-laravel "$release" "$php" >/dev/null

# Explicit maintenance remains a supported safety policy. A blind retry may not
# bypass a deliberately paused app; this fixture does not model the workflow default.
begin failed-migration-maintenance maintenance
preflight >/dev/null
atomic quiesce svc-laravel "$release" "$php" >/dev/null
atomic prepare svc-laravel "$release" "$php" >/dev/null
atomic risk svc-laravel "$release" "$php" >/dev/null
atomic finalize svc-laravel "$release" "$php" >/dev/null
test -f "$selected/storage/framework/down" || fail 'post-risk failure resumed unverified code'
begin blind-retry
preflight >"$fixture/output" 2>&1 && fail 'retry bypassed intentional maintenance'
atomic quiesce svc-laravel "$release" "$php" >"$fixture/output" 2>&1 && fail 'quiescence bypassed intentional maintenance'
atomic finalize svc-laravel "$release" "$php" >/dev/null
test -f "$selected/storage/framework/down" || fail 'retry silently brought the app up'
echo 'SVC OAuth deployment lifecycle tests passed.'
