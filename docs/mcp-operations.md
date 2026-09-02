# MCP operational runbook

This runbook applies to the Streamable HTTP endpoint at `/api/v1/mcp`. It
contains no bearer values, OAuth keys, request bodies, record exports, or
customer data. Preserve those constraints in incident tickets and logs.

## Immediate containment

1. Set `AGENT_API_MCP_ENABLED=false` in the protected deployment environment.
   Deploy the configuration change and clear Laravel's configuration cache using
   the normal deployment workflow. The next request omits every MCP capability;
   existing HTTP sessions cannot recover a capability on a subsequent request.
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

## Monitoring baseline

Watch request volume, 401/403/429 and safe MCP error categories, handler
duration, active-session/cache failures, OAuth revocation failures, and the
per-capability feature-flag state. Alerts must use capability and request IDs,
not tool arguments or results. The current application has global route
throttling, credential-bound per-capability `mcp-read`/`mcp-write` buckets,
capability kill switches, and `mcp.capability.executed` metadata-only audit
events. Per-capability metrics remain hardening work and must be validated
before general availability.
