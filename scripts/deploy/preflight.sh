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

echo 'SVC static deployment prerequisites passed (metadata only).'
