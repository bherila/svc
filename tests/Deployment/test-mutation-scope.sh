#!/usr/bin/env bash
# Exercise the same detector through the runner and the actual hosted run block.
set -euo pipefail
repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/checkout/"{app,scripts,storage/framework/cache/infection-diff} "$fixture/bin"
fail() { echo "FAIL: $*" >&2; exit 1; }
real_git=$(command -v git)
cat >"$fixture/bin/php" <<'MOCK'
#!/usr/bin/env bash
case "$*" in
    *vendor/bin/phpunit*) echo baseline >>"$FIXTURE_PHP_LOG"; exit "${FIXTURE_BASELINE_STATUS:-0}" ;;
    *vendor/bin/infection*) echo infection >>"$FIXTURE_PHP_LOG"; exit "${FIXTURE_INFECTION_STATUS:-73}" ;;
    *scripts/mutation-gate.php*) echo gate >>"$FIXTURE_PHP_LOG"; exit "${FIXTURE_GATE_STATUS:-0}" ;;
    *) exit 99 ;;
esac
MOCK
cat >"$fixture/bin/git" <<'MOCK'
#!/usr/bin/env bash
case "${FIXTURE_GIT_FAILURE:-}" in
    merge-base) if [ "$1" = merge-base ]; then exit 74; fi ;;
    diff) if [[ " $* " == *' diff '* ]]; then exit 75; fi ;;
    malformed) if [[ " $* " == *' diff '* ]]; then echo 'unclassifiable diff'; exit 0; fi ;;
esac
exec "$FIXTURE_REAL_GIT" "$@"
MOCK
chmod +x "$fixture/bin/php" "$fixture/bin/git"
cp "$repository/scripts/mutation-scope.sh" "$fixture/checkout/scripts/"
# Run the checked-in Actions step itself, so helper wiring and published outputs
# cannot drift from what local fixtures exercise. Fail if the step disappears.
awk '
    $0 == "      - name: Detect mutation-tested changes" { step = 1; next }
    step && $0 == "        run: |" { body = 1; next }
    body && /^          / { sub(/^          /, ""); print; next }
    body && NF == 0 { print; next }
    body { exit }
' "$repository/.github/workflows/tests.yml" >"$fixture/hosted.sh"
test -s "$fixture/hosted.sh" || fail 'hosted detection step not found'
git_fixture() {
    "$real_git" -C "$fixture/checkout" -c user.name='Mutation fixture' -c user.email=mutation@example.test \
        -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"
}
git_fixture init -q
cat >"$fixture/checkout/app/Original.php" <<'PHP'
<?php
function synthetic_retained(): int { return 1; }
function synthetic_removed(): int { return 2; }
PHP
printf '<?php\n// Synthetic tooling.\n' >"$fixture/checkout/scripts/tool.php"
git_fixture add .
git_fixture commit -qm baseline
base=$(git_fixture rev-parse HEAD)
run_scope() {
    rm -f "$fixture/php.log"
    (cd "$fixture/checkout" && PATH="$fixture/bin:$PATH" MUTATION_BASE="${FIXTURE_BASE:-$base}" \
        FIXTURE_PHP_LOG="$fixture/php.log" FIXTURE_REAL_GIT="$real_git" \
        bash "$repository/scripts/run-mutation-diff.sh") >"$fixture/output" 2>&1
}
run_hosted() {
    rm -f "$fixture/github-output" "$fixture/github-summary"
    (cd "$fixture/checkout" && PATH="$fixture/bin:$PATH" BASE_SHA="${FIXTURE_BASE:-$base}" HEAD_SHA=HEAD \
        GITHUB_OUTPUT="$fixture/github-output" GITHUB_STEP_SUMMARY="$fixture/github-summary" \
        FIXTURE_REAL_GIT="$real_git" bash -e "$fixture/hosted.sh") >"$fixture/hosted-output" 2>&1
}
expect_application_scope() {
    local status=0
    run_scope || status=$?
    test "$status" -eq 73 || fail 'an applicable change skipped Infection or hid its failure'
    printf 'baseline\ninfection\ngate\n' | cmp -s - "$fixture/php.log" || fail 'applicable scope did not run both gates'
    run_hosted || fail 'hosted applicable detection failed'
    grep -Fxq 'required=true' "$fixture/github-output" || fail 'hosted detector skipped applicable source'
    grep -Fxq "merge_base=$base" "$fixture/github-output" || fail 'hosted detector changed the merge base'
}
expect_not_applicable() {
    printf 'stale summary\n' >"$fixture/checkout/storage/framework/cache/infection-diff/summary.json"
    run_scope || fail 'a diff without surviving changed application lines failed'
    test ! -e "$fixture/php.log" || fail 'not-applicable diff invoked PHP'
    test ! -e "$fixture/checkout/storage/framework/cache/infection-diff/summary.json" || fail 'stale mutation summary survived'
    grep -Fq 'Mutation gate not applicable' "$fixture/output" || fail 'local scope was silently skipped'
    run_hosted || fail 'hosted not-applicable detection failed'
    grep -Fxq 'required=false' "$fixture/github-output" || fail 'hosted detector required absent source lines'
    grep -Fq 'Not applicable:' "$fixture/github-summary" || fail 'hosted skip has no honest summary'
    grep -Fq 'No mutation summary or score is produced.' "$fixture/github-summary" || fail 'hosted skip implies a measured score'
}
commit_fixture() { git_fixture add -A; git_fixture commit -qm "$1"; }
reset_fixture() { git_fixture reset --hard -q "$base"; }

