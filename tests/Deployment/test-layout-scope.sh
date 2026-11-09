#!/usr/bin/env bash
# The layout shards skip only a change no rendered screen can read; anything
# else, and any Git failure, must run them.
set -euo pipefail
repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
# shellcheck source=../../scripts/layout-scope.sh
source "$repository/scripts/layout-scope.sh"

git -C "$fixture" init -q
commit() { git -C "$fixture" -c user.name='Layout fixture' -c user.email=layout@example.test commit -q --allow-empty -m "$1"; }
mkdir -p "$fixture/docs" "$fixture/tests/Feature" "$fixture/app" "$fixture/resources/js" "$fixture/.github/workflows" "$fixture/scripts"
echo base >"$fixture/docs/a.md"; git -C "$fixture" add -A; commit base
base=$(git -C "$fixture" rev-parse HEAD)

expect() {
    local label="$1" required="$2"
    cd "$fixture"
    layout_scope "$base" HEAD || fail "$label: scope failed"
    cd - >/dev/null
    [ "$layout_scope_required" = "$required" ] || fail "$label: required=$layout_scope_required, expected $required"
    git -C "$fixture" reset -q --hard "$base"
}
change() { for path in "$@"; do mkdir -p "$fixture/$(dirname "$path")"; echo changed >>"$fixture/$path"; done; git -C "$fixture" add -A; commit change; }

change docs/a.md README.md tests/Feature/XTest.php .github/workflows/other.yml phpstan.neon
expect 'docs, tests and other workflows' false
change app/Services/Billing/X.php
expect 'application code seeds and renders the screens' true
change resources/js/app.tsx
expect 'the frontend' true
change resources/docs/guide.md
expect 'Markdown under resources' true
change docs/help/time.md
expect 'a help article the help panel bundles' true
change .github/workflows/tests.yml
expect 'this workflow' true
change scripts/check-layout.mjs
expect 'the harness' true
change docs/a.md routes/web.php
expect 'one relevant file among irrelevant ones' true

# A Git failure must reach the caller rather than publish a not-required result.
if (cd "$fixture" && layout_scope 0000000000000000000000000000000000000000 HEAD) 2>/dev/null; then
    fail 'an unknown base must fail'
fi
echo 'layout scope: ok'
