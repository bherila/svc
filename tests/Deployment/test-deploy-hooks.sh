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

for invocation in bare relative absolute; do
    case $invocation in
        bare) artisan=artisan ;;
        relative) artisan=./artisan ;;
        absolute) artisan=$stable_release/artisan ;;
    esac
    make_process 200 "$stable_release" php "$artisan" queue:work
    if run_quiesce >/dev/null 2>&1; then
        fail "$invocation SVC Artisan invocation was accepted"
    fi
    rm -r "$temporary/proc/200"
done

echo 'Deployment hook tests passed.'
