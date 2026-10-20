#!/usr/bin/env bash
#
# svc's candidate checks after migration and config cache, before activation.
#
# Runs ON THE HOST as the shared deploy action's post-deploy-script:
#
#   post-deploy.sh <candidate-path> <php> <stable-path>
set -euo pipefail

app_dir=$1
php=$2
stable_dir=$3
test "$stable_dir" = svc-laravel
cd "$HOME/$app_dir"

"$php" artisan svc:storage:status --apply --format=json

# The Stripe, Brevo and Square probes exit 1 for "not configured" (Square also for a failed last sync),
# which is not a deploy failure; anything else is. A probe that crashed also exits 1, so 1 counts as a
# report only when the probe printed its JSON object.
for probe in svc:stripe:status svc:brevo:status svc:square:status; do
    status=0
    output=$("$php" artisan "$probe" --format=json) || status=$?
    if [ "$status" -eq 1 ] && ! printf '%s' "$output" | "$php" -r 'exit(json_decode(stream_get_contents(STDIN)) instanceof stdClass ? 0 : 1);'; then
        printf '%s\n' "$output"
        echo "::error::$probe exited 1 without its JSON report." >&2
        exit 1
    fi
    if [ "$status" -ne 0 ] && [ "$status" -ne 1 ]; then
        printf '%s\n' "$output"
        echo "::error::$probe exited $status." >&2
        exit "$status"
    fi
    printf '%s\n' "$output"
done
