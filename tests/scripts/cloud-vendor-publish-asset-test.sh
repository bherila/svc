#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/cloud/vendor-bundle-publish-asset.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cloud-vendor-publish.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

STUBS="$WORK/bin"
mkdir -p "$STUBS"
LOG="$WORK/calls"
BUNDLE="$WORK/bundle.tar.zst"
printf 'trusted bundle bytes' >"$BUNDLE"
SHA="$(sha256sum "$BUNDLE" | cut -d' ' -f1)"
NAME="vendor-$(printf lock | sha256sum | cut -d' ' -f1).tar.zst"
LABEL='cloud-vendor/v4 h=0123456789012345678901234567890123456789 s=0123456789012345678901234567890123456789 r=12345678901234567890 a=12345678901234567890 l=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa e=12345678901234567890'
CANDIDATE="$NAME.candidate-123-1"
BACKUP="$NAME.previous-123-1"

if (( ${#LABEL} > 255 )); then
  printf 'test fixture exceeds GitHub release label limit: %s characters\n' "${#LABEL}" >&2
  exit 1
fi

cat >"$STUBS/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
method=GET
payload=''
url=''
while [[ $# -gt 0 ]]; do
  case "$1" in
    -X)
      method="$2"
      shift 2
      ;;
    --data)
      payload="$2"
      shift 2
      ;;
    http*)
      url="$1"
      shift
      ;;
    *) shift ;;
  esac
done

json_asset() {
  NAME="$1" LABEL="$2" DIGEST="$3" ID="$4" php -r '
    $digest = getenv("DIGEST");
    echo json_encode([
        "id" => (int) getenv("ID"),
        "name" => getenv("NAME"),
        "label" => getenv("LABEL"),
        "digest" => $digest === "" ? null : "sha256:" . $digest,
    ], JSON_THROW_ON_ERROR);
  '
}

if [[ "$method" == POST ]]; then
  printf 'upload-candidate\n' >>"$CALL_LOG"
  if [[ "$SCENARIO" == upload_failure ]]; then
    printf '%s\n422' '{"message":"Validation Failed","errors":[{"resource":"ReleaseAsset","code":"already_exists","field":"name"}]}'
    exit 0
  fi
  json_asset "$CANDIDATE" "$LABEL" "$SHA" 20
  printf '\n201'
  exit 0
fi

next_name="$(PAYLOAD="$payload" php -r '$p=json_decode((string) getenv("PAYLOAD"), true); echo is_array($p) ? ($p["name"] ?? "") : "";')"
if [[ "$url" == */10 ]]; then
  if [[ "$next_name" == "$BACKUP" ]]; then
    printf 'rename-old-to-backup\n' >>"$CALL_LOG"
    if [[ "$SCENARIO" == rename_failure ]]; then
      printf '%s\n500' '{"message":"Internal Server Error"}'
      exit 0
    fi
  else
    printf 'restore-old-canonical\n' >>"$CALL_LOG"
  fi
  json_asset "$next_name" "$OLD_LABEL" "$OLD_PATCH_DIGEST" 10
  printf '\n200'
  exit 0
fi

if [[ "$next_name" == "$NAME" ]]; then
  printf 'promote-candidate\n' >>"$CALL_LOG"
  if [[ "$SCENARIO" == promotion_failure ]]; then
    printf '%s\n422' '{"message":"Validation Failed","errors":[{"resource":"ReleaseAsset","code":"already_exists","field":"name"}]}'
    exit 0
  fi
else
  printf 'demote-candidate\n' >>"$CALL_LOG"
fi
json_asset "$next_name" "$LABEL" "$SHA" 20
printf '\n200'
EOF

cat >"$STUBS/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
args="$*"
if [[ "$args" == *'/releases/assets/20'* && "$args" != *'DELETE'* ]]; then
  printf 'verify-candidate\n' >>"$CALL_LOG"
  NAME="$CANDIDATE" LABEL="$LABEL" DIGEST="$SHA" php -r '
    echo json_encode(["id" => 20, "name" => getenv("NAME"), "label" => getenv("LABEL"), "digest" => "sha256:" . getenv("DIGEST")], JSON_THROW_ON_ERROR);
  '
elif [[ "$args" == *'/assets?per_page=100'* ]]; then
  printf 'find-old-canonical\n' >>"$CALL_LOG"
  if [[ "$SCENARIO" != no_old ]]; then
    printf '10\n'
  fi
elif [[ "$args" == *'DELETE'*'/releases/assets/10'* ]]; then
  printf 'delete-old-backup\n' >>"$CALL_LOG"
elif [[ "$args" == *'DELETE'*'/releases/assets/20'* ]]; then
  printf 'delete-candidate\n' >>"$CALL_LOG"
elif [[ "$args" == *'/releases/assets/10'* ]]; then
  printf 'read-old-canonical\n' >>"$CALL_LOG"
  LABEL="$OLD_LABEL" DIGEST="$OLD_DIGEST" NAME="$NAME" php -r '
    $digest = getenv("DIGEST");
    echo json_encode(["id" => 10, "name" => getenv("NAME"), "label" => getenv("LABEL"), "digest" => $digest === "" ? null : "sha256:" . $digest], JSON_THROW_ON_ERROR);
  '
else
  printf 'unexpected gh call: %s\n' "$args" >&2
  exit 2
fi
EOF
chmod +x "$STUBS/curl" "$STUBS/gh" "$SCRIPT"

OLD_SHA="$(printf old | sha256sum | cut -d' ' -f1)"

assert_same_file() {
  php -r '
    $expected = file_get_contents($argv[1]);
    $actual = file_get_contents($argv[2]);
    if ($expected !== $actual) {
        fwrite(STDERR, "expected:\n" . $expected . "actual:\n" . $actual);
        exit(1);
    }
  ' "$1" "$2"
}

run_publisher() {
  local scenario="$1"
  local stdout="$2"
  local stderr="$3"
  local old_digest="$OLD_SHA"
  local old_patch_digest="$OLD_SHA"
  local old_label='old trusted label'
  if [[ "$scenario" == no_digest ]]; then
    old_digest=''
  fi
  if [[ "$scenario" == empty_metadata ]]; then
    old_digest=''
    old_label=''
  fi
  : >"$LOG"
  PATH="$STUBS:$PATH" \
  CALL_LOG="$LOG" \
  SCENARIO="$scenario" \
  REPO=bherila/svc \
  GH_TOKEN=test-token \
  GITHUB_RUN_ID=123 \
  GITHUB_RUN_ATTEMPT=1 \
  CLOUD_VENDOR_PUBLISH_RETRY_DELAYS=0 \
  NAME="$NAME" \
  CANDIDATE="$CANDIDATE" \
  BACKUP="$BACKUP" \
  LABEL="$LABEL" \
  SHA="$SHA" \
  OLD_DIGEST="$old_digest" \
  OLD_PATCH_DIGEST="$old_patch_digest" \
  OLD_LABEL="$old_label" \
    "$SCRIPT" 99 "$BUNDLE" "$NAME" "$LABEL" "$SHA" >"$stdout" 2>"$stderr"
}

run_publisher success "$WORK/success.out" "$WORK/success.err"
EXPECTED_SUCCESS="$WORK/expected-success"
cat >"$EXPECTED_SUCCESS" <<'EOF'
upload-candidate
verify-candidate
find-old-canonical
read-old-canonical
rename-old-to-backup
promote-candidate
delete-old-backup
EOF
assert_same_file "$EXPECTED_SUCCESS" "$LOG"
grep -q 'published canonical release asset' "$WORK/success.out"

run_publisher no_digest "$WORK/no-digest.out" "$WORK/no-digest.err"
assert_same_file "$EXPECTED_SUCCESS" "$LOG"
grep -q 'published canonical release asset' "$WORK/no-digest.out"

run_publisher empty_metadata "$WORK/empty-metadata.out" "$WORK/empty-metadata.err"
assert_same_file "$EXPECTED_SUCCESS" "$LOG"
grep -q 'published canonical release asset' "$WORK/empty-metadata.out"

run_publisher no_old "$WORK/no-old.out" "$WORK/no-old.err"
EXPECTED_NO_OLD="$WORK/expected-no-old"
cat >"$EXPECTED_NO_OLD" <<'EOF'
upload-candidate
verify-candidate
find-old-canonical
promote-candidate
EOF
assert_same_file "$EXPECTED_NO_OLD" "$LOG"
grep -q 'published canonical release asset' "$WORK/no-old.out"

if run_publisher upload_failure "$WORK/upload-failure.out" "$WORK/upload-failure.err"; then
  printf 'upload failure unexpectedly succeeded\n' >&2
  exit 1
fi
[[ "$(cat "$LOG")" == 'upload-candidate' ]]
grep -q 'already_exists' "$WORK/upload-failure.err"

if run_publisher promotion_failure "$WORK/promotion-failure.out" "$WORK/promotion-failure.err"; then
  printf 'promotion failure unexpectedly succeeded\n' >&2
  exit 1
fi
EXPECTED_ROLLBACK="$WORK/expected-rollback"
cat >"$EXPECTED_ROLLBACK" <<'EOF'
upload-candidate
verify-candidate
find-old-canonical
read-old-canonical
rename-old-to-backup
promote-candidate
demote-candidate
restore-old-canonical
delete-candidate
EOF
assert_same_file "$EXPECTED_ROLLBACK" "$LOG"
grep -q 'previous canonical asset restored' "$WORK/promotion-failure.err"

if run_publisher rename_failure "$WORK/rename-failure.out" "$WORK/rename-failure.err"; then
  printf 'rename failure unexpectedly succeeded\n' >&2
  exit 1
fi
EXPECTED_RENAME_ROLLBACK="$WORK/expected-rename-rollback"
cat >"$EXPECTED_RENAME_ROLLBACK" <<'EOF'
upload-candidate
verify-candidate
find-old-canonical
read-old-canonical
rename-old-to-backup
restore-old-canonical
delete-candidate
EOF
assert_same_file "$EXPECTED_RENAME_ROLLBACK" "$LOG"
grep -q 'previous canonical asset restored' "$WORK/rename-failure.err"

TOO_LONG_LABEL="$(php -r 'echo str_repeat("x", 256);')"
: >"$LOG"
if PATH="$STUBS:$PATH" \
  CALL_LOG="$LOG" \
  SCENARIO=no_old \
  REPO=bherila/svc \
  GH_TOKEN=test-token \
  GITHUB_RUN_ID=123 \
  GITHUB_RUN_ATTEMPT=1 \
  CLOUD_VENDOR_PUBLISH_RETRY_DELAYS=0 \
  NAME="$NAME" \
  CANDIDATE="$CANDIDATE" \
  BACKUP="$BACKUP" \
  LABEL="$TOO_LONG_LABEL" \
  SHA="$SHA" \
  OLD_DIGEST="$OLD_SHA" \
  OLD_PATCH_DIGEST="$OLD_SHA" \
  OLD_LABEL='old trusted label' \
    "$SCRIPT" 99 "$BUNDLE" "$NAME" "$TOO_LONG_LABEL" "$SHA" >"$WORK/long-label.out" 2>"$WORK/long-label.err"; then
  printf 'overlong label unexpectedly reached publication\n' >&2
  exit 1
fi
[[ ! -s "$LOG" ]]
grep -q '255' "$WORK/long-label.err"

printf 'ok   candidate bytes are verified before the canonical asset moves\n'
printf 'ok   a legacy canonical asset remains replaceable when its API digest appears after update\n'
printf 'ok   empty legacy digest and label metadata survive canonical replacement\n'
printf 'ok   a missing canonical asset is recovered by verified candidate promotion\n'
printf 'ok   upload API errors retain their validation body and leave the canonical asset untouched\n'
printf 'ok   failed promotion restores the prior canonical asset before removing the candidate\n'
printf 'ok   an uncertain backup rename is rolled back before the candidate is removed\n'
printf 'ok   release labels are rejected locally before GitHub\x27s 255-character limit\n'
