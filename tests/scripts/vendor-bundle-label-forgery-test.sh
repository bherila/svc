#!/usr/bin/env bash
# Drive the complete installer against a stub GitHub API. The v1 label is a
# faithful forged-label attack against the old implementation: it names a real
# trusted-looking run but has no run-bound digest evidence. Legacy v2 labels are
# refused after the refresh protocol split run and source commits. The v3 and
# compact v4 cases prove an expired/deleted immutable proof also fails closed
# before download.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${VENDOR_BUNDLE_SCRIPT_UNDER_TEST:-$ROOT/scripts/cloud/vendor-bundle.sh}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/vendor-bundle-label-forgery-test.XXXXXX")" || exit 1
trap 'rm -rf "$WORK"' EXIT

CHECKOUT="$WORK/checkout"
STUBS="$WORK/bin"
mkdir -p "$CHECKOUT" "$STUBS" "$WORK/payload/vendor/composer"
printf '{"packages":[],"packages-dev":[]}\n' >"$CHECKOUT/composer.lock"
printf '<?php\n' >"$WORK/payload/vendor/autoload.php"
printf '{"packages":[],"dev":true}\n' >"$WORK/payload/vendor/composer/installed.json"
tar -cf "$WORK/bundle.tar" -C "$WORK/payload" vendor

LOCK="$(sha256sum "$CHECKOUT/composer.lock" | cut -d' ' -f1)"
BUNDLE_SHA="$(sha256sum "$WORK/bundle.tar" | cut -d' ' -f1)"
SHA="0123456789abcdef0123456789abcdef01234567"
SOURCE_SHA="89abcdef0123456789abcdef0123456789abcdef"
RUN="123456789"
EVIDENCE="987654321"
ATTEMPT="1"
ARTIFACT="cloud-vendor-provenance-$LOCK-$RUN-$ATTEMPT"
MANIFEST="cloud-vendor-provenance-$LOCK.txt"

cat >"$WORK/$MANIFEST" <<EOF
cloud-vendor-attestation/v2
repo=bherila/svc
workflow=.github/workflows/cloud-vendor.yml
ref=refs/heads/main
run_sha=$SHA
source_sha=$SOURCE_SHA
run=$RUN
attempt=$ATTEMPT
lock=$LOCK
bundle=vendor-$LOCK.tar.zst
artifact=$ARTIFACT
sha256=$BUNDLE_SHA
EOF
php -r '
  $zip = new ZipArchive();
  if ($zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { exit(1); }
  $zip->addFile($argv[2], basename($argv[2]));
  $zip->close();
' "$WORK/provenance.zip" "$WORK/$MANIFEST"
PROOF_DIGEST="$(sha256sum "$WORK/provenance.zip" | cut -d' ' -f1)"

cat >"$STUBS/zstd" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == -dc ]]; then
  command cat "$2"
  exit
fi
exit 2
EOF
chmod +x "$STUBS/zstd"

cat >"$STUBS/composer" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$STUBS/composer"

cat >"$STUBS/curl" <<'EOF'
#!/usr/bin/env bash
set -u
output=""
write_status=0
url=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o)
      output="$2"
      shift 2
      ;;
    -w)
      write_status=1
      shift 2
      ;;
    -H)
      shift 2
      ;;
    -*)
      shift
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done
printf '%s\n' "$url" >>"$CURL_LOG"
case "$url" in
  *'/releases?per_page=100&page=1')
    printf '[{"id":1,"draft":true,"tag_name":"cloud-vendor"}]'
    ;;
  *'/releases/1/assets?per_page=100')
    printf '[{"id":77,"name":"vendor-%s.tar.zst","digest":"sha256:%s","label":"%s"}]' \
      "$LOCK" "$BUNDLE_SHA" "$ASSET_LABEL"
    ;;
  *'/actions/runs/'*)
    printf '{"id":%s,"repository":{"full_name":"bherila/svc"},"path":".github/workflows/cloud-vendor.yml","head_branch":"main","head_sha":"%s","run_attempt":1,"status":"completed","conclusion":"success"}' \
      "$RUN" "$SHA"
    if [[ "$write_status" == 1 ]]; then printf '\n200'; fi
    ;;
  *"/actions/artifacts/$EVIDENCE/zip")
    if [[ -z "${PROOF_FILE:-}" ]]; then exit 22; fi
    command cp "$PROOF_FILE" "$output"
    ;;
  *"/actions/artifacts/$EVIDENCE")
    if [[ -z "${PROOF_FILE:-}" ]]; then
      printf '{"message":"Not Found"}'
      exit 22
    fi
    printf '{"id":%s,"name":"%s","expired":false,"digest":"sha256:%s","workflow_run":{"id":%s}}' \
      "$EVIDENCE" "$ARTIFACT" "$PROOF_DIGEST" "$RUN"
    ;;
  *'/releases/assets/77')
    command cp "$BUNDLE_FILE" "$output"
    ;;
  *)
    printf 'unexpected URL: %s\n' "$url" >&2
    exit 2
    ;;
