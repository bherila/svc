#!/usr/bin/env bash
# Install vendor/ from this repo's prebuilt cloud-vendor bundle.
#
# WHY THIS LIVES IN THE REPO
# --------------------------
# A Claude Code cloud session cannot install Composer packages from the
# network: the session's GitHub proxy answers 403 for github.com archives of
# every repository not attached to the session, so `composer install` fails
# package by package ("Could not authenticate against github.com") and leaves
# vendor/ at whatever it already was. Static analysis then reports errors from
# the wrong package versions, which is worse than no analysis at all.
#
# .github/workflows/cloud-vendor.yml builds vendor/ for each composer.lock and
# uploads it to the `cloud-vendor` DRAFT release as
# vendor-<sha256 of composer.lock>.tar.zst. The proxy does serve this repo's
# own release assets, so that bundle is reachable. This script is the consumer
# side of that contract.
#
# Only workflow runs on the default branch publish a bundle: the asset name is a
# public hash, and a session installs the matching asset and runs Composer over
# it without a human looking, so an asset any ref could overwrite would be code
# execution in an unrelated session. A lock that has not reached main has no
# bundle -- that is deliberate, and `verify` will say so rather than let stale
# analysis pass for fresh.
#
# The name is not an identity, though, so this script does not treat it as one.
# Every upload carries a compact locator for its workflow/source commits, run
# and evidence artifact in the release label. The label is not trusted:
# `install` checks GitHub's immutable run fields, downloads that run's immutable
# evidence artifact, verifies its full repository/workflow/ref contract and
# digest, and requires the release bytes to match the digest recorded in its
# manifest. It fails closed and says why; it never installs on a label alone.
# See the provenance section further down for the complete contract.
#
# It used to be assumed that the environment-provided `cloud-repo-bootstrap`
# did this. It does not verify provenance: it downloads by asset name, and a
# name is a public hash anyone with contents:write could upload under.
# Keeping the consumer here, next to the producer workflow, means the two can
# only drift within one repo, and a session no longer depends on an
# out-of-repo script gaining a feature.
#
# INTEROP WITH cloud-repo-bootstrap
# ---------------------------------
# On success this writes vendor/.cloud-repo-bootstrap containing the lock's
# sha256. That is exactly the stamp `cloud-repo-bootstrap` checks before
# running its own composer step, so a correct bundle install makes it report
# "composer: vendor/ already matches composer.lock" and skip the network
# install that would otherwise fail. Do not rename that file without also
# revisiting the SessionStart hook in .claude/settings.json, which relies on
# this script running first.
#
# Usage:
#   scripts/cloud/vendor-bundle.sh [install|verify|hash] [--force] [DIR]
#
#   install   Download and unpack the bundle matching composer.lock (default).
#             The run-bound immutable proof is checked before the bundle is
#             downloaded, and its bytes against that proof before anything is
#             unpacked. Network steps run only in a cloud
#             session (CLAUDE_CODE_REMOTE=true) or with --force; --force also
#             re-fetches a vendor/ whose metadata already matches, which is the
#             recovery for a tree whose *files* are damaged.
#   verify    Compare vendor/ against composer.lock, package by package. No
#             network. This is the check that catches a stale analyser before
#             it invents errors that CI does not have.
#   hash      Print the bundle key (sha256 of composer.lock).
#
# Exit codes:
#   0  vendor/ matches composer.lock (installed just now, or already did)
#   3  no bundle is published for this composer.lock, or this is not a cloud
#      session -- recoverable; the caller decides whether that is fatal
#   1  anything else -- verify mismatch, download or unpack failure, bad usage,
#      or a bundle this script will not install because it cannot establish
#      that the gated workflow published it. A refusal is deliberately NOT the
#      recoverable 3: "these bytes are unaccounted for" is a different answer
#      from "nobody has built this lock yet", and must not be papered over.
#
# Environment overrides (all default to the safe behaviour):
#   CLOUD_VENDOR_ALLOW_UNTRUSTED=1     continue past a missing/legacy release label
#   CLOUD_VENDOR_ALLOW_NO_DIGEST=1     with the untrusted override, allow no API digest
#   CLOUD_VENDOR_TRUSTED_REF=...       the ref allowed to publish (refs/heads/main)

set -uo pipefail

# Status lines go to stdout because the SessionStart hook's stdout becomes
# Claude's context. Verbose tool output goes to $LOG.
say() { printf '[vendor-bundle] %s\n' "$*"; }

file_sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    php -r 'echo hash_file("sha256", $argv[1]);' "$1"
  fi
}

