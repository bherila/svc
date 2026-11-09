#!/usr/bin/env bash

# Whether a change can affect a rendered screen, for the layout shards of the
# hosted tests workflow. Source this file, then call layout_scope BASE HEAD.
#
# Conservative: the layout run is skipped only when every changed file is one
# no screen can read - documentation, PHP tests and their fixtures, review and
# agent configuration, the workflows other than this one, and the mutation and
# CI helper scripts. Anything else - application code, which seeds and renders
# the screens (scripts/layout/seed.php boots the app), the frontend, routes,
# config, migrations, dependencies, the harness itself and this workflow - runs
# it. Git errors must reach the caller before a not-required result is
# published.
# The layout_scope_* variables are outputs consumed by the sourcing caller.
# shellcheck disable=SC2034
layout_scope() {
    local base_ref="$1" head_ref="$2" changed path
    layout_scope_base=$(git merge-base "$base_ref" "$head_ref") || return
    changed=$(git -c diff.renames=false diff --no-ext-diff --name-only "$layout_scope_base" "$head_ref") || return

    layout_scope_required=false
    layout_scope_reason=''
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        if ! layout_scope_ignores "$path"; then
            layout_scope_required=true
            layout_scope_reason="$path"
            return 0
        fi
    done <<< "$changed"
}

# The paths no rendered screen depends on.
layout_scope_ignores() {
    case "$1" in
        .github/workflows/tests.yml) return 1 ;;
        resources/*) return 1 ;;
        # The in-app help panel bundles these articles
        # (resources/js/help/articles.ts imports them with import.meta.glob).
        docs/help/*) return 1 ;;
        docs/* | *.md) return 0 ;;
        tests/Unit/* | tests/Feature/* | tests/Concerns/* | tests/Support/* | tests/Fixtures/* | tests/Deployment/*) return 0 ;;
        .claude/* | .github/*) return 0 ;;
        infection*.json5 | phpstan*.neon | phpunit*.xml | pint.json | .editorconfig) return 0 ;;
        scripts/ci/* | scripts/mutation-* | scripts/run-mutation-*) return 0 ;;
        *) return 1 ;;
    esac
}
