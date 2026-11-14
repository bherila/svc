#!/usr/bin/env bash
# Exercise the immutable workflow-artifact proof consumed by vendor-bundle.sh.
# The release asset, its digest and its label are all mutable together; this
# separate artifact is useful because GitHub binds it to one workflow run and
# does not permit its contents to be changed after upload.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT/scripts/cloud/vendor-bundle.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/vendor-bundle-provenance-test.XXXXXX")" || exit 1
trap 'rm -rf "$WORK"' EXIT

FAILURES=0
fail() {
  printf 'FAIL: %s\n' "$*" >&2
  FAILURES=$((FAILURES + 1))
}
check() {
  local what="$1"
  shift
  if "$@"; then
    printf 'ok   %s\n' "$what"
  else
    fail "$what"
  fi
}

# shellcheck source=/dev/null
. "$SCRIPT"

REPO="bherila/svc"
REF="refs/heads/main"
WORKFLOW=".github/workflows/cloud-vendor.yml"
RUN="123456789"
ATTEMPT="2"
SHA="0123456789abcdef0123456789abcdef01234567"
SOURCE_SHA="89abcdef0123456789abcdef0123456789abcdef"
LOCK="abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd"
BUNDLE="vendor-$LOCK.tar.zst"
MANIFEST="cloud-vendor-provenance-$LOCK.txt"
ARTIFACT="cloud-vendor-provenance-$LOCK-$RUN-$ATTEMPT"

printf 'trusted bundle bytes\n' >"$WORK/bundle"
BUNDLE_SHA="$(file_sha256 "$WORK/bundle")"

write_manifest() {
  local repo="${1:-$REPO}"
  local digest="${2:-$BUNDLE_SHA}"
  local source_sha="${3:-$SOURCE_SHA}"
  cat >"$WORK/$MANIFEST" <<EOF
cloud-vendor-attestation/v2
repo=$repo
workflow=$WORKFLOW
ref=$REF
run_sha=$SHA
source_sha=$source_sha
run=$RUN
attempt=$ATTEMPT
lock=$LOCK
bundle=$BUNDLE
artifact=$ARTIFACT
sha256=$digest
EOF
}

make_zip() {
  local extra="${1:-}"
  php -r '
    $zip = new ZipArchive();
    if ($zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { exit(1); }
    $zip->addFile($argv[2], basename($argv[2]));
    if ($argv[3] !== "") { $zip->addFile($argv[3], basename($argv[3])); }
    $zip->close();
  ' "$WORK/provenance.zip" "$WORK/$MANIFEST" "$extra"
}

verify_zip() {
  verify_provenance_artifact \
    "$WORK/provenance.zip" "sha256:$(file_sha256 "$WORK/provenance.zip")" \
    "$MANIFEST" "$REPO" "$WORKFLOW" "$REF" "$SHA" "$SOURCE_SHA" "$RUN" "$ATTEMPT" \
    "$LOCK" "$BUNDLE" "$ARTIFACT"
}

printf '== a trusted-run artifact binds the release bundle digest\n'
write_manifest
make_zip
verdict="$(verify_zip)"
check "the exact manifest is accepted" test "$verdict" = "ok $BUNDLE_SHA"
check "the bundle matches the immutable manifest digest" \
  verify_provenance_bundle "$WORK/bundle" "$BUNDLE_SHA"

printf '\n== a refresh proof separates the workflow run from its historical source\n'
write_manifest
make_zip
verdict="$(verify_zip)"
check "the v2 manifest binds both run and source commits" test "$verdict" = "ok $BUNDLE_SHA"
write_manifest "$REPO" "$BUNDLE_SHA" "$SHA"
make_zip
if verify_zip >"$WORK/wrong-source.out" 2>&1; then
  fail "a proof for another source commit is refused"
else
  check "a proof for another source commit is refused" grep -q 'source_sha=' "$WORK/wrong-source.out"
fi

printf '\n== changing the release bytes cannot reuse the trusted proof\n'
printf 'attacker-controlled replacement\n' >"$WORK/bundle"
if verify_provenance_bundle "$WORK/bundle" "$BUNDLE_SHA" >"$WORK/tampered.out" 2>&1; then
  fail "tampered release bytes are refused"
else
  check "tampered release bytes are refused" grep -q 'does not match the immutable provenance' "$WORK/tampered.out"
fi

printf '\n== changing the artifact bytes is detected before its manifest is trusted\n'
write_manifest
make_zip
ZIP_DIGEST="sha256:$(file_sha256 "$WORK/provenance.zip")"
printf 'x' >>"$WORK/provenance.zip"
if verify_provenance_artifact \
  "$WORK/provenance.zip" "$ZIP_DIGEST" "$MANIFEST" "$REPO" "$WORKFLOW" \
  "$REF" "$SHA" "$SHA" "$RUN" "$ATTEMPT" "$LOCK" "$BUNDLE" "$ARTIFACT" \
  >"$WORK/changed-artifact.out" 2>&1; then
  fail "changed artifact bytes are refused"
else
  check "changed artifact bytes are refused" grep -q 'artifact digest' "$WORK/changed-artifact.out"
fi

printf '\n== a manifest copied from another repository is refused\n'
write_manifest "attacker/fork"
make_zip
if verify_zip >"$WORK/wrong-repo.out" 2>&1; then
  fail "wrong-repository manifest is refused"
else
  check "wrong-repository manifest is refused" grep -q 'repo=attacker/fork' "$WORK/wrong-repo.out"
fi

printf '\n== an archive with any unaccounted-for file is refused\n'
write_manifest
printf 'surprise\n' >"$WORK/extra"
make_zip "$WORK/extra"
if verify_zip >"$WORK/extra-file.out" 2>&1; then
  fail "artifact with an extra file is refused"
else
  check "artifact with an extra file is refused" grep -q 'exactly one file' "$WORK/extra-file.out"
fi

printf '\n'
if [[ "$FAILURES" -gt 0 ]]; then
  printf '%d check(s) failed\n' "$FAILURES" >&2
  exit 1
fi
printf 'all immutable vendor provenance checks passed\n'
