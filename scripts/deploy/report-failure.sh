#!/usr/bin/env bash
# Runner-side incident summary. Read-only: never restore service or alter a flag.
set -euo pipefail
release=${DEPLOY_LIVE_RELEASE:-unavailable}
commit=${DEPLOY_LIVE_COMMIT:-unavailable}
state=${DEPLOY_LIVE_STATE:-unavailable}
[[ $release =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || release=unavailable
[[ $commit =~ ^[0-9a-f]{40}$ ]] || commit=unavailable
case $state in serving | maintenance | absent | unavailable) ;; *) state=unavailable ;; esac
status=$(curl --silent --connect-timeout 5 --max-time 10 --output /dev/null \
    --write-out '%{http_code}' https://svc.bherila.net/) || status=unreachable
[[ $status =~ ^[1-5][0-9]{2}$ ]] || status=unreachable
printf '::error::SVC deployment failed; public home HTTP %s; selected state %s. Recovery requires checking the selected release and migration outcome.\n' "$status" "$state"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        printf '### SVC deployment failed\n\n'
        printf -- '- Public home: **%s** (the maintenance-exempt `/up` endpoint is insufficient).\n' "$status"
        printf -- '- Selected release: `%s`\n- Selected commit: `%s`\n- Selected state: **%s**\n\n' "$release" "$commit" "$state"
        printf 'The workflow uses failure-policy: rollback to restore the retained prior code and scheduler entries. Database changes are not rolled back. Verify the finalizer selected and restored the exact prior release before claiming service is healthy.\n\n'
        printf 'If automatic restoration failed, inspect the migration and verification results before restoring service. A retry refuses an app already in maintenance; it does not undo that state. Do not disable maintenance after a failed migration without verifying schema compatibility.\n\n'
        printf 'When rollback succeeded, leave the prior release serving, correct the candidate and rerun deployment. When maintenance remains, require operator verification of the selected release, intended configuration and schema compatibility before manual restoration. Verify the authenticated smoke, exactly one scheduler line, and the healthy-release marker.\n\n'
        printf '[Recovery runbook](https://github.com/bherila/svc/blob/main/docs/mcp-operations.md#deployment-failures-and-recovery)\n'
    } >>"$GITHUB_STEP_SUMMARY"
fi
