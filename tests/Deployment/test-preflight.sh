#!/usr/bin/env bash
set -euo pipefail

repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
: "${SHARED_DEPLOY_ACTION_PATH:?checkout the workflow-pinned shared action}"
shared=$(cd "$SHARED_DEPLOY_ACTION_PATH" && pwd)
php_binary=$(command -v php)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
fixture_home="$fixture/account with spaces"
config_root="$fixture_home/.config/svc"
key_root="$fixture_home/svc-laravel/storage/app/private/oauth"
mkdir -p "$config_root" "$key_root"
files=("$config_root/deployment.env" "$config_root/database.env" \
    "$key_root/oauth-private.key" "$key_root/oauth-public.key")
# Incoming OAuth must be explicitly enabled; keys/database are metadata-only.
touch "${files[@]}"
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"${files[0]}"
chmod 600 "${files[@]}"
candidate="$fixture_home/.deployments/svc-laravel/releases/fixture"
# Build only the pinned action's default upload roots, never a full checkout.
# The production hook is streamed separately; scripts/ is not uploaded.
upload_paths=$("$php_binary" -r '
    require $argv[1];
    echo Symfony\Component\Yaml\Yaml::parseFile($argv[2])["inputs"]["paths"]["default"];
' "$repository/vendor/autoload.php" "$shared/action.yml")
while IFS= read -r path; do
    [ -n "$path" ] || continue
    [[ $path =~ ^[A-Za-z0-9._-]+$ ]] || exit 2
    case $path in
        artisan | composer.json | composer.lock) mkdir -p "$candidate"; touch "$candidate/$path" ;;
        *) mkdir -p "$candidate/$path" ;;
    esac
done <<< "$upload_paths"
test ! -e "$candidate/scripts"
ln -s "$repository/vendor/autoload.php" "$candidate/vendor/autoload.php"