# Validate the small, immutable Actions artifact that binds a bundle digest to
# one exact trusted workflow run. The release asset, its API digest and its
# label are mutable by anyone holding contents:write; this archive is separate
# evidence because GitHub Actions artifacts are immutable and run-scoped.
verify_provenance_artifact() {
  local archive="$1"
  local archive_digest="$2"
  local manifest="$3"
  shift 3

  if [[ ! "$archive_digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    printf 'bad the Actions API reported no usable artifact digest\n'
    return 1
  fi

  local got
  got="$(file_sha256 "$archive")"
  if [[ "$archive_digest" != "sha256:$got" ]]; then
    printf 'bad downloaded artifact digest sha256:%s does not match %s\n' "$got" "$archive_digest"
    return 1
  fi

  php -d display_errors=stderr -r '
    $zip = new ZipArchive();
    if ($zip->open($argv[1]) !== true) { echo "bad the provenance artifact is not a readable ZIP"; exit(1); }
    if ($zip->numFiles !== 1) { echo "bad the provenance artifact must contain exactly one file"; exit(1); }
    $stat = $zip->statIndex(0);
    if (!is_array($stat) || ($stat["name"] ?? "") !== $argv[2]) {
        echo "bad the provenance artifact does not contain only " . $argv[2];
        exit(1);
    }
    if (($stat["size"] ?? 0) > 4096) { echo "bad the provenance manifest is unexpectedly large"; exit(1); }
    $body = $zip->getFromIndex(0);
    $zip->close();
    if (!is_string($body) || $body === "" || strlen($body) > 4096) {
        echo "bad the provenance manifest could not be read";
        exit(1);
    }
    $lines = explode("\n", rtrim($body, "\n"));
    if (($lines[0] ?? "") !== "cloud-vendor-attestation/v2") {
        echo "bad unrecognised immutable provenance format";
        exit(1);
    }
    $expected = [
        "repo" => $argv[3],
        "workflow" => $argv[4],
        "ref" => $argv[5],
        "run_sha" => $argv[6],
        "source_sha" => $argv[7],
        "run" => $argv[8],
        "attempt" => $argv[9],
        "lock" => $argv[10],
        "bundle" => $argv[11],
        "artifact" => $argv[12],
    ];
    $fields = [];
    foreach (array_slice($lines, 1) as $line) {
        if ($line === "" || !str_contains($line, "=")) {
            echo "bad malformed provenance manifest field";
            exit(1);
        }
        [$key, $value] = explode("=", $line, 2);
        if (($key !== "sha256" && !array_key_exists($key, $expected)) || array_key_exists($key, $fields)) {
            echo "bad unexpected or duplicate provenance field " . $key;
            exit(1);
        }
        $fields[$key] = $value;
    }
    foreach ($expected as $key => $want) {
        $got = $fields[$key] ?? "";
        if ($got !== $want) {
            echo "bad manifest says " . $key . "=" . ($got === "" ? "(absent)" : $got) . ", expected " . $key . "=" . $want;
            exit(1);
        }
    }
    $digest = $fields["sha256"] ?? "";
    if (!preg_match("/^[0-9a-f]{64}\\z/", $digest)) {
        echo "bad manifest has no usable bundle digest";
        exit(1);
    }
    if (count($fields) !== count($expected) + 1) {
        echo "bad provenance manifest field count";
        exit(1);
    }
    echo "ok " . $digest;
  ' "$archive" "$manifest" "$@"
}

verify_provenance_bundle() {
  local bundle="$1"
  local expected="$2"
  local got
  got="$(file_sha256 "$bundle")"
  if [[ ! "$expected" =~ ^[0-9a-f]{64}$ || "$got" != "$expected" ]]; then
    printf 'bundle sha256:%s does not match the immutable provenance sha256:%s\n' "$got" "$expected"
    return 1
  fi
  return 0
}

# Move the staged tree into place, putting the previous one back if that fails.
#
# $STAGE is created as a SIBLING of vendor/ inside the checkout (see where it
# is set, below), so the move below is a real rename(2): atomic, instant, and
# with no window in which vendor/ is half-populated. The stage used to live
# under TMPDIR, which on a tmpfs /tmp, a container scratch mount or a worktree
# on another volume is a different filesystem -- and `mv` across filesystems is
# a recursive ~600MB copy, not a rename. If that copy died partway (the
# checkout filling up being the obvious way, and the copy itself being what
# filled it) the rollback then ran `mv "$OLD" vendor` against an existing
# directory, which moves the backup INSIDE the partial tree as
# vendor/vendor.replaced.<pid> -- burying the only good copy one level down
# where cleanup() could not see it and .gitignore did not cover it.
#
# Reads $STAGE, $OLD and $LOG. Sets KEEP_BACKUP=1 if it has to give up with the
# previous tree still sitting in $OLD, so cleanup() does not delete the only
# copy left. Returns 0 only when vendor/ is the newly staged tree.
swap_in_vendor() {
  if [[ -e vendor ]]; then
    if ! mv vendor "$OLD" 2>>"$LOG"; then
      say "FAILED: could not move the existing vendor/ aside (see $LOG)"
      return 1
    fi
  fi

  if mv "$STAGE/unpack/vendor" vendor 2>>"$LOG"; then
    rm -rf "$OLD"
    return 0
  fi

  say "FAILED: could not move the new vendor/ into place (see $LOG)"
  [[ -e "$OLD" ]] || return 1

  # Clear the destination before restoring: `mv "$OLD" vendor` against an
  # existing directory moves the backup into it rather than replacing it.
  # Then prove the restore worked instead of assuming it did.
  rm -rf vendor 2>>"$LOG"
  if ! mv "$OLD" vendor 2>>"$LOG"; then
    KEEP_BACKUP=1
    say "FAILED: could not restore the previous vendor/ either."
    say "  It is still at $PWD/$OLD -- move it back to $PWD/vendor by hand."
    return 1
  fi
  if [[ -f vendor/autoload.php ]]; then
    say "restored the vendor/ that was here before"
  else
    say "WARNING: restored $PWD/vendor from the backup, but it carries no"
    say "  autoload.php -- the tree that was here was already incomplete."
  fi
  return 1
}

# Defined next to the swap it protects; the trap is installed further down,
# where $STAGE and $OLD are created. Both transient trees live in the checkout,
# so an interrupted run must not leave either behind.
cleanup() {
  [[ -n "${STAGE:-}" ]] && rm -rf "$STAGE"
  [[ -n "${OLD:-}" && "${KEEP_BACKUP:-0}" == 0 && -d "$OLD" ]] && rm -rf "$OLD"
  [[ -n "${PROVENANCE_STAGE:-}" ]] && rm -rf "$PROVENANCE_STAGE"
  return 0
}

# Sourced rather than run: tests/scripts/vendor-bundle-swap-test.sh loads the
# helpers above to exercise the rollback, which a normal install never
# executes. Everything below this point talks to the network and rewrites the
# checkout, so stop here.
if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
  return 0
fi

MODE="install"
FORCE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    install | verify | hash)
      MODE="$1"
      shift
      ;;
    --force)
      FORCE=1
      shift
      ;;
    --)
      shift
      break
      ;;
    -*)
      printf 'vendor-bundle: unknown option: %s\n' "$1" >&2
      exit 1
      ;;
    *) break ;;
  esac
