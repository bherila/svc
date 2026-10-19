#!/usr/bin/env bash
# Read-only host prerequisites, before maintenance, shared-path preparation or DB risk.
# Configuration/key contents must never be opened or printed here.
set -euo pipefail

test "$#" -eq 3
candidate_dir=$1
php_binary=$2
stable_dir=$3
test "$stable_dir" = svc-laravel
case $candidate_dir in
    .deployments/svc-laravel/releases/*)
        release_id=${candidate_dir#.deployments/svc-laravel/releases/}
        case $release_id in
            '' | . | .. | *[!A-Za-z0-9._-]*) exit 2 ;;
        esac ;;
    *) exit 2 ;;
esac
case $php_binary in /*) ;; *) exit 2 ;; esac

account_root=$(cd "$HOME" && pwd -P)
fail() {
    # Labels are fixed application paths, never stat output or secret values.
    echo "SVC deployment prerequisite failed: $1." >&2
    exit 1
}

require_private_file() {
    local path=$1 label=$2 metadata
    test -f "$path" && test ! -L "$path" || fail "$label must be a regular, unaliased file"
    metadata=$(stat -c '%a:%u' -- "$path")
    case $metadata in
        "400:$(id -u)" | "600:$(id -u)") ;;
        *) fail "$label must be account-owned with mode 0400 or 0600" ;;
    esac
}

for directory in "$account_root/.config" "$account_root/.config/svc"; do
    test -d "$directory" && test ! -L "$directory" || fail 'private configuration directory is missing or aliased'
done
require_private_file "$account_root/.config/svc/deployment.env" deployment.env
require_private_file "$account_root/.config/svc/database.env" database.env

# Before preparation, keys belong to the selected application, not the uploaded
# candidate. A compatibility stable symlink is permitted only into SVC's managed
# releases. The only permitted nested alias is the action-managed storage tree.
selected_root="$account_root/$stable_dir"
if test -L "$selected_root"; then
    selected_root=$(readlink -f -- "$selected_root")
    case $selected_root in
        "$account_root/.deployments/svc-laravel/releases/"*) ;;
        *) fail 'selected application alias is unmanaged' ;;
    esac
fi
test -d "$selected_root" || fail 'selected application directory is missing'
key_root="$selected_root"
for component in storage app private oauth; do
    key_root="$key_root/$component"
    if test -L "$key_root"; then
        test "$component" = storage || fail 'Passport directory contains an unmanaged alias'
        key_root=$(readlink -f -- "$key_root")
        test "$key_root" = "$account_root/.deployments/svc-laravel/shared/storage" \
            || fail 'Passport storage alias is unmanaged'
        test "$(readlink -f -- "$account_root/.deployments/svc-laravel/shared")" \
            = "$account_root/.deployments/svc-laravel/shared" \
            || fail 'shared storage parent is aliased'
    fi
    test -d "$key_root" || fail 'Passport key directory is missing'
done
require_private_file "$key_root/oauth-private.key" oauth-private.key
require_private_file "$key_root/oauth-public.key" oauth-public.key

# The shared action pauses SVC's cron lines before maintenance and replaces them after serving,
# but it recognises a line only by `cd "$HOME/svc-laravel" ` (or the bare or expanded-home
# spelling). An SVC line spelled any other way (~/svc-laravel, a release path, a job id alone)
# would keep running through the migration and survive beside the canonical line, so refuse it
# now, before anything changes. The classifier matches scripts/deploy/cron-audit.sh. Lines are
# never printed.
command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable'
cron_work=$(mktemp -d)
trap 'rm -rf -- "$cron_work"' EXIT
if ! crontab -l >"$cron_work/current" 2>"$cron_work/error"; then
    grep -qi 'no crontab for' "$cron_work/error" || fail 'the account crontab could not be read'
    : >"$cron_work/current"
fi
# shellcheck disable=SC2016 # The literal spellings keep $HOME unexpanded, as cron sees it.
unmanaged_cron=$(LC_ALL=C awk -v app="$stable_dir" \
    -v quoted_literal='cd "$HOME/'"$stable_dir"'" ' -v bare_literal='cd $HOME/'"$stable_dir"' ' \
    -v quoted_expanded="cd \"$HOME/$stable_dir\" " -v bare_expanded="cd $HOME/$stable_dir " '
    function component(character) { return character != "" && character ~ /[A-Za-z0-9._-]/ }
    function names_app(line,   offset, relative, start) {
        offset = 1
        while ((relative = index(substr(line, offset), app)) != 0) {
            start = offset + relative - 1
            if (!component(substr(line, start - 1, 1)) && !component(substr(line, start + length(app), 1))) return 1
            offset = start + 1
        }
        return 0
    }
    # The scheduler job id anywhere it ends at an id boundary: trailing spaces or an annotation
    # after it must not hide a legacy scheduler.
    function has_job(line,   tag, offset, relative, start) {
        tag = "JOB:" app "-scheduler"
        offset = 1
        while ((relative = index(substr(line, offset), tag)) != 0) {
            start = offset + relative - 1
            if (!component(substr(line, start + length(tag), 1))) return 1
            offset = start + 1
        }
        return 0
    }
    /^[[:space:]]*(#|$)/ { next }
    /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=/ { next }
    names_app($0) || has_job($0) {
        if (!(index($0, quoted_literal) || index($0, bare_literal) || index($0, quoted_expanded) || index($0, bare_expanded))) unmanaged++
    }
    END { print unmanaged + 0 }
' "$cron_work/current")
test "$unmanaged_cron" = 0 || fail 'an SVC cron line is spelled so the deploy cannot pause or replace it'

echo 'SVC static deployment prerequisites passed (metadata only).'