esac
EOF
chmod +x "$STUBS/curl"

run_installer() {
  local label="$1"
  local output="$2"
  local proof_file="${3:-}"
  : >"$WORK/curl.log"
  ASSET_LABEL="$label" \
  BUNDLE_FILE="$WORK/bundle.tar" \
  BUNDLE_SHA="$BUNDLE_SHA" \
  CURL_LOG="$WORK/curl.log" \
  EVIDENCE="$EVIDENCE" \
  ARTIFACT="$ARTIFACT" \
  PROOF_DIGEST="$PROOF_DIGEST" \
  PROOF_FILE="$proof_file" \
  LOCK="$LOCK" \
  RUN="$RUN" \
  SHA="$SHA" \
  PATH="$STUBS:$PATH" \
  CLAUDE_CODE_REMOTE=true \
  GH_TOKEN=test-token \
    bash "$SCRIPT" install --force "$CHECKOUT" >"$output" 2>&1
}

assert_refused_before_bundle_download() {
  local label="$1"
  local output="$2"
  if run_installer "$label" "$output"; then
    printf 'FAIL: installer accepted provenance with no immutable bundle proof\n' >&2
    return 1
  fi
  if grep -q '/releases/assets/77' "$WORK/curl.log"; then
    printf 'FAIL: installer downloaded executable bundle bytes before refusing\n' >&2
    return 1
  fi
}

V1_LABEL="cloud-vendor/v1 repo=bherila/svc ref=refs/heads/main sha=$SHA run=$RUN attempt=1 lock=$LOCK"
assert_refused_before_bundle_download "$V1_LABEL" "$WORK/v1.out"
grep -q 'unrecognised provenance format' "$WORK/v1.out"
printf 'ok   a forged legacy label naming a trusted run is refused before download\n'

V2_LABEL="cloud-vendor/v2 repo=bherila/svc ref=refs/heads/main sha=$SHA run=$RUN attempt=1 lock=$LOCK evidence=$EVIDENCE"
assert_refused_before_bundle_download "$V2_LABEL" "$WORK/v2.out"
grep -q 'unrecognised provenance format' "$WORK/v2.out"
printf 'ok   a legacy v2 label is refused before download\n'

V3_LABEL="cloud-vendor/v3 repo=bherila/svc ref=refs/heads/main run_sha=$SHA source_sha=$SHA run=$RUN attempt=1 lock=$LOCK evidence=$EVIDENCE"
assert_refused_before_bundle_download "$V3_LABEL" "$WORK/v3.out"
grep -q 'may have expired or been deleted' "$WORK/v3.out"
grep -q 'publish a fresh bundle/proof pair' "$WORK/v3.out"
printf 'ok   a v3 label with a missing or expired immutable proof fails closed with recovery\n'

V4_LABEL="cloud-vendor/v4 h=$SHA s=$SOURCE_SHA r=$RUN a=$ATTEMPT l=$LOCK e=$EVIDENCE"
assert_refused_before_bundle_download "$V4_LABEL" "$WORK/v4.out"
grep -q 'may have expired or been deleted' "$WORK/v4.out"
grep -q 'publish a fresh bundle/proof pair' "$WORK/v4.out"
printf 'ok   a compact v4 label with a missing or expired immutable proof fails closed with recovery\n'

if ! run_installer "$V4_LABEL" "$WORK/v4-success.out" "$WORK/provenance.zip"; then
  cat "$WORK/v4-success.out" >&2
  printf 'FAIL: compact v4 label did not complete immutable proof verification and install\n' >&2
  exit 1
fi
grep -q "source ${SOURCE_SHA:0:12}" "$WORK/v4-success.out"
grep -q 'immutable provenance verified' "$WORK/v4-success.out"
grep -q 'installed vendor/' "$WORK/v4-success.out"
grep -q '/releases/assets/77' "$WORK/curl.log"
printf 'ok   a compact v4 label preserves distinct workflow/source SHAs through full proof verification\n'
