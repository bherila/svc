# MCP operational runbook

This runbook applies to the Streamable HTTP endpoint at `/api/v1/mcp`. It
contains no bearer values, OAuth keys, request bodies, record exports, or
customer data. Preserve those constraints in incident tickets and logs.

## Immediate containment

1. Set `AGENT_API_MCP_ENABLED=false` in the protected deployment environment.
   Deploy the configuration change and clear Laravel's configuration cache using
   the normal deployment workflow. Authenticated POST and DELETE requests then
   receive a no-store `503` with `Retry-After: 60`; no server or session is
   constructed. `OPTIONS` remains available for browser preflight. Existing
   sessions cannot recover a capability on a subsequent request.
2. For a single capability, set its named entry in
   `agent_api.mcp_feature_flags` to `false` (the stable feature-flag name in
   `docs/mcp.md` is preferred; the public capability name is a compatibility
   fallback). Deploy and clear configuration cache. Do not remove a route or
   rename a capability during an incident.
3. Preserve only metadata needed for investigation: request ID, UTC timestamp,
   public workspace ID when already known, OAuth client ID, capability name,
   and safe error category. Never copy authorization headers, MCP arguments,
   results, invoice documents, free text, or user email addresses.

## Credential containment

MCP authentication is Passport OAuth, not a separate MCP password or API-key
store. Revoke the affected OAuth connection through the authenticated
`DELETE /api/v1/connections/{token}` workflow where the affected user can do
so. The endpoint calls Passport's token revocation and the MCP principal
resolver rereads the persisted access-token row on every request, so a revoked,
expired, wrong-audience, or wrong-client credential is rejected before
discovery and execution.

For a suspected client-wide compromise, first globally disable MCP, then use
the established OAuth administration procedure to revoke the affected access
and refresh-token family. Do not rotate the OAuth signing keys as a first
response: the deployment workflow deliberately refuses automatic partial key
rotation, and a key rotation is a separate authorization-server incident.

## Recovery and rollback

1. Confirm the affected capability's feature flag remains false while the
   incident is investigated.
2. Reproduce only with synthetic `.test` data and a short-lived test OAuth
   credential. Run `scripts/mcp-smoke-ci.sh`; it creates its own ephemeral
   Passport keys and synthetic workspace.
3. Ship a fix as a stacked commit on the MCP PR. Run focused tests and static
   analysis, then rely on the concurrent hosted CI gates for the exact pushed
   head. Do not merge or deploy a head whose MCP smoke, MariaDB, CI,
   data-safety, or security gates are incomplete.
4. Re-enable the capability first for its reviewed cohort/feature flag, verify
   discovery and direct-call denial behavior, then remove the containment flag.
   Global MCP enablement is last.

MCP has no schema migration of its own. Roll back application code/configuration
using the normal deployment mechanism; do not roll back unrelated database
migrations or delete OAuth rows merely to recover the endpoint.

## Security release checks

Before publishing an MCP head, run `composer audit --no-interaction` and
`pnpm audit --prod --audit-level=high` against the checked-in locks. Treat a
new advisory as a release blocker until it is upgraded, removed, or has a
documented, reviewed exception. Run the disclosure scan as part of the same
candidate checks; do not paste its findings into an issue because matched
values are intentionally redacted.

## Monitoring and alerts

The deployment dashboard is named **SVC MCP Safety**. Its application telemetry
is the metadata-only `mcp.capability.executed` audit event and matching
`McpCapabilityInvoked` event. Its HTTP-status panel uses aggregate deployment
request metrics for the MCP route, never bodies or headers. Permitted event
dimensions are `capability`, `rate_limit_bucket`, `audit_classification`,
`outcome`, `duration_ms`, and request ID. Do not index or display arguments,
results, headers, account IDs, user email addresses, raw credential IDs, or
token values. Credential and client fingerprints are for investigated,
access-controlled correlation only; they are not dashboard dimensions.

The dashboard has these panels, grouped by capability and five-minute window:

| Panel                            | Signal                                                                               | Initial alert                                                                                                                                  |
| -------------------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Invocations                      | Count of `mcp.capability.executed`                                                   | No alert; use as the denominator for rate alerts.                                                                                              |
| Authentication and authorization | Requests rejected by the MCP route, plus `outcome=error`                             | Page the on-call owner when the error rate exceeds 5% and at least 20 MCP requests occur in five minutes.                                      |
| Availability guards              | `rate_limit_unavailable`, `concurrency_unavailable`, and `result_too_large` outcomes | Page immediately for any limiter/concurrency backend unavailability; create a ticket for repeated result-size rejections (five in 15 minutes). |
| Saturation                       | `rate_limited` and `concurrency_limited` outcomes                                    | Alert the service owner when either exceeds 2% of calls and there are at least 20 calls in five minutes.                                       |
| Latency                          | p50/p95 `duration_ms`                                                                | Alert the service owner when p95 exceeds 5 seconds for 10 minutes, with at least 20 calls in the window.                                       |
| Audit/metrics delivery           | `mcp.capability.metrics_unavailable` log events                                      | Page immediately: visibility is degraded even though capability requests remain safely available.                                              |
| Configuration                    | Global MCP and per-capability feature-flag state                                     | Alert on every change; link the change to its deployment, request ID if applicable, and the containment runbook.                               |

The thresholds are initial deployment defaults. Tune them only from aggregate,
payload-free production observations and retain the previous threshold and
rationale in the deployment change record. Audit and metrics sink failures are
deliberately non-fatal to MCP callers and contain no payload fallback; monitor
those sinks through the deployment platform.
