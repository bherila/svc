#!/usr/bin/env bash
set -euo pipefail

repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
fixture_home="$fixture/account"
config_root="$fixture_home/.config/svc"
key_root="$fixture_home/svc-laravel/storage/app/private/oauth"
mkdir -p "$config_root" "$key_root"
files=("$config_root/deployment.env" "$config_root/database.env" \
    "$key_root/oauth-private.key" "$key_root/oauth-public.key")
# Empty files deliberately pass: preflight checks metadata, not secret contents.
touch "${files[@]}"
chmod 600 "${files[@]}"

run_preflight() {
    HOME="$fixture_home" bash "$repository/scripts/deploy/preflight.sh" \
        .deployments/svc-laravel/releases/fixture /fixture/php svc-laravel
}
reject() {
    if run_preflight >"$fixture/output" 2>&1; then
        echo "FAIL: $1 was accepted." >&2
        exit 1
    fi
}

run_preflight >/dev/null
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
printf '%s\n' 'SYNTHETIC_PREREQUISITE_CONTENT_DO_NOT_PRINT' >"${files[0]}"
chmod 644 "${files[0]}"
reject 'world-readable configuration'
test "$(stat -c %a "${files[0]}")" = 644
if rg -q SYNTHETIC_PREREQUISITE_CONTENT_DO_NOT_PRINT "$fixture/output"; then
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

echo 'SVC deployment preflight tests passed.'
