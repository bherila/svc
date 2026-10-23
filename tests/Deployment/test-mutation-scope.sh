#!/usr/bin/env bash
# The local runner must match the hosted application scope, without masking errors.
set -euo pipefail
repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/checkout/"{app,scripts,storage/framework/cache/infection-diff} "$fixture/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }
cat >"$fixture/bin/php" <<'MOCK'
#!/usr/bin/env bash
case "$*" in
    *vendor/bin/infection*) echo infection >>"$FIXTURE_PHP_LOG"; exit 73 ;;
    *scripts/mutation-gate.php*) echo gate >>"$FIXTURE_PHP_LOG" ;;
    *) exit 99 ;;
esac
MOCK
chmod +x "$fixture/bin/php"
git_fixture() {
    git -C "$fixture/checkout" -c user.name='Mutation fixture' -c user.email=mutation@example.test \
        -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"
}
git_fixture init -q
printf '<?php\n// Synthetic baseline.\n' >"$fixture/checkout/app/Original.php"
printf '<?php\n// Synthetic tooling.\n' >"$fixture/checkout/scripts/tool.php"
git_fixture add .
git_fixture commit -qm baseline
base=$(git_fixture rev-parse HEAD)
run_scope() {
    rm -f "$fixture/php.log"
    (cd "$fixture/checkout" && PATH="$fixture/bin:$PATH" MUTATION_BASE="${FIXTURE_BASE:-$base}" \
        FIXTURE_PHP_LOG="$fixture/php.log" bash "$repository/scripts/run-mutation-diff.sh") >"$fixture/output" 2>&1
}
expect_application_scope() {
    local status=0
    run_scope || status=$?
    test "$status" -eq 73 || fail 'an application change skipped Infection or hid its failure'
    printf 'infection\ngate\n' | cmp -s - "$fixture/php.log" || fail 'application scope did not run both gates'
}

printf '// Tool-only change.\n' >>"$fixture/checkout/scripts/tool.php"
git_fixture add .
git_fixture commit -qm tooling
printf 'stale summary\n' >"$fixture/checkout/storage/framework/cache/infection-diff/summary.json"
run_scope || fail 'tool-only diff was treated as an application mutation failure'
test ! -e "$fixture/php.log" || fail 'tool-only diff invoked PHP'
test ! -e "$fixture/checkout/storage/framework/cache/infection-diff/summary.json" || fail 'stale mutation summary survived'
grep -Fq 'Mutation gate not applicable' "$fixture/output" || fail 'scope was silently skipped'

printf '// Application change.\n' >>"$fixture/checkout/app/Original.php"
git_fixture add .
git_fixture commit -qm application
expect_application_scope
git_fixture reset --hard -q "$base"
git_fixture mv app/Original.php app/Renamed.php
git_fixture commit -qm rename
expect_application_scope
git_fixture reset --hard -q "$base"
git_fixture mv scripts/tool.php app/Imported.php
git_fixture commit -qm imported-source
expect_application_scope

FIXTURE_BASE=nonexistent-synthetic-ref run_scope && fail 'invalid Git base passed'
test ! -e "$fixture/php.log" || fail 'invalid Git base invoked PHP'
echo 'Local mutation scope tests passed.'
