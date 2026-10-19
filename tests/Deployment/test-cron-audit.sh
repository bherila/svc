#!/usr/bin/env bash
# Tests for scripts/deploy/cron-audit.sh: exactly one canonical SVC scheduler line, nothing
# written, unrelated lines ignored and nothing from the crontab printed. When
# SHARED_DEPLOY_ACTION_PATH names a checkout of the pinned shared action (CI always sets it),
# the action's own installer is run against the same fake crontab to prove its output is the
# line this audit accepts, that a reinstall changes nothing, that legacy lines are replaced and
# that other applications' lines survive.
set -euo pipefail

repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

home=$temporary/home
bin=$temporary/bin
mkdir -p "$home" "$bin"
crontab_file=$temporary/crontab
writes=$temporary/crontab-writes
php=/opt/cpanel/ea-php85/root/usr/bin/php
secret=SYNTHETIC_CRON_SECRET_DO_NOT_PRINT

# A fake crontab: `-l` reads the fixture (or reports no crontab, or a read failure); any other
# invocation is a write, which is logged so read-only callers can be held to it.
cat >"$bin/crontab" <<'MOCK'
#!/usr/bin/env bash
if [ "${1:-}" = -l ]; then
    [ "${FIXTURE_CRONTAB_MODE:-}" != broken ] || { echo 'crontab: cannot open your crontab: Permission denied' >&2; exit 1; }
    [ -f "$FIXTURE_CRONTAB" ] || { echo "no crontab for fixture" >&2; exit 1; }
    cat "$FIXTURE_CRONTAB"
    exit 0
fi
printf 'write\n' >>"$FIXTURE_CRONTAB_WRITES"
cp "$1" "$FIXTURE_CRONTAB"
MOCK
chmod +x "$bin/crontab"

# shellcheck disable=SC2016 # $HOME is literal in the crontab.
canonical='* * * * * cd "$HOME/svc-laravel" && '"$php"' -d memory_limit=1G artisan schedule:run > /dev/null 2>&1 # JOB:svc-laravel-scheduler'
# Other applications on the account, comments and environment lines: never SVC's.
# shellcheck disable=SC2016
unrelated=(
    "MAILTO=\"\""
    "# svc-laravel used to run from here; the deploy manages it now"
    '* * * * * cd "$HOME/phr-laravel" && /usr/bin/php artisan schedule:run > /dev/null 2>&1 # JOB:phr-laravel-scheduler'
    '* * * * * cd "$HOME/svc-laravel-two" && /usr/bin/php artisan schedule:run > /dev/null 2>&1 # JOB:svc-laravel-two-scheduler'
    "*/5 * * * * curl -fsS https://example.test/ping?token=$secret > /dev/null"
)

run_audit() {
    HOME=$home PATH="$bin:$PATH" FIXTURE_CRONTAB=$crontab_file FIXTURE_CRONTAB_WRITES=$writes \
        bash "$repository/scripts/deploy/cron-audit.sh" "$@"
}

write_crontab() {
    rm -f "$writes"
    printf '%s\n' "$@" >"$crontab_file"
    cp "$crontab_file" "$temporary/before"
}

assert_untouched() {
    [ ! -e "$writes" ] || fail "$1: the audit wrote the crontab"
    if [ -e "$temporary/before" ]; then
        cmp -s "$temporary/before" "$crontab_file" || fail "$1: the crontab changed"
    fi
}

accept() {
    local label=$1
    run_audit svc-laravel "$php" >"$temporary/output" 2>&1 || { cat "$temporary/output" >&2; fail "$label was rejected"; }
    grep -qx 'SVC scheduler cron: exactly one canonical line.' "$temporary/output" || fail "$label: missing fixed success label"
    assert_untouched "$label"
}

reject() {
    local label=$1 expected=$2
    if run_audit svc-laravel "$php" >"$temporary/output" 2>&1; then
        fail "$label was accepted"
    fi
    grep -Fq "$expected" "$temporary/output" || { cat "$temporary/output" >&2; fail "$label: expected '$expected'"; }
    # Secret-safe: never a line, a value, the home path or a job id.
    for forbidden in "$secret" "$home" 'artisan' 'JOB:' 'cd '; do
        if grep -Fq -- "$forbidden" "$temporary/output"; then
            fail "$label: output contained crontab content ($forbidden)"
        fi
    done
    [ "$(wc -l <"$temporary/output")" -eq 1 ] || fail "$label: output was not one fixed label"
    assert_untouched "$label"
}

# Zero lines.
rm -f "$crontab_file" "$temporary/before" "$writes"
reject 'no crontab at all' 'no SVC scheduler line is installed'
write_crontab ''
reject 'an empty crontab' 'no SVC scheduler line is installed'
write_crontab "${unrelated[@]}"
reject 'only other applications lines' 'no SVC scheduler line is installed'

# Exactly one canonical line, with unrelated lines around it.
write_crontab "$canonical"
accept 'the canonical line alone'
write_crontab "${unrelated[0]}" "${unrelated[1]}" "$canonical" "${unrelated[2]}" "${unrelated[3]}" "${unrelated[4]}"
accept 'the canonical line among unrelated lines'

# Duplicates.
write_crontab "$canonical" "${unrelated[2]}" "$canonical"
reject 'a duplicated canonical line' '2 SVC lines are installed'

