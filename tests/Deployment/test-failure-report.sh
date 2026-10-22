#!/usr/bin/env bash
set -euo pipefail
repository=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
cat >"$fixture/curl" <<'MOCK'
#!/usr/bin/env bash
test "${!#}" = https://svc.bherila.net/ || exit 98
test "$FIXTURE_HTTP" != unreachable || exit 7
printf '%s' "$FIXTURE_HTTP"
MOCK
chmod +x "$fixture/curl"
run_report() {
    PATH="$fixture:$PATH" GITHUB_STEP_SUMMARY="$fixture/summary" \
        DEPLOY_LIVE_RELEASE="${FIXTURE_RELEASE:-0123456789ab-42-1}" \
        DEPLOY_LIVE_COMMIT="${FIXTURE_COMMIT:-0123456789abcdef0123456789abcdef01234567}" \
        DEPLOY_LIVE_STATE="${FIXTURE_STATE:-maintenance}" \
        bash "$repository/scripts/deploy/report-failure.sh"
}
for response in 503 200 unreachable; do
    rm -f "$fixture/summary"
    FIXTURE_HTTP=$response run_report >"$fixture/output"
    grep -Fq "public home HTTP $response" "$fixture/output" || fail 'public status missing from annotation'
    grep -Fq "Public home: **$response**" "$fixture/summary" || fail 'public status missing from summary'
    grep -Fq 'Selected release: `0123456789ab-42-1`' "$fixture/summary" || fail 'reviewed release missing'
    grep -Fq 'failure-policy: rollback' "$fixture/summary" || fail 'current rollback policy missing'
    grep -Fq 'Database changes are not rolled back' "$fixture/summary" || fail 'database compatibility warning missing'
    grep -Fq 'retry refuses an app already in maintenance' "$fixture/summary" || fail 'maintenance recovery missing'
done
rm -f "$fixture/summary"
FIXTURE_HTTP=503 FIXTURE_RELEASE='SYNTHETIC_PRIVATE_VALUE;id' FIXTURE_COMMIT='SYNTHETIC_PRIVATE_VALUE' \
    FIXTURE_STATE='SYNTHETIC_PRIVATE_VALUE' run_report >"$fixture/output"
if grep -Fq SYNTHETIC_PRIVATE_VALUE "$fixture/output" "$fixture/summary"; then
    fail 'untrusted action output was relayed'
fi
grep -Fq 'Selected state: **unavailable**' "$fixture/summary" || fail 'invalid state was accepted'
echo 'SVC deployment failure report tests passed.'