done

DIR="${1:-${CLAUDE_PROJECT_DIR:-$PWD}}"
cd "$DIR" || {
  printf 'vendor-bundle: cannot cd to %s\n' "$DIR" >&2
  exit 1
}

REPO="${CLOUD_VENDOR_REPO:-bherila/svc}"
TAG="${CLOUD_VENDOR_TAG:-cloud-vendor}"
# The only ref whose runs may publish a bundle, and the workflow allowed to do
# it. Both are asserted against GitHub's own record of the run named in an
# asset's provenance label; keep them in step with .github/workflows/cloud-vendor.yml.
TRUSTED_REF="${CLOUD_VENDOR_TRUSTED_REF:-refs/heads/main}"
TRUSTED_BRANCH="${TRUSTED_REF#refs/heads/}"
WORKFLOW_PATH="${CLOUD_VENDOR_WORKFLOW:-.github/workflows/cloud-vendor.yml}"
LOG="${TMPDIR:-/tmp}/vendor-bundle.$(basename "$PWD").log"

if [[ ! -f composer.lock ]]; then
  say "no composer.lock here; nothing to do"
  exit 0
fi

lock_hash() {
  file_sha256 composer.lock
}

# Compare vendor/composer/installed.json against composer.lock, package by
# package. The stamp file alone is not enough: it can be stale or absent after
# a manual fix, and it can never tell you *which* package is wrong.
verify_vendor() {
  php -r '
    $lockRaw = @file_get_contents("composer.lock");
    $instRaw = @file_get_contents("vendor/composer/installed.json");
    if ($lockRaw === false) { fwrite(STDERR, "composer.lock unreadable\n"); exit(1); }
    if ($instRaw === false) { fwrite(STDERR, "vendor/composer/installed.json missing\n"); exit(1); }
    $lock = json_decode($lockRaw, true);
    $inst = json_decode($instRaw, true);
    if (!is_array($lock) || !is_array($inst)) { fwrite(STDERR, "unparseable composer metadata\n"); exit(1); }

    $want = [];
    foreach (["packages", "packages-dev"] as $k) {
      foreach ($lock[$k] ?? [] as $p) { $want[$p["name"]] = $p["version"]; }
    }
    $have = [];
    foreach ($inst["packages"] ?? $inst as $p) {
      if (isset($p["name"], $p["version"])) { $have[$p["name"]] = $p["version"]; }
    }

    $bad = [];
    foreach ($want as $name => $version) {
      if (!isset($have[$name])) { $bad[] = "$name: absent from vendor/ (lock wants $version)"; }
      elseif ($have[$name] !== $version) { $bad[] = "$name: vendor/ has {$have[$name]}, lock wants $version"; }
    }
    // Both directions, not just the lock side. Dropping a dependency from
    // composer.lock leaves its entry in installed.json and its files in
    // vendor/, so the autoloader keeps serving a package the project no longer
    // declares. Walking only $want never looks at that entry, and every other
    // version still agrees -- so the stale tree would pass and get stamped.
    foreach ($have as $name => $version) {
      if (!isset($want[$name])) { $bad[] = "$name: vendor/ has $version, but composer.lock does not list it"; }
    }
    if ($bad) {
      fwrite(STDERR, count($bad) . " package(s) in vendor/ disagree with composer.lock:\n");
      foreach (array_slice($bad, 0, 12) as $line) { fwrite(STDERR, "    $line\n"); }
      if (count($bad) > 12) { fwrite(STDERR, "    ... and " . (count($bad) - 12) . " more\n"); }
      exit(1);
    }
    exit(0);
  '
}

WANT="$(lock_hash)"

case "$MODE" in
  hash)
    printf '%s\n' "$WANT"
    exit 0
    ;;
  verify)
    if [[ ! -f vendor/autoload.php ]]; then
      say "vendor/ is not installed"
      exit 1
    fi
    if verify_vendor; then
      say "vendor/ matches composer.lock"
      exit 0
    fi
    say "vendor/ does NOT match composer.lock (see the list above)"
    exit 1
    ;;
esac

# ---------------------------------------------------------------- install ---

VENDOR_MATCHES=0
if [[ -f vendor/autoload.php ]] && verify_vendor 2>/dev/null; then
  VENDOR_MATCHES=1
fi

# --force is the documented recovery for a vendor/ whose *files* are damaged
# while installed.json still reads correctly, so it has to reach the download
# path. Short-circuiting on the metadata alone made it a no-op.
if [[ "$VENDOR_MATCHES" == "1" && "$FORCE" != "1" ]]; then
  printf '%s\n' "$WANT" >vendor/.cloud-repo-bootstrap 2>/dev/null || true
  say "vendor/ already matches composer.lock; nothing to download"
  exit 0
fi

