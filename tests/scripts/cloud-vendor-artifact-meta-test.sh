#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/cloud/vendor-bundle-artifact-meta.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/cloud-vendor-artifact-meta.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

NAME=cloud-vendor-provenance-abcdef-123-2
DIGEST="sha256:$(printf proof | sha256sum | cut -d' ' -f1)"
cat >"$WORK/pages.json" <<EOF
[
  {
    "total_count": 2,
    "artifacts": [
      {"id": 17, "name": "$NAME", "expired": false, "digest": "$DIGEST", "workflow_run": {"id": 123}},
      {"id": 18, "name": "unrelated", "expired": false, "digest": "$DIGEST", "workflow_run": {"id": 123}}
    ]
  },
  {"total_count": 1, "artifacts": [
    {"id": 19, "name": "$NAME", "expired": false, "digest": "$DIGEST", "workflow_run": {"id": 999}}
  ]}
]
EOF

chmod +x "$SCRIPT"
[[ "$("$SCRIPT" "$NAME" 123 <"$WORK/pages.json")" == $'17\t'"$DIGEST" ]]

if "$SCRIPT" "$NAME" 9999 <"$WORK/pages.json" >"$WORK/missing.out" 2>&1; then
  printf 'FAIL: parser accepted an artifact from another workflow run\n' >&2
  exit 1
fi

printf 'ok   paginated Actions response selects one proof from the exact run\n'
printf 'ok   top-level total_count values are never treated as artifacts\n'
