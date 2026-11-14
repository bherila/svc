#!/usr/bin/env bash
# Build the trusted source-commit matrix for cloud-vendor publication.
#
# The release asset list is only a list of lock hashes worth keeping warm. Its
# bytes, labels and timestamps are mutable and are never trusted here. For each
# requested hash, this script independently finds a composer.lock with that
# digest in the ancestry of the main commit that triggered this workflow. A
# later job checks out that exact commit and rebuilds vendor/ from source.

set -euo pipefail

MODE="${1:-current}"
case "$MODE" in
  current | retained) ;;
  *)
    printf 'usage: %s [current|retained]\n' "$0" >&2
    exit 2
    ;;
esac

REPO="${REPO:-${GITHUB_REPOSITORY:-bherila/svc}}"
TAG="${TAG:-cloud-vendor}"
KEEP="${KEEP:-20}"
TRUSTED_SHA="${GITHUB_SHA:?GITHUB_SHA is required}"
OUTPUT="${GITHUB_OUTPUT:?GITHUB_OUTPUT is required}"

if [[ ! "$TRUSTED_SHA" =~ ^[0-9a-f]{40}$ ]] || ! git cat-file -e "$TRUSTED_SHA^{commit}"; then
  printf 'cloud-vendor plan: %s is not an available commit\n' "$TRUSTED_SHA" >&2
  exit 1
fi
if [[ ! "$KEEP" =~ ^[1-9][0-9]*$ ]]; then
  printf 'cloud-vendor plan: KEEP must be a positive integer\n' >&2
  exit 1
fi

current_lock="$(git show "$TRUSTED_SHA:composer.lock" | sha256sum | cut -d' ' -f1)"
declare -a retained_locks=()

if [[ "$MODE" == retained ]]; then
  if ! drafts="$(gh api --paginate "repos/$REPO/releases?per_page=100" \
    --jq ".[] | select(.draft and .tag_name == \"$TAG\") | .id")"; then
    printf 'cloud-vendor plan: could not list releases; refusing an incomplete refresh plan\n' >&2
    exit 1
  fi
  release_id="$(grep -m1 . <<<"$drafts" || true)"
  if [[ -n "$release_id" ]]; then
    if ! asset_pages="$(gh api --paginate --slurp "repos/$REPO/releases/$release_id/assets?per_page=100")"; then
      printf 'cloud-vendor plan: could not list retained assets; refusing an incomplete refresh plan\n' >&2
      exit 1
    fi
    if ! retained_output="$(printf '%s' "$asset_pages" | php -d display_errors=stderr -r '
      $pages = json_decode(stream_get_contents(STDIN), true);
      if (!is_array($pages)) { fwrite(STDERR, "invalid asset listing\n"); exit(1); }
      $assets = [];
      foreach ($pages as $page) {
          if (!is_array($page)) { fwrite(STDERR, "invalid asset page\n"); exit(1); }
          foreach ($page as $asset) { $assets[] = $asset; }
      }
      usort($assets, static fn (array $a, array $b): int => strcmp((string) ($b["created_at"] ?? ""), (string) ($a["created_at"] ?? "")));
      $seen = [];
      foreach ($assets as $asset) {
          if (!preg_match("/^vendor-([0-9a-f]{64})\\.tar\\.zst$/", (string) ($asset["name"] ?? ""), $matches)) { continue; }
          if ($matches[1] === $argv[1]) { continue; }
          if (isset($seen[$matches[1]])) { continue; }
          $seen[$matches[1]] = true;
          echo $matches[1], "\n";
      }
    ' "$current_lock")"; then
      printf 'cloud-vendor plan: could not parse retained assets; refusing an incomplete refresh plan\n' >&2
      exit 1
    fi
    mapfile -t retained_locks <<<"$retained_output"
  fi
fi

# The current lock is rebuilt from the workflow's exact head. Historical locks
# use the newest commit in that head's ancestry carrying the same bytes. The
# walk itself is the ancestry proof; the explicit merge-base check below makes
# that invariant visible and prevents a future refactor from weakening it.
declare -A source_for=(["$current_lock"]="$TRUSTED_SHA")
commit_list="$(mktemp "${TMPDIR:-/tmp}/cloud-vendor-commits.XXXXXX")"
trap 'rm -f "$commit_list"' EXIT
git rev-list "$TRUSTED_SHA" -- composer.lock >"$commit_list"
while IFS= read -r commit; do
  lock="$(git show "$commit:composer.lock" | sha256sum | cut -d' ' -f1)"
  if [[ -z "${source_for[$lock]:-}" ]]; then
    source_for["$lock"]="$commit"
  fi
done <"$commit_list"

append_target() {
  local lock="$1"
  local source_sha="$2"

  if ! git merge-base --is-ancestor "$source_sha" "$TRUSTED_SHA"; then
    printf 'cloud-vendor plan: refusing non-ancestor source %s for %s\n' "$source_sha" "$lock" >&2
    exit 1
  fi
  got="$(git show "$source_sha:composer.lock" | sha256sum | cut -d' ' -f1)"
  if [[ "$got" != "$lock" ]]; then
    printf 'cloud-vendor plan: source %s has lock %s, expected %s\n' "$source_sha" "$got" "$lock" >&2
    exit 1
  fi
  targets="$(php -r '
    $targets = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $targets[] = ["lock" => $argv[2], "source_sha" => $argv[3]];
    echo json_encode($targets, JSON_THROW_ON_ERROR);
  ' "$targets" "$lock" "$source_sha")"
}

# Keep the current lock first. The publisher relies on this order so a valid
# current build is published before a later retained target can report an error.
targets='[]'
append_target "$current_lock" "$TRUSTED_SHA"

retained_limit=$((KEEP - 1))
retained_count=0
for lock in "${retained_locks[@]:-}"; do
  if ((retained_count >= retained_limit)); then
    break
  fi
  [[ -n "$lock" ]] || continue
  source_sha="${source_for[$lock]:-}"
  if [[ -z "$source_sha" ]]; then
    printf '::warning::retained vendor bundle %s has no matching composer.lock in trusted main ancestry; it will not be blessed or replaced\n' "$lock"
    continue
  fi

  append_target "$lock" "$source_sha"
  retained_count=$((retained_count + 1))
done

count="$(php -r 'echo count(json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));' "$targets")"
matrix="$(php -r 'echo json_encode(["include" => json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);' "$targets")"
{
  printf 'count=%s\n' "$count"
  printf 'current_lock=%s\n' "$current_lock"
  printf 'targets=%s\n' "$targets"
  printf 'matrix=%s\n' "$matrix"
} >>"$OUTPUT"

printf 'cloud-vendor plan: %s trusted main-ancestor lock(s) selected (%s mode)\n' "$count" "$MODE"
