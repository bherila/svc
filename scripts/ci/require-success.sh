#!/usr/bin/env bash
# An aggregate check must fail if any dependency failed, was skipped or cancelled.
set -euo pipefail
jq -e 'type == "object" and length > 0 and all(.[]; .result == "success")' <<< "${NEEDS_JSON:?Missing dependency results}"