# A fake, read-only crontab: the fixture's lines, no crontab, or a failed read. A write fails.
mkdir -p "$fixture/bin"
crontab_file="$fixture/crontab"
cat >"$fixture/bin/crontab" <<'MOCK'
#!/usr/bin/env bash
[ "${1:-}" = -l ] || { echo 'preflight wrote the crontab' >&2; exit 97; }
[ "${FIXTURE_CRONTAB_MODE:-}" != broken ] || { echo 'crontab: Permission denied' >&2; exit 1; }
[ -f "$FIXTURE_CRONTAB" ] || { echo 'no crontab for fixture' >&2; exit 1; }
cat "$FIXTURE_CRONTAB"
MOCK
chmod +x "$fixture/bin/crontab"
cat >"$fixture/bin/curl" <<'MOCK'
#!/usr/bin/env bash
[ "${!#}" = https://svc.bherila.net/.well-known/oauth-authorization-server ] || exit 98
[ "${FIXTURE_OAUTH_RESPONSE:-}" != transport-failure ] || exit 7
printf '%s\n' "${FIXTURE_OAUTH_RESPONSE:-200 application/json}"
MOCK
chmod +x "$fixture/bin/curl"

run_preflight() {
    HOME="$fixture_home" PATH="$fixture/bin:$PATH" FIXTURE_CRONTAB="$crontab_file" \
        bash -s -- .deployments/svc-laravel/releases/fixture "$php_binary" svc-laravel \
        < "$repository/scripts/deploy/preflight.sh"
}
reject() {
    if run_preflight >"$fixture/output" 2>&1; then
        echo "FAIL: $1 was accepted." >&2
        exit 1
    fi
}

run_preflight >/dev/null

mv "$candidate/vendor/autoload.php" "$candidate/vendor/autoload.saved"
before=$(sha256sum "${files[@]}")
reject 'a deployment payload missing its autoloader'
test "$before" = "$(sha256sum "${files[@]}")"
grep -Fq 'incoming OAuth issuance' "$fixture/output"
mv "$candidate/vendor/autoload.saved" "$candidate/vendor/autoload.php"

# Parse exactly the incoming flag with the real Dotenv parser, without executing
# shell syntax, leaking errors/values, or changing any private file or cron.
for flag in true TRUE 1 '(true)' '"true"'; do
    printf 'AGENT_API_OAUTH_SERVER_ENABLED=%s\n' "$flag" >"${files[0]}"
    run_preflight >/dev/null || { echo 'FAIL: enabled incoming OAuth was rejected.' >&2; exit 1; }
done
printf 'OAUTH_READY=true\nAGENT_API_OAUTH_SERVER_ENABLED=${OAUTH_READY}\n' >"${files[0]}"
reject 'interpolated incoming OAuth setting'
for flags in $'true\nAGENT_API_OAUTH_SERVER_ENABLED=false' $'false\nAGENT_API_OAUTH_SERVER_ENABLED=true'; do
    printf 'AGENT_API_OAUTH_SERVER_ENABLED=%s\n' "$flags" >"${files[0]}"
    reject 'duplicate incoming OAuth setting'
done
for flag in false FALSE 0 '(false)' null empty '""' '' ambiguous; do
    printf 'AGENT_API_OAUTH_SERVER_ENABLED=%s\nPRIVATE_FIXTURE=SYNTHETIC_OAUTH_SECRET_DO_NOT_PRINT\n' "$flag" >"${files[0]}"
    before=$(sha256sum "${files[@]}")
    reject 'disabled or ambiguous incoming OAuth configuration'
    test "$before" = "$(sha256sum "${files[@]}")" || { echo 'FAIL: OAuth refusal changed private files.' >&2; exit 1; }
    grep -Fq 'incoming OAuth issuance' "$fixture/output" || { echo 'FAIL: incoming OAuth refusal missing.' >&2; exit 1; }
    if grep -Fq SYNTHETIC_OAUTH_SECRET_DO_NOT_PRINT "$fixture/output"; then
        echo 'FAIL: private OAuth configuration was printed.' >&2; exit 1
    fi
done
printf 'AGENT_API_OAUTH_SERVER_ENABLED="SYNTHETIC_PARSE_SECRET_DO_NOT_PRINT\n' >"${files[0]}"
reject 'malformed private configuration'
grep -Fq SYNTHETIC_PARSE_SECRET_DO_NOT_PRINT "$fixture/output" && { echo 'FAIL: parser error exposed configuration.' >&2; exit 1; }
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n#' >"${files[0]}"
head -c 1048576 /dev/zero | tr '\0' x >>"${files[0]}"
reject 'oversized incoming configuration'
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"${files[0]}"
for response in '404 application/json' '503 text/html' '302 text/html' '200 text/html' transport-failure; do
    before=$(sha256sum "${files[@]}")
    FIXTURE_OAUTH_RESPONSE="$response" reject 'unavailable live OAuth with enabled incoming configuration'
    test "$before" = "$(sha256sum "${files[@]}")" || { echo 'FAIL: live OAuth refusal changed private files.' >&2; exit 1; }
done
FIXTURE_OAUTH_RESPONSE='200 application/json; charset=utf-8' run_preflight >/dev/null
: >"${files[0]}"
reject 'missing incoming OAuth setting'
printf 'AGENT_API_OAUTH_SERVER_ENABLED=true\n' >"${files[0]}"
chmod 400 "${files[@]}"
run_preflight >/dev/null
chmod 600 "${files[@]}"
for path in "${files[@]}"; do
    mv "$path" "$path.saved"
    reject 'missing prerequisite'
    mkdir "$path"
    reject 'directory instead of a file'
    rmdir "$path"
    ln -s "$path.saved" "$path"
    reject 'aliased prerequisite'
    rm "$path"
    mv "$path.saved" "$path"
    for mode in 000 200 640 644 660 700 1600; do
        chmod "$mode" "$path"
        reject "unsafe mode $mode"
    done
    chmod 600 "$path"
done

# Verify secret-safe diagnostics and that preflight does not fix permissions.
printf '%s\n' 'AGENT_API_OAUTH_SERVER_ENABLED=true' 'PRIVATE_FIXTURE=SYNTHETIC_PREREQUISITE_CONTENT_DO_NOT_PRINT' >"${files[0]}"
chmod 644 "${files[0]}"
reject 'world-readable configuration'
test "$(stat -c %a "${files[0]}")" = 644
if grep -Fq SYNTHETIC_PREREQUISITE_CONTENT_DO_NOT_PRINT "$fixture/output"; then
    echo 'FAIL: prerequisite content appeared in diagnostics.' >&2
    exit 1
fi
chmod 600 "${files[0]}"
run_preflight >/dev/null

mv "$fixture_home/.config/svc" "$fixture_home/.config/svc.saved"
ln -s svc.saved "$fixture_home/.config/svc"
reject 'aliased configuration directory'
rm "$fixture_home/.config/svc"
mv "$fixture_home/.config/svc.saved" "$fixture_home/.config/svc"

# Managed storage and legacy stable aliases are valid before preparation.
mkdir -p "$fixture_home/.deployments/svc-laravel/shared" "$fixture_home/.deployments/svc-laravel/releases"
mv "$fixture_home/svc-laravel/storage" "$fixture_home/.deployments/svc-laravel/shared/storage"
ln -s ../.deployments/svc-laravel/shared/storage "$fixture_home/svc-laravel/storage"
run_preflight >/dev/null
mv "$fixture_home/svc-laravel" "$fixture_home/.deployments/svc-laravel/releases/legacy"
ln -s .deployments/svc-laravel/releases/legacy "$fixture_home/svc-laravel"
# Correct relative storage link for the retained compatibility release.
rm "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
ln -s ../../shared/storage "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
run_preflight >/dev/null

rm "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
ln -s "$fixture_home/.config" "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
reject 'unmanaged storage alias'
rm "$fixture_home/svc-laravel"
ln -s .config "$fixture_home/svc-laravel"
reject 'unmanaged selected application alias'

# Restore a valid layout, then exercise the cron precondition: every SVC line must be one the
# shared action can pause before maintenance and replace after serving.
rm "$fixture_home/svc-laravel"
ln -s .deployments/svc-laravel/releases/legacy "$fixture_home/svc-laravel"
rm "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
ln -s ../../shared/storage "$fixture_home/.deployments/svc-laravel/releases/legacy/storage"
run_preflight >/dev/null

cron_secret=SYNTHETIC_CRON_SECRET_DO_NOT_PRINT
# shellcheck disable=SC2016 # $HOME is literal in a crontab.
canonical='* * * * * cd "$HOME/svc-laravel" && /fixture/php -d memory_limit=1G artisan schedule:run > /dev/null 2>&1 # JOB:svc-laravel-scheduler'
# shellcheck disable=SC2016
unrelated=(
    'MAILTO=""'
    '# svc-laravel notes'
    '* * * * * cd ~/phr-laravel && php artisan schedule:run # JOB:phr-laravel-scheduler'
    '* * * * * cd ~/svc-laravel-two && php artisan schedule:run # JOB:svc-laravel-two-scheduler'
    "*/5 * * * * curl -fsS https://example.test/?token=$cron_secret"
)
# shellcheck disable=SC2016
managed=(
    "$canonical"
    '* * * * * cd "$HOME/svc-laravel" && php artisan schedule:run'
    '* * * * * cd $HOME/svc-laravel && php artisan schedule:run'
    "* * * * * cd \"$fixture_home/svc-laravel\" && php artisan schedule:run"
    "* * * * * cd $fixture_home/svc-laravel && php artisan schedule:run"
)
# shellcheck disable=SC2016
unmanaged=(
    "* * * * * cd ~/svc-laravel && php artisan schedule:run token=$cron_secret"
    '* * * * * cd "$HOME/svc-laravel"&& php artisan schedule:run'
    '* * * * * cd $HOME/.deployments/svc-laravel/releases/old && php artisan schedule:run'
    "* * * * * php $fixture_home/svc-laravel/artisan schedule:run"
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler'
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler   '
    '* * * * * php artisan schedule:run # JOB:svc-laravel-scheduler (legacy)'
)

rm -f "$crontab_file"
run_preflight >/dev/null || { echo 'FAIL: no crontab was rejected.' >&2; exit 1; }
printf '%s\n' "${unrelated[@]}" >"$crontab_file"
run_preflight >/dev/null || { echo 'FAIL: unrelated cron lines were rejected.' >&2; exit 1; }
for line in "${managed[@]}"; do
    # Legacy and duplicate lines the action owns are fine here: it pauses and replaces them.
    printf '%s\n' "${unrelated[@]}" "$line" "$line" >"$crontab_file"
    run_preflight >/dev/null || { echo "FAIL: a manageable SVC line was rejected: $line" >&2; exit 1; }
done
for line in "${unmanaged[@]}"; do
    printf '%s\n' "${unrelated[@]}" "$canonical" "$line" >"$crontab_file"
    reject "an unmanageable SVC cron line ($line)"
    grep -Fq 'cannot pause or replace it' "$fixture/output" || { echo 'FAIL: unmanaged cron label missing.' >&2; exit 1; }
    if grep -Fq -e "$cron_secret" -e 'artisan' -e "$fixture_home" "$fixture/output"; then
        echo 'FAIL: crontab content appeared in preflight diagnostics.' >&2
        exit 1
    fi
done
printf '%s\n' "$canonical" >"$crontab_file"
if FIXTURE_CRONTAB_MODE=broken run_preflight >"$fixture/output" 2>&1; then
    echo 'FAIL: an unreadable crontab was accepted.' >&2
    exit 1
fi
if grep -Fq 'Permission denied' "$fixture/output"; then
    echo 'FAIL: crontab errors were relayed.' >&2
    exit 1
fi

echo 'SVC deployment preflight tests passed.'