# Legacy lines, in every spelling, beside or instead of the canonical one.
# shellcheck disable=SC2016
legacy=(
    '* * * * * cd ~/svc-laravel && php artisan schedule:run >> /dev/null 2>&1'
    "* * * * * cd $home/svc-laravel && php artisan schedule:run token=$secret"
    '* * * * * cd "$HOME/svc-laravel" && /usr/bin/php artisan schedule:run > /dev/null 2>&1'
    '* * * * * cd $HOME/.deployments/svc-laravel/releases/old && php artisan schedule:run'
    "* * * * * /usr/bin/php $home/svc-laravel/artisan schedule:run"
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler'
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler   '
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler (legacy)'
)
for line in "${legacy[@]}"; do
    write_crontab "$canonical" "$line"
    reject "a legacy line beside the canonical one (${line:0:30})" '2 SVC lines are installed'
    write_crontab "${unrelated[@]}" "$line"
    reject "a legacy line instead of the canonical one (${line:0:30})" 'the only SVC line is not the canonical 1G scheduler line'
done

# The right shape with the wrong PHP, memory or log is still not canonical.
for line in \
    "${canonical/memory_limit=1G/memory_limit=512M}" \
    "${canonical/$php//usr/bin/php}" \
    "${canonical/> \/dev\/null/>> storage/logs/scheduler.log}"; do
    write_crontab "$line"
    reject 'a near-canonical line' 'the only SVC line is not the canonical 1G scheduler line'
done

# A crontab that cannot be read is a failure, not "zero lines".
write_crontab "$canonical"
if FIXTURE_CRONTAB_MODE=broken run_audit svc-laravel "$php" >"$temporary/output" 2>&1; then
    fail 'an unreadable crontab was accepted'
fi
grep -Fq 'the account crontab could not be read' "$temporary/output" || fail 'unreadable crontab label missing'
grep -Fq 'Permission denied' "$temporary/output" && fail 'crontab error output was relayed'

# Only SVC's own arguments are accepted.
for arguments in 'phr-laravel /usr/bin/php' 'svc-laravel php' 'svc-laravel /usr/bin/php;id' 'svc-laravel'; do
    # shellcheck disable=SC2086 # Word splitting builds the argument list.
    if run_audit $arguments >/dev/null 2>&1; then
        fail "audit arguments '$arguments' were accepted"
    fi
done

echo 'SVC cron audit tests passed.'

action=${SHARED_DEPLOY_ACTION_PATH:-}
if [ -z "$action" ]; then
    [ -z "${CI:-}" ] || fail 'CI must set SHARED_DEPLOY_ACTION_PATH to the pinned shared action checkout'
    echo 'SKIP: SHARED_DEPLOY_ACTION_PATH is unset; the pinned action installer contract was not run.'
    exit 0
fi
action=$(cd "$action" && pwd)
[ -f "$action/scripts/prepare-cron-lines.sh" ] && [ -f "$action/scripts/install-cron.sh" ] \
    || fail 'SHARED_DEPLOY_ACTION_PATH is not a shared deployment action checkout'

# Exactly what the deploy job's inputs give the action: deploy-dir svc-laravel, cron-memory-limit 1G,
# the default /dev/null scheduler log, and no cron-lines or extra-cron-lines.
mapfile -t desired < <(bash "$action/scripts/prepare-cron-lines.sh" svc-laravel "$php" 1G /dev/null '' '')
[ "${#desired[@]}" -eq 1 ] || fail 'the action prepared other than one SVC cron line'
[ "${desired[0]}" = "$canonical" ] || fail 'the action prepares a different line than cron-audit.sh accepts'

install_with_action() {
    HOME=$home PATH="$bin:$PATH" FIXTURE_CRONTAB=$crontab_file FIXTURE_CRONTAB_WRITES=$writes \
        bash "$action/scripts/install-cron.sh" svc-laravel "${desired[@]}" >/dev/null
}

# Zero lines: the action installs the line and the audit accepts it.
rm -f "$crontab_file" "$writes" "$temporary/before"
install_with_action
run_audit svc-laravel "$php" >/dev/null || fail 'the action-installed line was rejected'

# Idempotent: a second install writes nothing.
rm -f "$writes"
install_with_action
[ ! -e "$writes" ] || fail 'a repeated install rewrote the crontab'
run_audit svc-laravel "$php" >/dev/null || fail 'a repeated install broke the line'

# Legacy and duplicate lines in the spellings the action owns are replaced; unrelated lines stay,
# in order, and the canonical line is appended once.
printf '%s\n' "${unrelated[0]}" "${legacy[2]}" "${unrelated[1]}" "${unrelated[2]}" "${legacy[2]}" \
    "${unrelated[3]}" "* * * * * cd $home/svc-laravel && php artisan schedule:run" "$canonical" \
    "${unrelated[4]}" >"$crontab_file"
install_with_action
run_audit svc-laravel "$php" >/dev/null || fail 'legacy or duplicate lines survived the action install'
printf '%s\n' "${unrelated[@]}" "$canonical" >"$temporary/expected"
cmp -s "$temporary/expected" "$crontab_file" || fail 'the action install did not keep exactly the unrelated lines, in order'

# A legacy spelling the action does not own survives its install, which is why preflight refuses
# it before anything changes and why verification would fail closed if it ever got this far.
printf '%s\n' "${unrelated[2]}" "${legacy[0]}" >"$crontab_file"
install_with_action
if run_audit svc-laravel "$php" >/dev/null 2>&1; then
    fail 'an unmanaged legacy line beside the installed line was accepted'
fi

echo 'Pinned action cron installer contract passed.'
