#!/usr/bin/env bash
#
# Wait for every already-running SVC Artisan invocation before shared storage
# is moved into the atomic deployment area. The shared action has already
# paused SVC's tagged cron lines and put the selected release into maintenance
# mode. Production intentionally has no queue worker, but an unexpected one (or
# a manual command) must fail closed here instead of racing a storage move.
#
#   quiesce.sh <candidate-path> <php> <stable-path>
set -euo pipefail

candidate_path=$1
php=$2
stable_path=$3

# The arguments are part of the shared action's host-hook contract. Validate
# them before inspecting processes so a malformed invocation cannot silently
# claim quiescence.
case $candidate_path in
    .deployments/svc-laravel/releases/*) ;;
    *) echo "::error::Unexpected SVC candidate path: $candidate_path" >&2; exit 2 ;;
esac
test -x "$php"
test "$stable_path" = svc-laravel

stable_root=$(readlink -f "$HOME/$stable_path")
test -n "$stable_root" -a -d "$stable_root"

uid=$(id -u)
deadline=$((SECONDS + 420))

while :; do
    running=()

    for process in /proc/[0-9]*; do
        test -r "$process/status" -a -r "$process/cmdline" || continue
        process_uid=$(awk '/^Uid:/ { print $2; exit }' "$process/status" 2>/dev/null || true)
        test "$process_uid" = "$uid" || continue

        command=$(tr '\0' ' ' <"$process/cmdline" 2>/dev/null || true)
        case " $command " in
            *" artisan "*) ;;
            *) continue ;;
        esac

        working_directory=$(readlink -f "$process/cwd" 2>/dev/null || true)
        case "$working_directory/" in
            "$stable_root/"*) running+=("${process##*/}") ;;
        esac
    done

    if [ "${#running[@]}" -eq 0 ]; then
        echo "SVC Artisan processes are quiescent."
        exit 0
    fi

    if [ "$SECONDS" -ge "$deadline" ]; then
        echo "::error::Timed out waiting for SVC Artisan process(es): ${running[*]}" >&2
        exit 1
    fi

    echo "Waiting for ${#running[@]} SVC Artisan process(es) to finish."
    sleep 5
done