# Verification just failed, so drop the stamp before going anywhere near the
# network. If it still held this lock's hash and the refresh below failed, the
# SessionStart hook would carry on to cloud-repo-bootstrap, which reads the
# stamp, skips Composer and reports "vendor/ already matches composer.lock" --
# the opposite of what was just measured. A missing stamp is always safe: it
# only ever causes extra work.
if [[ "$VENDOR_MATCHES" != "1" ]]; then
  rm -f vendor/.cloud-repo-bootstrap 2>/dev/null || true
fi

if [[ "$VENDOR_MATCHES" == "1" ]]; then
  say "vendor/ matches composer.lock, but --force was given; re-fetching the bundle"
fi

if [[ "${CLAUDE_CODE_REMOTE:-}" != "true" && "$FORCE" != "1" ]]; then
  say "vendor/ does not match composer.lock, but this is not a cloud session."
  say "  Run 'composer install', or re-run with --force to fetch the bundle."
  exit 3
fi

TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
if [[ -z "$TOKEN" ]]; then
  say "FAILED: no GH_TOKEN/GITHUB_TOKEN in the environment."
  say "  The cloud-vendor release is a draft on purpose, and draft release"
  say "  assets are only readable with credentials, so the bundle cannot be"
  say "  fetched without one. Do NOT fall back to 'composer install': the"
  say "  cloud proxy blocks it, and it will leave vendor/ silently stale."
  exit 1
fi

: >"$LOG"
api() {
  curl -sS --fail-with-body \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "$@"
}

NAME="vendor-$WANT.tar.zst"
say "looking for $NAME in the $TAG draft release of $REPO"

# A draft release has no git tag, so GET /releases/tags/$TAG cannot find it --
# the list has to be scanned for tag_name plus the draft flag, exactly how
# .github/workflows/cloud-vendor.yml finds it again. Paginate to exhaustion:
# this repo publishes a couple of releases a day, so the draft sinks page by
# page, and any fixed window eventually hides it from every consumer while the
# producer (which paginates with `gh api --paginate`) keeps uploading to it.
# Stop on the first empty page. PAGE_LIMIT is not a window -- 100k releases is
# far past anything real -- it only stops an endless loop if the API ever stops
# honouring `page`. Keep this in step with that workflow's lookup.
PAGE_LIMIT=1000
RELEASE_ID=""
page=1
while [[ "$page" -le "$PAGE_LIMIT" ]]; do
  # `curl --fail-with-body` exits nonzero on an HTTP error but still writes the
  # body, so the status has to be read here: otherwise the JSON error object
  # for a 401/403/5xx gets parsed as a page of releases and the run ends in the
  # recoverable "no draft release" branch, telling the operator to re-run a
  # workflow that was never the problem.
  if ! RELEASES="$(api "https://api.github.com/repos/$REPO/releases?per_page=100&page=$page" 2>>"$LOG")"; then
    say "FAILED: GitHub API error listing releases (page $page) of $REPO."
    say "  This is an authentication, rate-limit or server failure, not a"
    say "  missing bundle. See $LOG for the response body."
    exit 1
  fi
  PAGE_RESULT="$(printf '%s' "$RELEASES" | php -d display_errors=stderr -r '
    $r = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($r) || ($r !== [] && !array_is_list($r))) { echo "bad"; return; }
    foreach ($r as $rel) {
      if (!empty($rel["draft"]) && ($rel["tag_name"] ?? "") === $argv[1]) { echo "id:" . $rel["id"]; return; }
    }
    echo count($r) === 0 ? "end" : "more";
  ' "$TAG")"
  case "$PAGE_RESULT" in
    id:*)
      RELEASE_ID="${PAGE_RESULT#id:}"
      break
      ;;
    end) break ;;
    more) ;;
    *)
      say "FAILED: could not parse the release listing (page $page); see $LOG"
      exit 1
      ;;
  esac
  page=$((page + 1))
done

if [[ "$page" -gt "$PAGE_LIMIT" ]]; then
  say "FAILED: gave up after $PAGE_LIMIT pages of releases; the API is not"
  say "  paginating as expected. See $LOG."
  exit 1
fi

if [[ -z "$RELEASE_ID" ]]; then
  say "no draft release tagged $TAG in $REPO."
  say "  Run the 'Cloud vendor bundle' workflow to create it."
  exit 3
fi

# Same --fail-with-body trap as the release lookup: check the status before
# parsing, or a 401 body becomes "no bundle published for this composer.lock".
# One page is enough -- the workflow prunes to the 20 newest assets.
if ! ASSETS="$(api "https://api.github.com/repos/$REPO/releases/$RELEASE_ID/assets?per_page=100" 2>>"$LOG")"; then
  say "FAILED: GitHub API error listing assets of release $RELEASE_ID."
  say "  This is an authentication, rate-limit or server failure, not a"
  say "  missing bundle. See $LOG for the response body."
  exit 1
fi

# The assets endpoint reports GitHub's own sha256 of the stored bytes as
# "digest": "sha256:<hex>", and the provenance the producer recorded as
# "label". Carry both out of the lookup: the label decides whether this bundle
# may be installed at all, and the digest checks the bytes that arrive.
# Tab-separated because a label contains spaces; control characters in it are
# folded to spaces so a crafted label cannot forge extra fields.
ASSET_META="$(printf '%s' "$ASSETS" | php -d display_errors=stderr -r '
  $a = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($a) || ($a !== [] && !array_is_list($a))) { echo "bad"; return; }
  foreach ($a as $asset) {
    if (($asset["name"] ?? "") === $argv[1]) {
      $label = preg_replace("/[[:cntrl:]]+/", " ", (string) ($asset["label"] ?? ""));
      echo "ok\t" . ($asset["id"] ?? "") . "\t" . ($asset["digest"] ?? "") . "\t" . trim($label);
      return;
    }
  }
  echo "ok\t\t\t";
