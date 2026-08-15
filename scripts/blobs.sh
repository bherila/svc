#!/usr/bin/env bash
#
# Sync private SVC files between authoritative web1 storage and x-data.
#
#   pnpm blobs pull            dry-run: web1 -> x-data
#   pnpm blobs pull --apply    execute
#   pnpm blobs verify          compare file count and bytes
#   pnpm blobs push --apply    restore path; rare, never prunes by default
set -euo pipefail

PROJECT="svc"
REMOTE_HOST="ssh-bwh-php"
REMOTE_PATH="svc-laravel/storage/app/private"

X_DATA="${X_DATA_DIR:-$HOME/proj/x-data}"
LOCAL_PATH="$X_DATA/$PROJECT"
REMOTE="${REMOTE_HOST}:${REMOTE_PATH}"

die() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }

usage() {
    sed -n '3,9p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-1}"
}

MODE="${1:-}"
shift || true
APPLY=0
PRUNE=0

for arg in "$@"; do
    case "$arg" in
        --apply) APPLY=1 ;;
        --prune) PRUNE=1 ;;
        -h|--help) usage 0 ;;
        *) die "unknown argument: $arg" ;;
    esac
done

RSYNC_OPTS=(-a --human-readable --itemize-changes --stats --exclude '.DS_Store' --exclude '.gitignore')
[ "$APPLY" -eq 1 ] && RSYNC_OPTS+=(--partial --progress) || RSYNC_OPTS+=(--dry-run)

case "$MODE" in
    pull)
        mkdir -p "$LOCAL_PATH"
        chmod 700 "$LOCAL_PATH"
        info "pull  ${REMOTE}/  ->  ${LOCAL_PATH}/   $([ "$APPLY" -eq 1 ] && echo '(APPLY)' || echo '(dry-run)')"
        rsync "${RSYNC_OPTS[@]}" --delete --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "${REMOTE}/" "${LOCAL_PATH}/"
        # openrsync does not reliably apply --chmod to unchanged entries during
        # an incremental pull. Normalize the complete local mirror after rsync.
        find "$LOCAL_PATH" -type d -exec chmod 700 {} +
        find "$LOCAL_PATH" -type f -exec chmod 600 {} +
        ;;
    push)
        [ -d "$LOCAL_PATH" ] || die "local mirror does not exist: $LOCAL_PATH — run 'pull' first"
        if [ -z "$(find "$LOCAL_PATH" -type f -print -quit)" ]; then
            die "local mirror is empty: $LOCAL_PATH — refusing to push (run 'pull' first)"
        fi
        if [ "$PRUNE" -eq 1 ]; then
            RSYNC_OPTS+=(--delete)
            if [ "$APPLY" -eq 1 ]; then
                printf '\033[31m--prune will DELETE remote files absent locally.\033[0m\n'
                read -r -p "Type the project name ($PROJECT) to continue: " confirm
                [ "$confirm" = "$PROJECT" ] || die "aborted"
            fi
        fi
        info "push  ${LOCAL_PATH}/  ->  ${REMOTE}/   $([ "$APPLY" -eq 1 ] && echo '(APPLY)' || echo '(dry-run)')$([ "$PRUNE" -eq 1 ] && echo ' (PRUNE)' || echo '')"
        rsync "${RSYNC_OPTS[@]}" "${LOCAL_PATH}/" "${REMOTE}/"
        ;;
    verify)
        info "comparing file count and bytes"
        remote_stat=$(ssh "$REMOTE_HOST" "find '$REMOTE_PATH' -type f ! -name .gitignore 2>/dev/null | wc -l; find '$REMOTE_PATH' -type f ! -name .gitignore -printf '%s\\n' 2>/dev/null | awk '{s+=\$1} END {print s+0}'")
        local_stat=$(printf '%s\n%s\n' \
            "$(find "$LOCAL_PATH" -type f ! -name .gitignore 2>/dev/null | wc -l | tr -d ' ')" \
            "$(find "$LOCAL_PATH" -type f ! -name .gitignore -print0 2>/dev/null | xargs -0 stat -f %z 2>/dev/null | awk '{s+=$1} END {print s+0}')")
        printf 'web1    %s files, %s bytes\n' $(echo "$remote_stat" | tr '\n' ' ')
        printf 'x-data  %s files, %s bytes\n' $(echo "$local_stat" | tr '\n' ' ')
        [ "$(echo "$remote_stat" | tr -d ' \n')" = "$(echo "$local_stat" | tr -d ' \n')" ] \
            && info "match" || die "MISMATCH — re-run 'pull --apply'"
        ;;
    *) usage ;;
esac
