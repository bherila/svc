#!/usr/bin/env bash
# Pull a verified, point-in-time SVC database snapshot from web1 into x-data.
#
#   pnpm db-snapshot pull            # show the plan; no writes
#   pnpm db-snapshot pull --apply    # export remotely, verify, and download
#   pnpm db-snapshot verify          # verify the current local snapshot
#
# There is deliberately no push or restore mode. web1 remains authoritative.
set -euo pipefail

PROJECT="svc"
REMOTE_HOST="ssh-bwh-php"
REMOTE_PROJECT_PATH="svc-laravel"
X_DATA="${X_DATA_DIR:-$HOME/proj/x-data}"
LOCAL_DIRECTORY="${DB_SNAPSHOT_DIR:-$X_DATA/${PROJECT}-database}"

die() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }

usage() {
    sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-1}"
}

sha256_file() {
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
    else
        sha256sum "$1" | awk '{print $1}'
    fi
}

case "$LOCAL_DIRECTORY/" in
    "$X_DATA/$PROJECT/"*)
        die "database snapshots must stay outside the rsync-managed $X_DATA/$PROJECT tree"
        ;;
esac

verify_snapshot() {
    local snapshot_path="$1"
    local checksum_path="${snapshot_path}.sha256"
    local manifest_path="${snapshot_path}.manifest"

    [[ -f "$snapshot_path" ]] || die "snapshot is missing: $snapshot_path"
    [[ -f "$checksum_path" ]] || die "checksum is missing: $checksum_path"
    [[ -f "$manifest_path" ]] || die "manifest is missing: $manifest_path"
    gzip -t "$snapshot_path" || die "snapshot gzip verification failed"

    local expected_checksum
    local actual_checksum
    local manifest_filename
    local manifest_checksum

    expected_checksum="$(awk 'NR == 1 {print $1}' "$checksum_path")"
    [[ "$expected_checksum" =~ ^[0-9a-f]{64}$ ]] || die "invalid snapshot checksum"
    actual_checksum="$(sha256_file "$snapshot_path")"
    [[ "$actual_checksum" == "$expected_checksum" ]] || die "snapshot checksum mismatch"

    manifest_filename="$(sed -n 's/^filename=//p' "$manifest_path")"
    manifest_checksum="$(sed -n 's/^sha256=//p' "$manifest_path")"
    [[ "$manifest_filename" == "$(basename "$snapshot_path")" ]] || die "manifest filename mismatch"
    [[ "$manifest_checksum" == "$actual_checksum" ]] || die "manifest checksum mismatch"
    info "verified: $snapshot_path"
}

MODE="${1:-}"
shift || true
APPLY=0
for argument in "$@"; do
    case "$argument" in
        --apply) APPLY=1 ;;
        -h|--help) usage 0 ;;
        *) die "unknown argument: $argument" ;;
    esac
done

case "$MODE" in
    verify)
        [[ "$APPLY" -eq 0 ]] || die "verify does not accept --apply"
        current_path="$LOCAL_DIRECTORY/${PROJECT}-current.sql.gz"
        [[ -L "$current_path" ]] || die "current snapshot link is missing: $current_path"
        current_target="$(readlink "$current_path")"
        [[ "$current_target" =~ ^${PROJECT}-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z\.sql\.gz$ ]] \
            || die "current snapshot link has an unexpected target"
        verify_snapshot "$LOCAL_DIRECTORY/$current_target"
        exit 0
        ;;
    pull) ;;
    *) usage ;;
esac

if [[ "$APPLY" -ne 1 ]]; then
    info "pull  ${REMOTE_HOST}:${REMOTE_PROJECT_PATH}/.db-credentials  ->  ${LOCAL_DIRECTORY}/  (dry-run)"
    info "would create a remote consistent MySQL dump, gzip + SHA-256 verify it, then download it"
    info "no restore or push mode exists"
    exit 0
fi

mkdir -p "$LOCAL_DIRECTORY"
chmod 700 "$LOCAL_DIRECTORY"

