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
2. To stop new OAuth metadata discovery, client registration, authorization,
   token exchange, and refresh without invalidating an already-issued bound
   credential, set `AGENT_API_OAUTH_SERVER_ENABLED=false`, deploy, and clear the
   configuration cache. Those issuance routes return non-cacheable 404 responses.
   This is not credential revocation and does not replace the MCP kill switch.
3. For a single capability, set its named entry in
   `agent_api.mcp_feature_flags` to `false`. The stable feature-flag name in
   `docs/mcp.md` disables its group; the public capability name disables only
   that capability. Both switches must allow execution: an explicit `true`
   never overrides a `false` on the other switch. Deploy and clear configuration
   cache. Do not remove a route or rename a capability during an incident.
4. Preserve only metadata needed for investigation: request ID, UTC timestamp,
   public workspace ID when already known, OAuth client ID, capability name,
   and safe error category. Never copy authorization headers, MCP arguments,
   results, invoice documents, free text, or user email addresses.

## Credential containment

MCP authentication is Passport OAuth, not a separate MCP password or API-key
store. A connector the affected user still controls can disconnect itself
through the authenticated `DELETE /api/v1/connections/{token}` workflow; it
revokes only the calling connection. Otherwise use the emergency revocation
below. Both go through the same revoker (`CredentialRevoker`): one transaction
that locks the access-token rows and revokes their refresh credentials before
the access tokens themselves, and the MCP principal resolver rereads the
persisted access-token row on every request, so a revoked, expired,
wrong-audience, or wrong-client credential is rejected before discovery and
execution.

Do not rotate the OAuth signing keys as a first response: the deployment
workflow deliberately refuses automatic partial key rotation, and a key
rotation is a separate authorization-server incident.

### Emergency revocation of a person's agent connections

For a compromised connector, a lost device, or a person who must be cut off
now, an operator with shell access on the host runs:

```bash
php artisan svc:auth:revoke-connections USER_PUBLIC_UUID
# or only the credentials issued to one OAuth client:
php artisan svc:auth:revoke-connections USER_PUBLIC_UUID --client=OAUTH_CLIENT_ID
```

1. For a suspected client-wide or deployment-wide compromise, first globally
   disable MCP (`AGENT_API_MCP_ENABLED=false`) and stop new issuance and
   refresh (`AGENT_API_OAUTH_SERVER_ENABLED=false`), as under
   [Immediate containment](#immediate-containment). With issuance off, no
   connection can refresh between the revocation and the next request.
2. Name the person by exact public UUID; an internal id, email address or
   partial match is refused (exit 2), and an unknown UUID or client id fails
   (exit 1) without revoking anything. `--client` takes the OAuth client id
   recorded in the audit metadata.
3. The command revokes, in one transaction, every pending authorization code,
   every refresh token (including one whose expired access token Passport has
   already purged) and then every access token the person holds - OAuth and
   MCP connections, operator-issued agent tokens and personal API tokens alike -
   or only those issued to `--client`. Rows are revoked, never deleted, so they
   remain for the investigation. Another person's credentials are never
   selected, even for the same client.
4. It prints one JSON line, for example
   `{"access_tokens":2,"refresh_tokens":1,"authorization_codes":0}`, and logs
   `agent_token.revoked` with `actor: console`, the person's public UUID,
   `selection: connections`, the client id when given, the counts and SHA-256
   fingerprints of the revoked access-token ids. It prints and logs no bearer
   value, token id or email address.
5. Run it again. It is idempotent, and a second run reporting zeros confirms
   nothing was minted in between. Then re-enable issuance and MCP as described
   under [Recovery and rollback](#recovery-and-rollback); the person reconnects
   through a fresh consent.

The finance integration's Sanctum tokens are a separate credential store and
are not touched; revoke those by name with `svc:auth:revoke-token` (see
`docs/finance-api.md`).

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

The v0.11 adoption adds nullable `oauth_clients.scopes` and
`oauth_refresh_tokens.resource_uri` columns through an idempotent, no-op-on-
rollback migration. Roll back application code/configuration using the normal
deployment mechanism; leaving those nullable columns in place is safe. Do not
delete OAuth rows merely to recover the endpoint.

## v0.11 deployment and legacy-client cutover

1. Deploy the code and run the normal migration step before accepting OAuth
   traffic. Confirm `oauth_clients.scopes`, `oauth_auth_codes.resource_uri`,
   `oauth_access_tokens.resource_uri`, and `oauth_refresh_tokens.resource_uri`
   exist. The migration preserves SVC's pre-existing columns.
2. Confirm `AGENT_API_OAUTH_SERVER_ENABLED=true` and clear the configuration
   cache. Verify authorization-server and protected-resource metadata before
   attempting registration.
3. Existing dynamic clients with a null `oauth_clients.scopes` value are
   deliberately unusable for new authorization. Do not backfill a guessed or
   catalog-wide ceiling. Re-register the client and obtain fresh user consent.
4. Treat connections issued before v0.11 as requiring re-authorization.
   Legacy refresh tokens have no independent resource binding, and legacy
   access tokens generally lack the signed issuer/audience/resource claims;
   both fail closed. An access token continues only when its database binding
   and signed claims pass the route-specific expected-resource check.
5. Run `scripts/mcp-smoke-ci.sh`, then the authenticated deployment smoke. The
   smoke credential issuer establishes the resource before Passport signs the
   JWT; never patch `resource_uri` after token issuance.

Passport may purge an expired access-token row while its longer-lived refresh
credential remains valid. Because Passport's refresh table does not retain the
client identifier independently, dynamic-client pruning conservatively defers
all deletions while any such active, unattributed refresh credential exists.
This favors connection continuity over cleanup; pruning resumes after the
credential expires or is revoked.

## Per-endpoint resources and provider identity cutover

1. Run the normal migration step. It adds nullable `provider_subject` and
   `provider_generation` columns to the Passport code, access-token and
   refresh-token tables (plus an indexed `oauth_refresh_tokens.provider_user_id`)
   and to `personal_access_tokens`, all on their own tables' connections.
2. MCP is now its own resource, `APP_URL/api/v1/mcp`. Connectors holding tokens
   bound to `APP_URL/api/v1` are refused by the MCP endpoint and authorize again
   once; tell connector users before deploying. REST tokens are unaffected. The
   deployment smoke issues MCP-bound credentials and expects the MCP endpoint as
   the discovered resource.
3. Provider identity enforcement stays off until
   `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true`. Before enabling it: confirm the
   identity provider's status endpoint answers for this application's client
   credential, keep a lock-capable cache store shared by every web worker (the
   database store qualifies; `BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE` overrides
   the default store), and reissue the finance integration's Sanctum tokens for
   provider-bound users. Sign-ins after this deploy record their login generation
   even while enforcement is off; sessions and credentials from before it are asked
   to sign in or authorize again once enforcement is on.
4. Clear the configuration cache after changing the switch. With it on, an
   unavailable provider answers `503` with `Retry-After` and keeps sessions and
   tokens; turning the switch off again restores the previous behaviour without
   any data change.

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

Proposal writes are nested behind `AGENT_API_PROPOSAL_WRITES_ENABLED=false` and
`AGENT_API_WRITES_ENABLED`. Switching either off removes create/send/accept from
MCP discovery and REST, and from advertised write/accept capabilities. Reads remain
available with `proposals:read`. As with other shared actions, the authenticated
website continues to operate through its established routes. Enable only after
proposal revision migrations and the required validation gates pass.
