#!/usr/bin/env bash
#
# Read-only proof that the account crontab holds exactly one SVC cron line, and that it is the
# canonical 1G scheduler line. Nothing on the deployment runs a queue worker, so this line is
# SVC's only background runner: zero lines means scheduled work silently stops, and two means it
# runs twice.
#
# Runs ON THE HOST, fed over SSH by the runner-side deploy and diagnostic scripts:
#
#   ssh <target> "bash -s -- svc-laravel <php>" < scripts/deploy/cron-audit.sh
#
# The shared deploy action installs the line (install-cron, after serving is proven) and replaces
# legacy lines run from cd "$HOME/svc-laravel". This script never writes the crontab. A line is
# SVC's when it names the svc-laravel directory as a whole path component, in any spelling (~,
# $HOME, an absolute home or a release path), or carries SVC's scheduler job id. Other
# applications' lines, including a sibling such as svc-laravel-two, are not counted.
#
# Output is fixed labels and a count only: never a crontab line, path or value.
set -euo pipefail

fail() {
    echo "SVC scheduler cron: $1." >&2
    exit 1
}

test "$#" -eq 2 || fail 'audit arguments rejected'
stable_dir=$1
php=$2
test "$stable_dir" = svc-laravel || fail 'audit arguments rejected'
case $php in
    /*[!A-Za-z0-9._/-]* | /) fail 'audit arguments rejected' ;;
    /*) ;;
    *) fail 'audit arguments rejected' ;;
esac

command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable on this host'
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
if ! crontab -l >"$work/current" 2>"$work/error"; then
    grep -qi 'no crontab for' "$work/error" || fail 'the account crontab could not be read'
    : >"$work/current"
fi

# Exactly what the pinned action's prepare-cron-lines.sh renders for deploy-dir svc-laravel,
# cron-memory-limit 1G and the default /dev/null scheduler log.
# shellcheck disable=SC2016 # $HOME is for cron to expand; it is compared literally here.
canonical='* * * * * cd "$HOME/'"$stable_dir"'" && '"$php"' -d memory_limit=1G artisan schedule:run > /dev/null 2>&1 # JOB:'"$stable_dir"'-scheduler'

counts=$(LC_ALL=C awk -v app="$stable_dir" -v canonical="$canonical" '
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
    function has_job(line,   tag) {
        tag = "# JOB:" app "-scheduler"
        return length(line) >= length(tag) && substr(line, length(line) - length(tag) + 1) == tag
    }
    /^[[:space:]]*(#|$)/ { next }
    /^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=/ { next }
    names_app($0) || has_job($0) { total++; if ($0 == canonical) exact++ }
    END { printf "%d %d\n", total, exact }
' "$work/current")
read -r total exact <<<"$counts"

case "$total:$exact" in
    1:1) echo 'SVC scheduler cron: exactly one canonical line.' ;;
    0:*) fail 'no SVC scheduler line is installed' ;;
    1:0) fail 'the only SVC line is not the canonical 1G scheduler line' ;;
    *) fail "$total SVC lines are installed; exactly one canonical scheduler line is required" ;;
esac
