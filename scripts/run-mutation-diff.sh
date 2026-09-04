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

exec php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/infection \
    --configuration=infection.diff.json5 \
    --git-diff-lines \
    --git-diff-base="$mutation_base" \
    --only-covering-test-cases \
    --min-covered-msi=82 \
    --threads=max \
    --initial-tests-php-options='-d pcov.enabled=1 -d memory_limit=1G'
