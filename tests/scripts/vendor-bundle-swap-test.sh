#!/usr/bin/env bash
# Exercise the vendor/ swap in scripts/cloud/vendor-bundle.sh -- above all its
# ROLLBACK, which a normal install never runs and which was therefore written
# but never executed.
#
#   bash tests/scripts/vendor-bundle-swap-test.sh
#
# No network, no real bundle, no dependence on how the machine's filesystems
# are laid out. The script is SOURCED, which stops at the guard just past
# swap_in_vendor()/cleanup(), and each case runs in its own throwaway checkout
# in a subshell. Failure is injected by shadowing `mv` with a shell function,
# so the cross-filesystem copy this rollback exists for does not have to be
# reproduced to test what happens when it dies.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/cloud/vendor-bundle.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/vendor-bundle-swap-test.XXXXXX")" || exit 1
trap 'rm -rf "$WORK"' EXIT

FAILURES=0
fail() {
  printf 'FAIL: %s\n' "$*" >&2
  FAILURES=$((FAILURES + 1))
}
check() {
  # check <description> <test-command...>
  local what="$1"
  shift
  if "$@"; then
    printf 'ok   %s\n' "$what"
  else
    fail "$what"
  fi
}

# A checkout with an existing vendor/ and a staged replacement, matching the
# layout swap_in_vendor() expects: $STAGE is a sibling of vendor/.
new_checkout() {
  local dir
  dir="$(mktemp -d "$WORK/checkout.XXXXXX")"
  mkdir -p "$dir/vendor" "$dir/.vendor-bundle-stage.test/unpack/vendor"
  printf 'old\n' >"$dir/vendor/autoload.php"
  printf 'old\n' >"$dir/vendor/marker"
  printf 'new\n' >"$dir/.vendor-bundle-stage.test/unpack/vendor/autoload.php"
  printf 'new\n' >"$dir/.vendor-bundle-stage.test/unpack/vendor/marker"
  printf '%s' "$dir"
}

# Load say(), swap_in_vendor() and cleanup() without running an install.
load() {
  # Each case runs in its own subshell, which inherits the parent's tally.
  FAILURES=0
  STAGE=".vendor-bundle-stage.test"
  OLD="vendor.replaced.test"
  KEEP_BACKUP=0
  LOG="$PWD/swap.log"
  : >"$LOG"
  # shellcheck source=/dev/null
  . "$SCRIPT"
}

no_nested_backup() {
  # The bug this whole change is about: the backup ending up INSIDE vendor/.
  [[ -z "$(find vendor -maxdepth 1 -name 'vendor.replaced.*' -print -quit 2>/dev/null)" ]]
}

printf '== sourcing the script defines the swap helpers without installing\n'
(
  cd "$(new_checkout)" || exit 1
  load
  [[ "$(type -t swap_in_vendor)" == function ]] || exit 1
  [[ "$(type -t cleanup)" == function ]] || exit 1
  # Nothing may have been touched by merely sourcing it.
  [[ "$(cat vendor/marker)" == old ]] || exit 1
) && printf 'ok   sourcing defines swap_in_vendor() and cleanup() and installs nothing\n' \
  || fail "sourcing defines swap_in_vendor() and cleanup() and installs nothing"

printf '\n== the happy path swaps the staged tree in\n'
(
  cd "$(new_checkout)" || exit 1
  load
  swap_in_vendor || exit 1
  check "vendor/ is the staged tree" test "$(cat vendor/marker)" = new
  check "the backup is gone" test ! -e "$OLD"
  check "the stage no longer holds a vendor/" test ! -e "$STAGE/unpack/vendor"
  exit "$FAILURES"
) || fail "happy path"

printf '\n== a failed swap puts the previous vendor/ back\n'
(
  cd "$(new_checkout)" || exit 1
  load
  # Fail only the move of the staged tree into place, exactly as an
  # interrupted cross-filesystem copy would, and leave a partial destination
  # behind -- which is what made the old rollback bury the backup inside it.
  mv() {
    if [[ "${1:-}" == *"/unpack/vendor" && "${2:-}" == vendor ]]; then
      mkdir -p vendor
      printf 'partial\n' >vendor/half-copied
      return 1
    fi
    command mv "$@"
  }
  swap_in_vendor
  rc=$?
  check "the swap reports failure" test "$rc" -ne 0
  check "vendor/autoload.php is back" test -f vendor/autoload.php
  check "vendor/ is the tree that was here before" test "$(cat vendor/marker)" = old
  check "the partial copy is gone" test ! -e vendor/half-copied
  check "the backup is not nested inside vendor/" no_nested_backup
  check "the backup directory is gone" test ! -e "$OLD"
  check "KEEP_BACKUP stayed 0" test "$KEEP_BACKUP" = 0
  exit "$FAILURES"
) || fail "rollback restores the previous tree"

printf '\n== when the restore fails too, the backup is named and kept\n'
(
  cd "$(new_checkout)" || exit 1
  load
  # Both moves onto vendor/ fail: the swap and then the restore.
  mv() {
    [[ "${2:-}" == vendor ]] && return 1
    command mv "$@"
  }
  # Not `$(swap_in_vendor)`: a command substitution is a subshell, and
  # KEEP_BACKUP would be set in it and lost.
  swap_in_vendor >swap.out 2>&1
  rc=$?
  output="$(cat swap.out)"
  check "the swap reports failure" test "$rc" -ne 0
  check "the backup still exists" test -d "$OLD"
  check "KEEP_BACKUP is set" test "$KEEP_BACKUP" = 1
  check "the operator is told where the backup is" \
    bash -c '[[ "$1" == *"$2"* ]]' _ "$output" "$OLD"
  # The point of KEEP_BACKUP: cleanup() must not delete the only good copy.
  unset -f mv
  cleanup
  check "cleanup() left the backup alone" test -f "$OLD/autoload.php"
  check "cleanup() still removed the stage" test ! -e "$STAGE"
  exit "$FAILURES"
) || fail "a failed restore keeps and names the backup"

printf '\n== cleanup() removes both transient trees on a normal exit\n'
(
  cd "$(new_checkout)" || exit 1
  load
  command mv vendor "$OLD"
  cleanup
  check "the stage is removed" test ! -e "$STAGE"
  check "the backup is removed" test ! -e "$OLD"
  exit "$FAILURES"
) || fail "cleanup removes both transient trees"

printf '\n== the stage is a sibling of vendor/, and gitignored\n'
check "the stage is created inside the checkout, not under TMPDIR" \
  grep -q '^STAGE="\.vendor-bundle-stage\.\$\$"$' "$SCRIPT"
check "the stage is gitignored" \
  grep -q '^/\.vendor-bundle-stage\.\*$' "$ROOT/.gitignore"

printf '\n'
if [[ "$FAILURES" -gt 0 ]]; then
  printf '%d check(s) failed\n' "$FAILURES" >&2
  exit 1
fi
printf 'all vendor-bundle swap checks passed\n'