remote_directory=""
cleanup_remote() {
    [[ -n "$remote_directory" ]] || return 0
    ssh "$REMOTE_HOST" "find '$remote_directory' -type f -delete && rmdir -- '$remote_directory'" >/dev/null 2>&1 || true
}
trap cleanup_remote EXIT

remote_directory_candidate="$(ssh "$REMOTE_HOST" "umask 077 && mktemp -d '/tmp/${PROJECT}-db-snapshot.XXXXXX'")"
[[ "$remote_directory_candidate" =~ ^/tmp/${PROJECT}-db-snapshot\.[[:alnum:]]+$ ]] \
    || die "unexpected remote snapshot directory"
remote_directory="$remote_directory_candidate"

remote_filename="$(ssh "$REMOTE_HOST" bash -s -- "$REMOTE_PROJECT_PATH" "$remote_directory" <<'REMOTE_SCRIPT'
set -euo pipefail

remote_project_path="$1"
remote_directory="$2"
cd "$remote_project_path"
[[ -f .db-credentials ]] || { printf 'missing .db-credentials\n' >&2; exit 1; }

set -a
# shellcheck disable=SC1091
source .db-credentials
set +a
for required_name in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    [[ -n "${!required_name:-}" ]] || { printf 'missing required database setting: %s\n' "$required_name" >&2; exit 1; }
done

timestamp="$(date -u +%Y-%m-%dT%H%M%SZ)"
filename="svc-${timestamp}.sql.gz"
snapshot_path="${remote_directory}/${filename}"

umask 077
MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --quick \
    --skip-lock-tables \
    --no-tablespaces \
    --triggers \
    --hex-blob \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -9 > "${snapshot_path}.part"
gzip -t "${snapshot_path}.part"
mv -- "${snapshot_path}.part" "$snapshot_path"
checksum="$(sha256sum "$snapshot_path" | awk '{print $1}')"
bytes="$(stat -c %s "$snapshot_path")"
printf '%s  %s\n' "$checksum" "$filename" > "${snapshot_path}.sha256"
printf 'format=mysql-sql-gzip-v1\nfilename=%s\nsnapshot_started_at=%s\nsha256=%s\nbytes=%s\n' \
    "$filename" "$timestamp" "$checksum" "$bytes" > "${snapshot_path}.manifest"
printf '%s\n' "$filename"
REMOTE_SCRIPT
)"
[[ "$remote_filename" =~ ^${PROJECT}-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z\.sql\.gz$ ]] \
    || die "remote exporter returned an invalid filename"

local_path="$LOCAL_DIRECTORY/$remote_filename"
[[ ! -e "$local_path" && ! -e "${local_path}.part" ]] || die "snapshot already exists locally"

newest_local=""
for candidate_path in "$LOCAL_DIRECTORY/${PROJECT}"-*.sql.gz; do
    [[ -f "$candidate_path" ]] || continue
    candidate="$(basename "$candidate_path")"
    [[ "$candidate" =~ ^${PROJECT}-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z\.sql\.gz$ ]] || continue
    [[ "$candidate" > "$newest_local" ]] && newest_local="$candidate"
done
if [[ -n "$newest_local" && "$newest_local" > "$remote_filename" ]]; then
    die "newer local snapshot exists; refusing an out-of-order pull"
fi

info "pull  ${REMOTE_HOST}:${remote_directory}/${remote_filename}  ->  ${local_path}  (APPLY)"
scp "${REMOTE_HOST}:${remote_directory}/${remote_filename}" "${local_path}.part"
scp "${REMOTE_HOST}:${remote_directory}/${remote_filename}.sha256" "${local_path}.sha256.part"
scp "${REMOTE_HOST}:${remote_directory}/${remote_filename}.manifest" "${local_path}.manifest.part"
mv -- "${local_path}.part" "$local_path"
mv -- "${local_path}.sha256.part" "${local_path}.sha256"
mv -- "${local_path}.manifest.part" "${local_path}.manifest"
chmod 600 "$local_path" "${local_path}.sha256" "${local_path}.manifest"
verify_snapshot "$local_path"
ln -sfn "$remote_filename" "$LOCAL_DIRECTORY/${PROJECT}-current.sql.gz"
info "snapshot ready: $local_path"