' "$NAME")"
if [[ "$ASSET_META" == bad* ]]; then
  say "FAILED: could not parse the asset listing of release $RELEASE_ID; see $LOG"
  exit 1
fi
IFS=$'\t' read -r _ ASSET_ID ASSET_DIGEST ASSET_LABEL <<<"$ASSET_META"
ASSET_ID="${ASSET_ID:-}"
ASSET_DIGEST="${ASSET_DIGEST:-}"
ASSET_LABEL="${ASSET_LABEL:-}"

if [[ -z "$ASSET_ID" ]]; then
  say "no bundle published for this composer.lock ($NAME)."
  say "  The 'Cloud vendor bundle' workflow builds one on every push that"
  say "  touches composer.lock, and keeps only the 20 most recent. If this"
  say "  lock is committed and pushed, wait for that workflow to finish and"
  say "  re-run; otherwise push composer.lock first. Do NOT run"
  say "  'composer install' -- the cloud proxy blocks it."
  exit 3
fi

# ------------------------------------------------------------- provenance ---
# The asset name is a public sha256 of composer.lock, so it identifies the
# CONTENT WANTED, never the bytes offered or who produced them. Treating the
# name as sufficient is unsafe: until the main-only gate landed,
# .github/workflows/cloud-vendor.yml granted `contents: write` on every ref, so
# any branch could build and upload the bundle for main's lock, and main's own
# run would then SKIP its build because a file of the right name was already
# there. Every cloud session after that would install the branch-produced bytes
# and run Composer over them. Restricting future publication does nothing
# about an asset already uploaded that way.
#
# The release label is only a locator. It names an immutable Actions artifact
# uploaded inside the claimed run; that artifact contains the bundle digest the
# run computed. The installer checks GitHub's immutable run fields, verifies the
# proof archive against GitHub's artifact digest, validates every manifest
# field, and finally compares the downloaded release bytes to the manifest.
# Someone with contents:write can still replace the release asset and its label,
# but cannot change or recreate an artifact inside a prior trusted run. Deleting
# the proof causes a fail-closed denial of service, not code execution.
#
# Actions artifacts expire, so the workflow rebuilds every retained lock
# monthly from the matching composer.lock in the current main head's ancestry
# and retains each proof for 90 days. A missing/expired proof is never
# downgraded to a label-only check: rerun Cloud vendor bundle on main to publish
# fresh bundle/proof pairs for the retained locks.
STAGE=""
OLD=""
KEEP_BACKUP=0
PROVENANCE_STAGE=""
PROVENANCE_BUNDLE_SHA=""
trap cleanup EXIT

PROV="$(printf '%s' "$ASSET_LABEL" | php -d display_errors=stderr -r '
  $label = trim(stream_get_contents(STDIN));
  if ($label === "") { echo "bad the asset carries no provenance label"; return; }
  $parts = preg_split("/\s+/", $label, -1, PREG_SPLIT_NO_EMPTY);
  $version = $parts[0] ?? "";
  if (!in_array($version, ["cloud-vendor/v3", "cloud-vendor/v4"], true)) {
    echo "bad unrecognised provenance format: " . substr($label, 0, 160);
    return;
  }
  $allowed = $version === "cloud-vendor/v3"
    ? ["repo", "ref", "run_sha", "source_sha", "run", "attempt", "lock", "evidence"]
    : ["h", "s", "r", "a", "l", "e"];
  $f = [];
  foreach (array_slice($parts, 1) as $p) {
    if (!str_contains($p, "=")) { echo "bad malformed provenance label field"; return; }
    [$k, $v] = explode("=", $p, 2);
    if (!in_array($k, $allowed, true) || array_key_exists($k, $f)) {
      echo "bad unexpected or duplicate provenance label field " . $k;
      return;
    }
    $f[$k] = $v;
  }
  $expectedCount = $version === "cloud-vendor/v3" ? 8 : 6;
  if (count($f) !== $expectedCount) { echo "bad provenance label field count"; return; }
  if ($version === "cloud-vendor/v4") {
    $f = [
      "run_sha" => $f["h"] ?? "",
      "source_sha" => $f["s"] ?? "",
      "run" => $f["r"] ?? "",
      "attempt" => $f["a"] ?? "",
      "lock" => $f["l"] ?? "",
      "evidence" => $f["e"] ?? "",
    ];
  } else {
    foreach (["repo" => $argv[1], "ref" => $argv[2]] as $k => $want) {
      $got = $f[$k] ?? "";
      if ($got !== $want) {
        echo "bad label says $k=" . ($got === "" ? "(absent)" : $got) . ", expected $k=$want";
        return;
      }
    }
  }
  foreach (["lock" => $argv[3]] as $k => $want) {
    $got = $f[$k] ?? "";
    if ($got !== $want) {
      echo "bad label says $k=" . ($got === "" ? "(absent)" : $got) . ", expected $k=$want";
      return;
    }
  }
  if (!preg_match("/^[0-9]+\z/", $f["run"] ?? "")) { echo "bad label has no usable run id"; return; }
  if (!preg_match("/^[0-9]+\z/", $f["attempt"] ?? "")) { echo "bad label has no usable run attempt"; return; }
  if (!preg_match("/^[0-9]+\z/", $f["evidence"] ?? "")) { echo "bad label has no usable evidence artifact id"; return; }
  if (!preg_match("/^[0-9a-f]{40}\z/", $f["run_sha"] ?? "")) { echo "bad label has no usable workflow commit sha"; return; }
  if (!preg_match("/^[0-9a-f]{40}\z/", $f["source_sha"] ?? "")) { echo "bad label has no usable source commit sha"; return; }
  echo "ok " . $f["run"] . " " . $f["run_sha"] . " " . $f["source_sha"] . " " . $f["attempt"] . " " . $f["evidence"];
