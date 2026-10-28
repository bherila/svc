#!/usr/bin/env bash
# The shared action has entered maintenance; wait for this account's Artisan work to end.
set -euo pipefail
[[ $# -eq 3 ]] || exit 2
candidate=$1
php=$2
stable=$3
[[ $stable =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && $stable != *..* ]] || exit 2
[[ $candidate = ".deployments/$stable/releases/"* && $candidate != *..* ]] || exit 2
[[ $php = /* && -x $php ]] || exit 1
proc_root=${PILOT_QUIESCE_PROC_ROOT:-/proc}
uid=${PILOT_QUIESCE_UID:-$(id -u)}
timeout=${PILOT_QUIESCE_TIMEOUT_SECONDS:-120}
interval=${PILOT_QUIESCE_INTERVAL_SECONDS:-2}
[[ -d $proc_root && $uid =~ ^[0-9]+$ && $timeout =~ ^[0-9]+$ && $interval =~ ^[1-9][0-9]*$ ]] || exit 2
deadline=$((SECONDS + timeout))
while :; do
    running=false
    for process in "$proc_root"/[0-9]*; do
        [[ -r "$process/status" && -r "$process/cmdline" ]] || continue
        process_uid=$(awk '/^Uid:/ { print $2; exit }' "$process/status" 2>/dev/null || true)
        [[ $process_uid = "$uid" ]] || continue
        executable=$(readlink -f "$process/exe" 2>/dev/null || true)
        case ${executable##*/} in php | php[0-9]* | php-cgi | php-cgi[0-9]* | lsphp | lsphp[0-9]* | ea-php*) ;; *) continue ;; esac
        mapfile -d '' -t arguments < "$process/cmdline" || true
        for argument in "${arguments[@]}"; do
            case $argument in artisan | */artisan) running=true ;; esac
        done
    done
    [[ $running = true ]] || { echo 'Pilot account Artisan processes are quiescent.'; exit 0; }
    [[ $SECONDS -lt $deadline ]] || { echo 'Timed out waiting for pilot Artisan processes.' >&2; exit 1; }
    sleep "$interval"
done
