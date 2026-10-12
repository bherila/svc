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
# A leftover summary from an earlier run must not be mistaken for this one.
rm -f "$summary"

# Infection's exit code judges the score; the gate judges whether anything was
# measured (#443). Run both and fail on either, so a run in which every mutant
# timed out cannot pass. CI runs the same gate script.
infection_status=0
php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/infection \
    --configuration=infection.diff.json5 \
    --git-diff-lines \
    --git-diff-base="$mutation_base" \
    --only-covering-test-cases \
    --min-covered-msi=82 \
    --threads=max \
    --initial-tests-php-options='-d pcov.enabled=1 -d memory_limit=1G' || infection_status=$?

gate_status=0
php scripts/mutation-gate.php "$summary" || gate_status=$?

if [ "$gate_status" -ne 0 ]; then
    exit "$gate_status"
fi
exit "$infection_status"