' "$REPO" "$TRUSTED_REF" "$WANT")"

if [[ "$PROV" != ok\ * ]]; then
  say "REFUSED: $NAME has no trusted publication provenance."
  say "  ${PROV#bad }"
  say "  A bundle is only installable if .github/workflows/cloud-vendor.yml"
  say "  published it from $TRUSTED_REF and recorded an immutable proof. Assets"
  say "  published before immutable provenance are refused here on purpose."
  say "  Recovery: push this composer.lock to $TRUSTED_BRANCH, or run the"
  say "  'Cloud vendor bundle' workflow there. It always rebuilds the bundle"
  say "  and its proof. Nothing was downloaded."
  say "  CLOUD_VENDOR_ALLOW_UNTRUSTED=1 installs it anyway; do that only if you"
  say "  have established by hand where these bytes came from."
  if [[ "${CLOUD_VENDOR_ALLOW_UNTRUSTED:-}" != "1" ]]; then
    exit 1
  fi
  say "WARNING: continuing because CLOUD_VENDOR_ALLOW_UNTRUSTED=1 is set."
  PROV_RUN=""
else
  read -r _ PROV_RUN PROV_RUN_SHA PROV_SOURCE_SHA PROV_ATTEMPT PROV_EVIDENCE <<<"$PROV"
  say "provenance recorded: run $PROV_RUN attempt $PROV_ATTEMPT, source ${PROV_SOURCE_SHA:0:12}, ref $TRUSTED_REF"
fi

