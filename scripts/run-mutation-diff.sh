#!/usr/bin/env bash

set -euo pipefail

# Infection refines this reference to its merge base with HEAD. The workflow
# supplies the merge base it used for detection; local runs default to the
# current origin/main merge base through the same checked-in Infection API.
mutation_base="${MUTATION_BASE:-origin/main}"

# `--git-diff-lines` intentionally uses Git's AM filter. Disable rename
# detection so a renamed-and-edited PHP file appears as delete + add: the new
# path and all of its lines then remain inside Infection's changed-line scope.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=diff.renames
export GIT_CONFIG_VALUE_0=false

# Infection reads PHPUnit's ordinary output. Inside a coding agent's shell
# (CLAUDECODE, AI_AGENT, CODEX_* and the rest `laravel/agent-detector` knows),
# `laravel/pao` replaces that output with a one-line JSON summary Infection
# cannot parse, and every mutant is then counted as killed - a gate that
# passes locally at 100% while CI fails it at 40%. Disable it for this run
# only; every agent the detector knows is covered by the one switch.
export PAO_DISABLE=1

summary=storage/framework/cache/infection-diff/summary.json
# Evidence that must survive Infection: it appends `infection/` to its tmpDir
# and deletes that directory when a run finishes (unless --debug), so the
# baseline coverage, JUnit timings and per-mutant log live beside it instead.
diagnostics=storage/framework/cache/infection-diff/diagnostics
# A leftover summary or diagnostics from an earlier run must not be mistaken
# for this one.
rm -f "$summary"
rm -rf "$diagnostics"

# Both entry points use surviving added/changed lines, not file status. A pure
# deletion has nothing for Infection's changed-line filter to mutate.
# shellcheck source=scripts/mutation-scope.sh
source "$(dirname "${BASH_SOURCE[0]}")/mutation-scope.sh"
mutation_scope "$mutation_base" HEAD
if [ "$mutation_scope_required" = false ]; then
    echo 'Mutation gate not applicable: no added or changed surviving PHP application lines under app/ (including deletion-only diffs).'
    exit 0
fi

mkdir -p "$diagnostics"
{
    echo "base=$(git rev-parse "$mutation_base")"
    echo "head=$(git rev-parse HEAD)"
    echo "changed_files=$mutation_scope_files"
    echo "cpus=$(nproc 2>/dev/null || echo unknown)"
} >"$diagnostics/run.txt"

# The baseline: the Unit suite once, with PCOV, writing the coverage map and
# per-test timings Infection selects covering tests from. It must pass before
# any mutant runs: with --skip-initial-tests, an ordinary failing test would
# otherwise read as a killed mutant. Same suite and order as Infection's own
# initial run (infection.diff.json5's testFrameworkExtraArgs).
baseline_started=$SECONDS
baseline_status=0
php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/phpunit \
    --testsuite=Unit \
    --order-by=default \
    --coverage-xml="$diagnostics/baseline/coverage-xml" \
    --log-junit="$diagnostics/baseline/junit.xml" || baseline_status=$?
echo "baseline_seconds=$((SECONDS - baseline_started))" >>"$diagnostics/run.txt"
if [ "$baseline_status" -ne 0 ]; then
    echo "The Unit baseline failed (exit $baseline_status); no mutant was run." >&2
    exit "$baseline_status"
fi

# Infection's exit code judges the score; the gate judges whether anything was
# measured (#443). Run both and fail on either, so a run in which every mutant
# timed out cannot pass. CI runs the same gate script.
# --teamcity streams one record per finished mutant (mutator, id, location,
# outcome, diff, runtime) into the diagnostics, so a run cut off by the job
# deadline still says which mutants it reached and what each cost. The console
# keeps everything else.
mutants_started=$SECONDS
infection_status=0
php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/infection \
    --configuration=infection.diff.json5 \
    --git-diff-lines \
    --git-diff-base="$mutation_base" \
    --only-covering-test-cases \
    --min-covered-msi=82 \
    --threads=max \
    --coverage="$diagnostics/baseline" \
    --skip-initial-tests \
    --teamcity | tee "$diagnostics/mutants.teamcity.log" | grep --line-buffered -v '^##teamcity' || infection_status=${PIPESTATUS[0]}
echo "mutants_seconds=$((SECONDS - mutants_started))" >>"$diagnostics/run.txt"

gate_status=0
php scripts/mutation-gate.php "$summary" || gate_status=$?

# The baseline is the Unit suite alone, so a changed line only a Feature test
# reaches yields no mutant (#661). Name those methods as not measured rather
# than let "0 mutants" read as a result. Advisory: it fails only when the
# coverage it reads is missing.
coverage_status=0
php scripts/mutation-coverage.php "$mutation_scope_base" "$diagnostics/baseline/coverage-xml" || coverage_status=$?

if [ "$gate_status" -ne 0 ]; then
    exit "$gate_status"
fi
if [ "$coverage_status" -ne 0 ]; then
    exit "$coverage_status"
fi
exit "$infection_status"
