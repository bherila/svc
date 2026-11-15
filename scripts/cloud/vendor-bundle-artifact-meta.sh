#!/usr/bin/env bash
# Read a paginated GitHub Actions artifact-list response from stdin and return
# the one immutable proof belonging to the requested run and artifact name.

set -euo pipefail

NAME="${1:?artifact name is required}"
RUN="${2:?workflow run id is required}"

php -d display_errors=stderr -r '
try {
    $pages = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($pages)) { throw new RuntimeException("invalid artifact pages"); }
    $matches = [];
    foreach ($pages as $page) {
        if (!is_array($page) || !is_array($page["artifacts"] ?? null)) {
            throw new RuntimeException("invalid artifact REST response shape");
        }
        foreach ($page["artifacts"] as $artifact) {
            if (($artifact["name"] ?? null) === $argv[1]
                && ($artifact["expired"] ?? null) === false
                && (string) ($artifact["workflow_run"]["id"] ?? "") === $argv[2]) {
                $matches[] = $artifact;
            }
        }
    }
    if (count($matches) !== 1) {
        throw new RuntimeException("expected exactly one unexpired immutable proof in the trusted run");
    }
    $id = (string) ($matches[0]["id"] ?? "");
    $digest = (string) ($matches[0]["digest"] ?? "");
    if (!preg_match("/^[0-9]+$/", $id) || !preg_match("/^sha256:[0-9a-f]{64}$/", $digest)) {
        throw new RuntimeException("immutable proof metadata is invalid");
    }
    printf("%s\t%s\n", $id, $digest);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
' "$NAME" "$RUN"