# Corroborate the claim against GitHub's immutable record of that run, then
# fetch the exact evidence artifact named by the label. Every failure is fatal:
# continuing on a label alone would restore the original vulnerability.
if [[ -n "${PROV_RUN:-}" ]]; then
  # Read the status separately from the body, because the three outcomes are
  # not interchangeable and --fail-with-body cannot tell them apart:
  #   200  -> an answer; check it, and a mismatch is fatal
  #   404  -> GitHub says this repo has no such run. That is a NEGATIVE ANSWER,
  #           not a missing channel: the label names a run that does not exist,
  #           so the label is fabricated. Fatal.
  #   else -> 401/403/429/5xx or a transport failure: provenance cannot be
  #           established, so fail closed before downloading executable bytes.
  RUN_RAW="$(curl -sS \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    -w '\n%{http_code}' \
    "https://api.github.com/repos/$REPO/actions/runs/$PROV_RUN/attempts/$PROV_ATTEMPT" 2>>"$LOG")"
  RUN_RC=$?
  RUN_HTTP="${RUN_RAW##*$'\n'}"
  RUN_JSON="${RUN_RAW%$'\n'*}"
  if [[ "$RUN_RC" -ne 0 || "$RUN_HTTP" != "200" ]]; then
    say "REFUSED: could not read provenance run $PROV_RUN from the Actions API"
    say "  (HTTP ${RUN_HTTP:-none}, curl $RUN_RC; see $LOG). The token needs"
    say "  Actions read access to verify the immutable proof. Nothing was downloaded."
    exit 1
  else
    RUN_VERDICT="$(printf '%s' "$RUN_JSON" | php -d display_errors=stderr -r '
      $r = json_decode(stream_get_contents(STDIN), true);
      if (!is_array($r) || !isset($r["id"])) { echo "bad the run lookup did not return a run"; return; }
      $checks = [
        "repository" => [(string) ($r["repository"]["full_name"] ?? ""), $argv[1]],
        "workflow"   => [(string) ($r["path"] ?? ""), $argv[2]],
        "branch"     => [(string) ($r["head_branch"] ?? ""), $argv[3]],
        "commit"     => [(string) ($r["head_sha"] ?? ""), $argv[4]],
        "attempt"    => [(string) ($r["run_attempt"] ?? ""), $argv[6]],
      ];
      foreach ($checks as $what => $pair) {
        [$got, $want] = $pair;
        if ($got !== $want) {
          echo "bad GitHub says run " . $argv[5] . " has $what \"" . ($got === "" ? "(absent)" : $got) . "\", the label claims \"$want\"";
          return;
        }
      }
      echo "ok " . $argv[2] . " on " . $argv[3] . " (" . ($r["status"] ?? "?") . "/" . ($r["conclusion"] ?? "?") . ")";
    ' "$REPO" "$WORKFLOW_PATH" "$TRUSTED_BRANCH" "$PROV_RUN_SHA" "$PROV_RUN" "$PROV_ATTEMPT")"
    if [[ "$RUN_VERDICT" != ok\ * ]]; then
      say "REFUSED: the provenance label on $NAME does not match GitHub's record"
      say "  of the run it names."
      say "  ${RUN_VERDICT#bad }"
      say "  Treat the stored asset as suspect. Nothing was downloaded."
      exit 1
    fi
    say "provenance corroborated: run $PROV_RUN was ${RUN_VERDICT#ok }"
  fi

  PROVENANCE_ARTIFACT="cloud-vendor-provenance-$WANT-$PROV_RUN-$PROV_ATTEMPT"
  PROVENANCE_MANIFEST="cloud-vendor-provenance-$WANT.txt"
  if ! EVIDENCE_JSON="$(api "https://api.github.com/repos/$REPO/actions/artifacts/$PROV_EVIDENCE" 2>>"$LOG")"; then
    say "REFUSED: immutable provenance artifact $PROV_EVIDENCE is missing or"
    say "  unreadable. It may have expired or been deleted. Run 'Cloud vendor"
    say "  bundle' on $TRUSTED_BRANCH to publish a fresh bundle/proof pair."
    say "  Nothing was downloaded (see $LOG)."
    exit 1
  fi
  EVIDENCE_META="$(printf '%s' "$EVIDENCE_JSON" | php -d display_errors=stderr -r '
    $a = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($a) || !isset($a["id"])) { echo "bad artifact lookup did not return an artifact"; return; }
    $checks = [
      "id" => [(string) ($a["id"] ?? ""), $argv[1]],
      "name" => [(string) ($a["name"] ?? ""), $argv[2]],
      "run" => [(string) ($a["workflow_run"]["id"] ?? ""), $argv[3]],
    ];
    foreach ($checks as $what => [$got, $want]) {
      if ($got !== $want) { echo "bad artifact has $what " . ($got === "" ? "(absent)" : $got) . ", expected " . $want; return; }
    }
    if (($a["expired"] ?? true) !== false) { echo "bad artifact has expired"; return; }
    $digest = (string) ($a["digest"] ?? "");
    if (!preg_match("/^sha256:[0-9a-f]{64}\z/", $digest)) { echo "bad artifact has no usable digest"; return; }
    echo "ok " . $digest;
  ' "$PROV_EVIDENCE" "$PROVENANCE_ARTIFACT" "$PROV_RUN")"
  if [[ "$EVIDENCE_META" != ok\ * ]]; then
    say "REFUSED: evidence artifact $PROV_EVIDENCE does not match the trusted run."
    say "  ${EVIDENCE_META#bad }"
    say "  Run 'Cloud vendor bundle' on $TRUSTED_BRANCH to publish a fresh pair."
    say "  Nothing was downloaded."
    exit 1
  fi
  EVIDENCE_DIGEST="${EVIDENCE_META#ok }"

  if ! PROVENANCE_STAGE="$(mktemp -d "${TMPDIR:-/tmp}/vendor-bundle-provenance.XXXXXX")"; then
    say "FAILED: could not create a temporary directory for provenance evidence."
    exit 1
  fi
  if ! api -L "https://api.github.com/repos/$REPO/actions/artifacts/$PROV_EVIDENCE/zip" \
    -o "$PROVENANCE_STAGE/provenance.zip" 2>>"$LOG"; then
    say "REFUSED: immutable provenance artifact $PROV_EVIDENCE expired, was"
    say "  deleted, or could not be downloaded (see $LOG). Run 'Cloud vendor"
    say "  bundle' on $TRUSTED_BRANCH to publish a fresh bundle/proof pair."
    say "  Nothing was downloaded."
    exit 1
  fi
  if ! PROOF="$(verify_provenance_artifact \
    "$PROVENANCE_STAGE/provenance.zip" "$EVIDENCE_DIGEST" "$PROVENANCE_MANIFEST" \
    "$REPO" "$WORKFLOW_PATH" "$TRUSTED_REF" "$PROV_RUN_SHA" "$PROV_SOURCE_SHA" "$PROV_RUN" \
    "$PROV_ATTEMPT" "$WANT" "$NAME" "$PROVENANCE_ARTIFACT")"; then
    say "REFUSED: immutable provenance artifact $PROV_EVIDENCE is invalid."
    say "  ${PROOF#bad }"
    say "  Nothing was downloaded."
    exit 1
  fi
  PROVENANCE_BUNDLE_SHA="${PROOF#ok }"
  if [[ -n "$ASSET_DIGEST" && "$ASSET_DIGEST" != "sha256:$PROVENANCE_BUNDLE_SHA" ]]; then
    say "REFUSED: the mutable release metadata and immutable provenance disagree."
    say "  release:    $ASSET_DIGEST"
    say "  provenance: sha256:$PROVENANCE_BUNDLE_SHA"
    say "  Nothing was downloaded."
    exit 1
  fi
  say "immutable provenance verified (artifact $PROV_EVIDENCE, bundle sha256:$PROVENANCE_BUNDLE_SHA)"
fi

# The bundle is downloaded and unpacked HERE, inside the checkout, as a sibling
# of vendor/ -- not under TMPDIR -- so that swap_in_vendor()'s final move is a
# rename(2) on one filesystem rather than a long, interruptible recursive copy
# across two. See the note on that function for what the copy used to cost.
#
# The price is peak space: the checkout's filesystem must now hold the
# compressed bundle, the unpacked tree AND the tree being replaced at the same
# time (roughly 2.5x vendor/) for the length of the swap. Both transient trees
# are removed by cleanup() on every exit path.
STAGE=".vendor-bundle-stage.$$"

# The old vendor/ is moved aside rather than deleted, so a failed swap can put
# it back. It has to land on the same filesystem (moving ~600MB across one
# would be a copy), which means inside the checkout -- so clean it up on every
# exit path, or an interrupted run leaves a stray directory behind.
# /vendor.replaced.* and /.vendor-bundle-stage.* are gitignored for the case
# where even that does not run.
OLD="vendor.replaced.$$"
# Set by swap_in_vendor() when the previous tree could not be put back, so that
# cleanup() does not delete the only good copy that is left.
# The cleanup trap was armed before provenance verification, so it also removes
# the downloaded proof on every exit path.

rm -rf "$STAGE"
if ! mkdir -p "$STAGE"; then
  say "FAILED: could not create the staging directory $PWD/$STAGE"
  exit 1
fi