expect_not_applicable
printf '// Tool-only change.\n' >>"$fixture/checkout/scripts/tool.php"
commit_fixture tooling
expect_not_applicable
reset_fixture
sed -i '/function synthetic_removed/d' "$fixture/checkout/app/Original.php"
commit_fixture method-deletion
expect_not_applicable
reset_fixture
git_fixture rm -q app/Original.php
commit_fixture file-deletion
expect_not_applicable
reset_fixture
printf '// Application addition.\n' >>"$fixture/checkout/app/Original.php"
commit_fixture addition
expect_application_scope
reset_fixture
sed -i 's/return 1/return 3/' "$fixture/checkout/app/Original.php"
commit_fixture replacement
expect_application_scope
reset_fixture
sed -i '/function synthetic_removed/d' "$fixture/checkout/app/Original.php"
printf '<?php\nfunction synthetic_new(): int { return 3; }\n' >"$fixture/checkout/app/New.php"
commit_fixture mixed-addition-deletion
expect_application_scope
reset_fixture
git_fixture mv app/Original.php app/Renamed.php
commit_fixture rename
expect_application_scope
reset_fixture
git_fixture mv scripts/tool.php app/Imported.php
commit_fixture imported-source
expect_application_scope
reset_fixture
for special in 'space name.php' $'tab\tname.php' $'line\nname.php'; do
    cp "$fixture/checkout/app/Original.php" "$fixture/checkout/app/$special"
    commit_fixture special-filename
    expect_application_scope
    reset_fixture
done
printf '\0synthetic-binary-source\n' >>"$fixture/checkout/app/Original.php"
commit_fixture binary-source
expect_application_scope

# Applicable changes always run the measurement gate, including after an
# Infection failure; a gate failure wins over Infection's successful exit.
status=0
FIXTURE_INFECTION_STATUS=0 FIXTURE_GATE_STATUS=76 run_scope || status=$?
test "$status" -eq 76 || fail 'measurement failure was hidden'
printf 'baseline\ninfection\ngate\n' | cmp -s - "$fixture/php.log" || fail 'measurement gate did not execute'
# A failing baseline stops before any mutant: with --skip-initial-tests an
# ordinary failing test would otherwise count as a killed mutant.
status=0
FIXTURE_BASELINE_STATUS=77 run_scope || status=$?
test "$status" -eq 77 || fail 'a failing baseline was hidden'
printf 'baseline\n' | cmp -s - "$fixture/php.log" || fail 'mutants ran after a failing baseline'
grep -Fq 'The Unit baseline failed' "$fixture/output" || fail 'a failing baseline was not explained'
reset_fixture
for failure in invalid-base invalid-head merge-base diff malformed; do
    status=0
    case "$failure" in
        invalid-base) FIXTURE_BASE=nonexistent-synthetic-ref run_scope || status=$? ;;
        invalid-head)
            (cd "$fixture/checkout" && source scripts/mutation-scope.sh && mutation_scope "$base" nonexistent-synthetic-ref) >"$fixture/output" 2>&1 || status=$?
            ;;
        *) FIXTURE_GIT_FAILURE="$failure" run_scope || status=$? ;;
    esac
    test "$status" -ne 0 || fail "$failure passed local detection"
    test ! -e "$fixture/php.log" || fail "$failure invoked PHP"
    if [ "$failure" = invalid-head ]; then continue; fi
    status=0
    if [ "$failure" = invalid-base ]; then
        FIXTURE_BASE=nonexistent-synthetic-ref run_hosted || status=$?
    else
        FIXTURE_GIT_FAILURE="$failure" run_hosted || status=$?
    fi
    test "$status" -ne 0 || fail "$failure passed hosted detection"
    test ! -e "$fixture/github-output" || fail "$failure published applicability outputs"
done
echo 'Shared local and hosted mutation scope tests passed.'
