#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/cloud/vendor-bundle-refresh-plan.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cloud-vendor-refresh-plan.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

if grep -Fq '<(' "$SCRIPT" || grep -Fq '>(' "$SCRIPT"; then
  printf 'cloud-vendor refresh plan must not use process substitution; CodeBuild Lambda has no /dev/fd\n' >&2
  exit 1
fi

REPO="$WORK/repo"
STUBS="$WORK/bin"
mkdir -p "$REPO" "$STUBS"
git -C "$REPO" init -q
git -C "$REPO" config user.name Test
git -C "$REPO" config user.email test@example.com

write_lock() {
  printf '{"packages":[],"marker":"%s"}\n' "$1" >"$REPO/composer.lock"
  git -C "$REPO" add composer.lock
  git -C "$REPO" commit -qm "$1"
}

write_lock first
FIRST_SHA="$(git -C "$REPO" rev-parse HEAD)"
FIRST_LOCK="$(sha256sum "$REPO/composer.lock" | cut -d' ' -f1)"
write_lock retained
RETAINED_SHA="$(git -C "$REPO" rev-parse HEAD)"
RETAINED_LOCK="$(sha256sum "$REPO/composer.lock" | cut -d' ' -f1)"
write_lock current
CURRENT_SHA="$(git -C "$REPO" rev-parse HEAD)"
CURRENT_LOCK="$(sha256sum "$REPO/composer.lock" | cut -d' ' -f1)"
UNKNOWN_LOCK="$(printf attacker | sha256sum | cut -d' ' -f1)"

json_length() {
  php -r 'echo count(json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));' "$1"
}

target_source() {
  php -r '
    foreach (json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR) as $target) {
        if ($target["lock"] === $argv[2]) { echo $target["source_sha"]; }
    }
  ' "$1" "$2"
}

has_target() {
  [[ -n "$(target_source "$1" "$2")" ]]
}

cat >"$STUBS/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
args="$*"
if [[ "$args" == *'/releases?per_page=100'* ]]; then
  printf '41\n'
elif [[ "$args" == *'/releases/41/assets?per_page=100'* ]]; then
  printf '[[{"name":"vendor-%s.tar.zst","label":"cloud-vendor/v3 forged","created_at":"2026-09-03T00:00:00Z"},{"name":"vendor-%s.tar.zst","label":"attacker-controlled","created_at":"2026-09-02T00:00:00Z"},{"name":"vendor-%s.tar.zst","created_at":"2026-09-01T00:00:00Z"}]]' \
    "$UNKNOWN_LOCK" "$RETAINED_LOCK" "$FIRST_LOCK"
else
  printf 'unexpected gh call: %s\n' "$args" >&2
  exit 2
fi
EOF
chmod +x "$STUBS/gh" "$SCRIPT"

OUTPUT="$WORK/output"
LOG="$WORK/log"
(
  cd "$REPO"
  PATH="$STUBS:$PATH" \
  REPO=bherila/svc \
  TAG=cloud-vendor \
  KEEP=20 \
  GITHUB_SHA="$CURRENT_SHA" \
  GITHUB_OUTPUT="$OUTPUT" \
  RETAINED_LOCK="$RETAINED_LOCK" \
  UNKNOWN_LOCK="$UNKNOWN_LOCK" \
  FIRST_LOCK="$FIRST_LOCK" \
    "$SCRIPT" retained
) >"$LOG"

targets="$(sed -n 's/^targets=//p' "$OUTPUT")"
[[ "$(json_length "$targets")" == 3 ]]
[[ "$(target_source "$targets" "$CURRENT_LOCK")" == "$CURRENT_SHA" ]]
[[ "$(target_source "$targets" "$RETAINED_LOCK")" == "$RETAINED_SHA" ]]
[[ "$(target_source "$targets" "$FIRST_LOCK")" == "$FIRST_SHA" ]]
! has_target "$targets" "$UNKNOWN_LOCK"
grep -q "has no matching composer.lock in trusted main ancestry" "$LOG"

LIMITED_OUTPUT="$WORK/limited-output"
(
  cd "$REPO"
  PATH="$STUBS:$PATH" \
  REPO=bherila/svc \
  TAG=cloud-vendor \
  KEEP=2 \
  GITHUB_SHA="$CURRENT_SHA" \
  GITHUB_OUTPUT="$LIMITED_OUTPUT" \
  RETAINED_LOCK="$RETAINED_LOCK" \
  UNKNOWN_LOCK="$UNKNOWN_LOCK" \
  FIRST_LOCK="$FIRST_LOCK" \
    "$SCRIPT" retained
) >/dev/null
limited_targets="$(sed -n 's/^targets=//p' "$LIMITED_OUTPUT")"
[[ "$(json_length "$limited_targets")" == 2 ]]
[[ "$(target_source "$limited_targets" "$CURRENT_LOCK")" == "$CURRENT_SHA" ]]
[[ "$(target_source "$limited_targets" "$RETAINED_LOCK")" == "$RETAINED_SHA" ]]
! has_target "$limited_targets" "$UNKNOWN_LOCK"

printf 'ok   retained lock names are rebuilt only from matching trusted main ancestors\n'
printf 'ok   mutable release labels are not used as source authority\n'
printf 'ok   unbuildable newer assets do not consume retained refresh capacity\n'
printf 'ok   Lambda refresh planning does not require /dev/fd process substitution\n'