say "downloading $NAME"
if ! curl -sSL --fail-with-body \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/octet-stream" \
  "https://api.github.com/repos/$REPO/releases/assets/$ASSET_ID" \
  -o "$STAGE/bundle.tar.zst" 2>>"$LOG"; then
  say "FAILED: could not download the bundle (see $LOG)"
  exit 1
fi

# Check the bytes against the immutable trusted-run manifest BEFORE unpacking
# them, because unpacking is followed by `composer dump-autoload`, which executes
# code out of the tree. The release API digest remains a second transport check,
# but it is mutable alongside the release asset and is not provenance.
GOT="$(file_sha256 "$STAGE/bundle.tar.zst")"
if [[ -n "$PROVENANCE_BUNDLE_SHA" ]]; then
  if ! verify_provenance_bundle "$STAGE/bundle.tar.zst" "$PROVENANCE_BUNDLE_SHA"; then
    say "REFUSED: $NAME does not match the immutable proof from run $PROV_RUN."
    say "  expected: sha256:$PROVENANCE_BUNDLE_SHA"
    say "  got:      sha256:$GOT"
    say "  Nothing was unpacked. Treat the release asset as replaced or corrupt."
    exit 1
  fi
  say "bundle matches immutable provenance (sha256:$PROVENANCE_BUNDLE_SHA)"
fi

if [[ -z "$ASSET_DIGEST" ]]; then
  if [[ -n "$PROVENANCE_BUNDLE_SHA" ]]; then
    say "the release API reported no digest; the immutable proof still verifies the bytes"
  elif [[ "${CLOUD_VENDOR_ALLOW_NO_DIGEST:-}" == "1" ]]; then
    say "WARNING: the API reported no digest for $NAME; continuing because"
    say "  CLOUD_VENDOR_ALLOW_NO_DIGEST=1 is set. The bundle is unverified."
  else
    say "FAILED: the API reported no digest for $NAME, so the download cannot"
    say "  be verified. Re-run the 'Cloud vendor bundle' workflow to upload the"
    say "  asset again. Set CLOUD_VENDOR_ALLOW_NO_DIGEST=1 to install it"
    say "  unverified anyway."
    exit 1
  fi
else
  if [[ "$ASSET_DIGEST" != "sha256:$GOT" ]]; then
    say "FAILED: $NAME does not match the digest the API published for it."
    say "  expected: $ASSET_DIGEST"
    say "  got:      sha256:$GOT"
    say "  Nothing was unpacked. Re-run; if it persists, re-run the 'Cloud"
    say "  vendor bundle' workflow and treat the stored asset as suspect."
    exit 1
  fi
  say "digest verified ($ASSET_DIGEST)"
fi

# zstd is NOT installed in the cloud image, and GNU tar's --zstd shells out to
# it, so neither `tar --zstd` nor `unzstd` works there. Node 24 ships a zstd
# decompressor in core zlib; that is the fallback that actually runs today.
decompress_to_tar() {
  if command -v zstd >/dev/null 2>&1; then
    zstd -dc "$STAGE/bundle.tar.zst"
  elif command -v unzstd >/dev/null 2>&1; then
    unzstd -c "$STAGE/bundle.tar.zst"
  elif command -v node >/dev/null 2>&1; then
    node -e '
      const fs = require("fs");
      const zlib = require("zlib");
      if (typeof zlib.createZstdDecompress !== "function") {
        console.error("this Node build has no zstd support (needs Node >= 23)");
        process.exit(1);
      }
      const fail = (e) => { console.error(String((e && e.message) || e)); process.exit(1); };
      const input = fs.createReadStream(process.argv[1]);
      const out = zlib.createZstdDecompress();
      input.on("error", fail);
      out.on("error", fail);
      input.pipe(out).pipe(process.stdout);
    ' "$STAGE/bundle.tar.zst"
  else
    echo "vendor-bundle: no zstd decompressor available (install zstd, or Node >= 23)" >&2
    return 1
  fi
}

say "unpacking"
mkdir -p "$STAGE/unpack"
if ! decompress_to_tar 2>>"$LOG" | tar -xf - -C "$STAGE/unpack" 2>>"$LOG"; then
  say "FAILED: could not unpack the bundle (see $LOG)"
  exit 1
fi
if [[ ! -f "$STAGE/unpack/vendor/autoload.php" ]]; then
  say "FAILED: the bundle did not contain vendor/autoload.php"
  exit 1
fi

# Swap it in, keeping the old tree until the new one is in place so a failed
# move cannot leave the checkout with no vendor/ at all.
swap_in_vendor || exit 1

# The replaced tree's discovery caches name packages that may no longer exist.
# Laravel rebuilds both on the next boot.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# The bundle is built with --no-scripts on a different absolute path. Composer's
# autoloader is path-relative so it already works, but re-dumping runs package
# discovery for this checkout. Never let it reach the network.
if command -v composer >/dev/null 2>&1; then
  if ! COMPOSER_DISABLE_NETWORK=1 composer dump-autoload --no-interaction >>"$LOG" 2>&1; then
    if ! COMPOSER_DISABLE_NETWORK=1 composer dump-autoload --no-interaction --no-scripts >>"$LOG" 2>&1; then
      say "WARNING: composer dump-autoload failed (see $LOG); using the bundle's autoloader"
    fi
  fi
fi

if ! verify_vendor; then
  say "FAILED: vendor/ still disagrees with composer.lock after unpacking $NAME."
  say "  The bundle may have been built from a different lock. Re-run the"
  say "  'Cloud vendor bundle' workflow for this composer.lock."
  exit 1
fi

printf '%s\n' "$WANT" >vendor/.cloud-repo-bootstrap 2>/dev/null || true
say "installed vendor/ from $NAME; matches composer.lock"
exit 0
