#!/usr/bin/env bash

# Shared applicability for the local runner and hosted job. Source this file,
# then call mutation_scope BASE HEAD. Git errors must reach the caller before
# any not-applicable result can be published.
# The mutation_scope_* variables are outputs consumed by the sourcing caller.
# shellcheck disable=SC2034
mutation_scope() {
    local base_ref="$1" head_ref="$2" statistics added removed path
    mutation_scope_base=$(git merge-base "$base_ref" "$head_ref") || return
    statistics=$(git -c diff.renames=false diff --no-ext-diff --no-textconv --numstat \
        --diff-filter=AM "$mutation_scope_base" "$head_ref" -- ':(glob)app/**/*.php') || return

    mutation_scope_required=false
    mutation_scope_files=0
    while IFS=$'\t' read -r added removed path; do
        [ -n "$added" ] || continue
        if [[ "$added" == '-' && "$removed" == '-' && -n "$path" ]]; then
            # Binary source cannot prove absence of surviving changed lines.
            mutation_scope_required=true
        elif [[ "$added" =~ ^[0-9]+$ && "$removed" =~ ^[0-9]+$ && -n "$path" ]]; then
            if [ "$added" -gt 0 ]; then
                mutation_scope_required=true
            fi
        else
            echo 'Cannot classify application mutation scope: invalid Git numstat output.' >&2
            return 1
        fi
        mutation_scope_files=$((mutation_scope_files + 1))
    done <<< "$statistics"
}
