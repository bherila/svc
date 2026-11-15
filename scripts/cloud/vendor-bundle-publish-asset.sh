#!/usr/bin/env bash
# Safely replace one cloud-vendor release asset after its bundle and immutable
# provenance manifest have already been validated by the publishing workflow.

set -euo pipefail

if [[ $# -ne 5 ]]; then
  printf 'usage: %s RELEASE_ID BUNDLE_PATH ASSET_NAME LABEL SHA256\n' "$0" >&2
  exit 2
fi

release_id="$1"
bundle_path="$2"
asset_name="$3"
label="$4"
want="$5"

REPO="${REPO:?REPO is required}"
GH_TOKEN="${GH_TOKEN:?GH_TOKEN is required}"
run_id="${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
run_attempt="${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}"
retry_delays="${CLOUD_VENDOR_PUBLISH_RETRY_DELAYS:-0 1 2 4 8}"

if [[ ! "$release_id" =~ ^[0-9]+$ ]] || [[ ! "$asset_name" =~ ^vendor-[0-9a-f]{64}\.tar\.zst$ ]] || [[ ! "$want" =~ ^[0-9a-f]{64}$ ]]; then
  printf 'cloud-vendor publish: invalid release, asset name or digest\n' >&2
  exit 1
fi
if (( ${#label} > 255 )); then
  printf 'cloud-vendor publish: release asset label is %s characters; GitHub allows at most 255\n' "${#label}" >&2
  exit 1
fi
if [[ ! -f "$bundle_path" ]]; then
  printf 'cloud-vendor publish: bundle does not exist: %s\n' "$bundle_path" >&2
  exit 1
fi
got="$(sha256sum "$bundle_path" | cut -d' ' -f1)"
if [[ "$got" != "$want" ]]; then
  printf 'cloud-vendor publish: bundle digest %s does not match %s\n' "$got" "$want" >&2
  exit 1
fi
for delay in $retry_delays; do
  if [[ ! "$delay" =~ ^[0-9]+$ ]]; then
    printf 'cloud-vendor publish: retry delays must be non-negative integers\n' >&2
    exit 1
  fi
done

candidate_name="$asset_name.candidate-$run_id-$run_attempt"
backup_name="$asset_name.previous-$run_id-$run_attempt"
response_body=''
response_http=''
response_rc=0

api_detail() {
  printf '%s' "$1" | php -d display_errors=stderr -r '
    $raw = stream_get_contents(STDIN);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $message = trim((string) ($json["message"] ?? ""));
        $errors = $json["errors"] ?? [];
        $detail = $message;
        if (is_array($errors) && $errors !== []) {
            $encoded = json_encode($errors, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) { $detail .= ($detail === "" ? "" : ": ") . $encoded; }
        }
    } else {
        $detail = $raw;
    }
    $detail = preg_replace("/[[:cntrl:]]+/", " ", (string) $detail);
    echo substr(trim((string) $detail), 0, 800);
  '
}

set_response() {
  local raw="$1"
  response_http="${raw##*$'\n'}"
  response_body="${raw%$'\n'*}"
}

upload_candidate() {
  local encoded_name encoded_label raw
  encoded_name="$(VALUE="$candidate_name" php -r 'echo rawurlencode((string) getenv("VALUE"));')"
  encoded_label="$(VALUE="$label" php -r 'echo rawurlencode((string) getenv("VALUE"));')"
  response_rc=0
  raw="$(curl -sS -X POST \
    -H "Authorization: Bearer $GH_TOKEN" \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    -H 'Content-Type: application/zstd' \
    --data-binary @"$bundle_path" \
    -w $'\n%{http_code}' \
    "https://uploads.github.com/repos/$REPO/releases/$release_id/assets?name=$encoded_name&label=$encoded_label")" || response_rc=$?
  set_response "$raw"
  if [[ "$response_rc" -ne 0 || "$response_http" != 201 ]]; then
    printf 'cloud-vendor publish: candidate upload failed (HTTP %s, curl %s): %s\n' \
      "${response_http:-none}" "$response_rc" "$(api_detail "$response_body")" >&2
    return 1
  fi
  return 0
}

patch_asset() {
  local asset_id="$1"
  local next_name="$2"
  local next_label="${3-}"
  local include_label="${4:-0}"
  local payload raw
  payload="$(NAME="$next_name" LABEL="$next_label" INCLUDE_LABEL="$include_label" php -r '
    $payload = ["name" => (string) getenv("NAME")];
    if (getenv("INCLUDE_LABEL") === "1") { $payload["label"] = (string) getenv("LABEL"); }
    echo json_encode($payload, JSON_THROW_ON_ERROR);
  ')"
  response_rc=0
  raw="$(curl -sS -X PATCH \
    -H "Authorization: Bearer $GH_TOKEN" \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    -H 'Content-Type: application/json' \
    --data "$payload" \
    -w $'\n%{http_code}' \
    "https://api.github.com/repos/$REPO/releases/assets/$asset_id")" || response_rc=$?
  set_response "$raw"
  [[ "$response_rc" -eq 0 && "$response_http" == 200 ]]
}

asset_verdict() {
  local body="$1"
  local asset_id="$2"
  local expected_name="$3"
  local expected_digest="$4"
  local expected_label="$5"
  local expected_api_digest=''
  if [[ -n "$expected_digest" ]]; then
    expected_api_digest="sha256:$expected_digest"
  fi
  printf '%s' "$body" | php -d display_errors=stderr -r '
    $asset = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($asset)) { echo "bad invalid asset response"; exit(1); }
    $checks = [
        "id" => [(string) ($asset["id"] ?? ""), $argv[1]],
        "name" => [(string) ($asset["name"] ?? ""), $argv[2]],
        "label" => [(string) ($asset["label"] ?? ""), $argv[4]],
    ];
    if ($argv[3] !== "") {
        $checks["digest"] = [(string) ($asset["digest"] ?? ""), $argv[3]];
    }
    foreach ($checks as $field => [$got, $want]) {
        if ($got !== $want) { echo "bad stored $field does not match"; exit(1); }
    }
    echo "ok";
  ' "$asset_id" "$expected_name" "$expected_api_digest" "$expected_label"
}

patch_with_retries() {
  local asset_id="$1"
  local next_name="$2"
  local next_label="$3"
  local include_label="$4"
  local expected_digest="$5"
  local expected_label="$6"
  local delay verdict
  for delay in $retry_delays; do
    if ((delay > 0)); then
      sleep "$delay"
    fi
    if patch_asset "$asset_id" "$next_name" "$next_label" "$include_label"; then
      verdict="$(asset_verdict "$response_body" "$asset_id" "$next_name" "$expected_digest" "$expected_label" 2>&1)" || true
      if [[ "$verdict" == ok ]]; then
        return 0
      fi
      printf 'cloud-vendor publish: asset update verification failed: %s\n' "$verdict" >&2
    else
      printf 'cloud-vendor publish: asset update to %s failed (HTTP %s, curl %s): %s\n' \
        "$next_name" "${response_http:-none}" "$response_rc" "$(api_detail "$response_body")" >&2
    fi
  done
  return 1
}

delete_asset() {
  gh api -X DELETE "repos/$REPO/releases/assets/$1" >/dev/null
}

if ! upload_candidate; then
  exit 1
fi

candidate_id="$(printf '%s' "$response_body" | php -r '
  $asset = json_decode(stream_get_contents(STDIN), true);
  $id = is_array($asset) ? (string) ($asset["id"] ?? "") : "";
  if (!preg_match("/^[0-9]+$/", $id)) { exit(1); }
  echo $id;
')" || {
  printf 'cloud-vendor publish: upload response did not contain a usable asset id\n' >&2
  exit 1
}

if ! candidate_json="$(gh api "repos/$REPO/releases/assets/$candidate_id")"; then
  printf 'cloud-vendor publish: could not read uploaded candidate %s\n' "$candidate_id" >&2
  exit 1
fi
candidate_verdict="$(asset_verdict "$candidate_json" "$candidate_id" "$candidate_name" "$want" "$label" 2>&1)" || true
if [[ "$candidate_verdict" != ok ]]; then
  printf 'cloud-vendor publish: refusing unverified candidate: %s\n' "$candidate_verdict" >&2
  delete_asset "$candidate_id" || true
  exit 1
fi

if ! old_asset_id="$(gh api "repos/$REPO/releases/$release_id/assets?per_page=100" \
  --jq "([.[] | select(.name == \"$asset_name\")][0] // {}) | .id // \"\"")"; then
  printf 'cloud-vendor publish: could not look up the existing canonical asset\n' >&2
  delete_asset "$candidate_id" || true
  exit 1
fi

old_label=''
old_digest=''
if [[ -n "$old_asset_id" ]]; then
  if ! old_json="$(gh api "repos/$REPO/releases/assets/$old_asset_id")"; then
    printf 'cloud-vendor publish: could not read existing canonical asset %s\n' "$old_asset_id" >&2
    delete_asset "$candidate_id" || true
    exit 1
  fi
  if ! old_digest="$(printf '%s' "$old_json" | php -r '
    $asset = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($asset)) { exit(1); }
    echo (string) ($asset["digest"] ?? "");
  ')"; then
    printf 'cloud-vendor publish: existing canonical asset response was invalid\n' >&2
    delete_asset "$candidate_id" || true
    exit 1
  fi
  if ! old_label="$(printf '%s' "$old_json" | php -r '
    $asset = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($asset)) { exit(1); }
    $label = preg_replace("/[[:cntrl:]]+/", " ", (string) ($asset["label"] ?? ""));
    echo trim((string) $label);
  ')"; then
    printf 'cloud-vendor publish: existing canonical asset response was invalid\n' >&2
    delete_asset "$candidate_id" || true
    exit 1
  fi
  old_digest="${old_digest#sha256:}"
  if [[ ! "$old_asset_id" =~ ^[0-9]+$ ]] || { [[ -n "$old_digest" ]] && [[ ! "$old_digest" =~ ^[0-9a-f]{64}$ ]]; }; then
    printf 'cloud-vendor publish: existing canonical asset metadata is unusable\n' >&2
    delete_asset "$candidate_id" || true
    exit 1
  fi
  if ! patch_with_retries "$old_asset_id" "$backup_name" "$old_label" 1 "$old_digest" "$old_label"; then
    printf 'cloud-vendor publish: canonical backup rename was not confirmed; restoring its canonical name\n' >&2
    if patch_with_retries "$old_asset_id" "$asset_name" "$old_label" 1 "$old_digest" "$old_label"; then
      delete_asset "$candidate_id" || true
      printf 'cloud-vendor publish: previous canonical asset restored\n' >&2
    else
      printf 'cloud-vendor publish: automatic rollback failed; both asset ids were preserved (%s, %s)\n' \
        "$old_asset_id" "$candidate_id" >&2
    fi
    exit 1
  fi
fi

if ! patch_with_retries "$candidate_id" "$asset_name" "$label" 1 "$want" "$label"; then
  if [[ -n "$old_asset_id" ]]; then
    printf 'cloud-vendor publish: candidate promotion failed; restoring the previous canonical asset\n' >&2
    candidate_demoted=0
    old_restored=0
    if patch_with_retries "$candidate_id" "$candidate_name" "$label" 1 "$want" "$label"; then
      candidate_demoted=1
    fi
    if patch_with_retries "$old_asset_id" "$asset_name" "$old_label" 1 "$old_digest" "$old_label"; then
      old_restored=1
    fi
    if [[ "$candidate_demoted" -eq 1 && "$old_restored" -eq 1 ]]; then
      delete_asset "$candidate_id" || true
      printf 'cloud-vendor publish: previous canonical asset restored\n' >&2
    else
      printf 'cloud-vendor publish: automatic rollback failed; both asset ids were preserved (%s, %s)\n' \
        "$old_asset_id" "$candidate_id" >&2
    fi
  else
    printf 'cloud-vendor publish: candidate promotion failed; verified candidate asset %s was preserved\n' \
      "$candidate_id" >&2
  fi
  exit 1
fi

if [[ -n "$old_asset_id" ]] && ! delete_asset "$old_asset_id"; then
  printf '::warning::published canonical asset %s, but could not remove backup asset %s\n' \
    "$candidate_id" "$old_asset_id" >&2
fi

printf 'published canonical release asset %s (id %s, sha256:%s)\n' "$asset_name" "$candidate_id" "$want"
