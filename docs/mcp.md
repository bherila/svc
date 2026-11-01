# SVC MCP public contract

This document records the MCP surface shipped by SVC and the privacy-safe read
general-availability work tracked by
[#202](https://github.com/bherila/svc/issues/202). The feature tests in
`tests/Feature/Mcp` are the executable compatibility contract.

## Agent API contract baseline

The authenticated `context.get` tool returns required integer
`data.contract_version: 3`, the same field as `GET /api/v1/context`.
`AgentApiContract::VERSION` names the published agent API baseline. Version 2
introduced handover refusals and required handover audit fields. Version 3 adds
buy-down operations, the `deferred_buydown` invoice kind, required
`hours_bought_down` trend fields, and the agreement portal visibility setting.
Consumers must explicitly support the exact integer; missing,
non-integer or unsupported values are not a supported baseline. Check the
runtime context when connecting and before syncing rather than assuming the
`/api/v1` path or OpenAPI `info.version: 1.0.0` establishes compatibility.

This signal is independent of MCP's negotiated protocol version, the application
release and each resource's HMAC `version`. It changes neither the caller's
identity-provider subject nor scope, workspace-role or deployment-cutover checks.
The same authorized caller still receives its own role-filtered context, and
no additional operation or permission is introduced.

## Refused writes

MCP tools that call REST writes preserve the API's user-facing message for
409 conflicts and 422 validation or domain refusals, followed by the first
field validation error when it differs. Read the current record before
retrying a stale version, and correct the named input for a validation refusal.
Permission failures and server failures retain generic messages; internal
exception details are not returned. `invoices.correct` advertises that at
least one of `due_date` or `lines` must be supplied.

## Pinned implementation and transport

- `mcp/sdk` is locked to `v0.7.1` (`785fc3b9b7006ecc8a73322c939d96a4a7154345`).
  Its checked-in `ProtocolVersion` enum supports `2024-11-05`, `2025-03-26`,
  `2025-06-18`, and `2025-11-25`. SVC's current tests negotiate
  `2025-06-18`; no other version is a separately tested SVC compatibility
  promise.
- `bherila/mcp-laravel-bridge` is locked to `v0.2.0`
  (`89736139aa7f1323bbe9dc361e239b175ac1fbe6`).
- `bherila/auth-laravel` is locked to `v0.23.0`
  (`dbf0363090a8efbeffe0f037965565d1838027f5`). Its opt-in Passport
  repositories own authorization-code, access-token, and refresh-token resource
  binding; SVC does not maintain parallel repository implementations.
- `POST /api/v1/mcp` is Streamable HTTP. `DELETE /api/v1/mcp` is available for
  session termination and `OPTIONS /api/v1/mcp` supports configured browser
  origins. There is no stdio transport, SSE endpoint, resource subscription,
  sampling, roots, or elicitation endpoint configured by SVC.
- The shared bridge's hardened Laravel edge policy performs exact browser-Origin
  matching, independent service-Host validation, bounded CORS preflight, and
  SDK-version-aware protocol handling before `AgentMcpController` runs its
  `StreamableHttpResponder`. Maximum request size is
  `AGENT_API_MCP_MAX_BODY_BYTES` (1048576 by default); capability results are
  bounded after serialization by `AGENT_API_MCP_MAX_RESULT_BYTES` (2097152
  by default), while complete non-streamed transport responses are independently capped by
  `AGENT_API_MCP_MAX_RESPONSE_BODY_BYTES` (4194304 by default). Responses are
  `private, no-store`. When the global
  `AGENT_API_MCP_ENABLED` kill switch is false, authenticated POST and DELETE
  requests receive a stable no-store `503` with `Retry-After`; the application
  does not construct a server or emit a misleading empty capability document.
- Sessions use the Laravel cache via `Psr16SessionStore`, expire after
  `AGENT_API_MCP_SESSION_TTL_SECONDS` (1800 seconds by default), and are
  namespaced by a SHA-256 digest of the bearer credential. A session cannot be
  found from a request using a different bearer credential.

The initialize response advertises the protocol groups implemented and enabled
by the server. Discovery within those groups is filtered for the authenticated
principal, and every invocation is authorized again. SVC does not
advertise list-change notifications, resource subscriptions, server logging,
completions, sampling, roots, or elicitation; direct resource subscription
and other unsupported optional-feature requests are rejected as unsupported.

Production deploys the same routes from `main` to web1. The deployment keeps
OAuth signing keys server-side in `storage/app/private/oauth`; it does not
seed tenant data.

## Authentication, tenant selection, and authorization

MCP uses the `api` Passport guard and requires the `mcp:use` token scope at
the route. It is not authenticated by the browser session or a query-string
credential: the shared MCP edge guard rejects query parameters shaped like
bearer or API-key credentials before route authentication with a no-store 400
response. The current provider resolves `AgentPrincipal`,
an OAuth-only view of the local `users` table; its memberships and project
roles remain SVC data. OAuth Authorization Code with S256 PKCE is the
documented client flow. `McpPrincipalResolverInterface` is currently bound to
the local Passport-backed `McpPrincipalResolver`, retaining a narrow adapter
seam for a future shared resource server without introducing a separate MCP
credential system.

SVC enables the shared authorization server explicitly with
`AGENT_API_OAUTH_SERVER_ENABLED=true`. `EnsureOAuthServerEnabled` precedes PKCE
and resource validation on Passport authorization and token routes. Turning it
off returns non-cacheable 404 responses from metadata, registration,
authorization, and token/refresh routes while the shared access-token repository
continues enforcing already-issued credentials. SVC protects two OAuth resources,
each its own audience with its own RFC 9728 document and scope ceiling: REST at
`APP_URL/api/v1` (every operation scope, never `mcp:use`) and MCP at
`APP_URL/api/v1/mcp` (`mcp:use` plus every operation scope). Every protected route
names its resource (`ExpectOAuthResource:rest` or `:mcp`) before Passport
authentication, and its 401 challenge names that resource's metadata; a bound token
therefore fails closed on an unmarked route or one marked for the other resource, so a
REST token cannot open an MCP connection and an MCP token cannot call REST. A token
request naming no resource is bound to REST. `DELETE /api/v1/connections/{token}`
requires `mcp:use` and so belongs to the MCP resource. It disconnects only the calling
connection: `token` must be the id of the access token making the request, and any other
id, including another of the same person's connections, is a 404 that revokes nothing. Dynamic registrations persist their exact scope ceiling. A legacy
dynamic client whose ceiling is null must re-register and re-authorize; null is
never interpreted as unrestricted access.

The current route also has Laravel's `throttle:60,1` limiter. Browser requests
with an `Origin` must exactly match `AGENT_API_MCP_ALLOWED_ORIGINS`; native
clients without an Origin are permitted. Browser origins never expand the service
Host allowlist, which defaults to `APP_URL` and the configured OAuth resource and
may be replaced with exact `host[:port]` authorities through
`AGENT_API_MCP_ALLOWED_HOSTS` for a reviewed reverse-proxy deployment. CORS exposes
the MCP session, protocol, and OAuth challenge headers.

Tools presently take `workspace_id` arguments. It is only a selector: an
immutable `McpRequestContext` resolves it through the authenticated
principal's active workspace or portal memberships before materializing a
workspace. `AgentAccess`, `ProjectAccess`, `PortalAccess`,
`AgentTimeEntryQuery`, and `WorkspacePolicy`, `ClientCompanyPolicy`, and
`ClientProjectPolicy` supply the applicable local membership, role, portal,
and object checks. OAuth scopes are a ceiling, not a replacement for those
checks.

The v1 compatibility contract deliberately permits a credential-bound
resumable session to select any of its authorized workspaces on each
workspace-scoped call. The selector never establishes identity or a durable
authority grant, and active membership, scope, policy, and ownership are
re-scoped and reauthorized before lookup and execution. Pinning the first
selector would break existing multi-workspace consumers. Any future
session-selected workspace contract must use an explicit versioned
selection/rotation protocol and migration window rather than silently
changing v1.

Current token scopes are `identity:read`, `projects:read`, `projects:write`, `tasks:read`,
`tasks:write`, `time:read`, `time:write`, `time:approve`, `billing:read`,
`billing:write`, `billing:deliver`, and `mcp:use`. Discovery filters tools by
their declared scopes and omits manager-only capabilities when the principal
has no workspace where the existing `AgentAccess::isWorkspaceManager` policy
can succeed. The MCP
principal resolver rereads the persisted Passport token on each request and
rejects expired, revoked, wrong-subject, wrong-client, or wrong-audience
credentials before discovery or execution. Read execution repeats scope
checks before tenant and object lookup. `AGENT_API_WRITES_ENABLED` defaults to
false; the independent `AGENT_API_TIME_ENTRY_WRITES_ENABLED` defaults to
true and is an emergency cutoff for the three time-entry write tools.
`AGENT_API_INVOICE_WRITES_ENABLED` also defaults to false and is nested inside
the workflow cutover rather than independent of it: the eight invoice write tools
require both, as do their REST routes, the `billing:write` and
`billing:deliver` capabilities, and the `prepare-invoice-safely` prompt, which
names two of those tools in its required capabilities. Approving time and
creating a task are recoverable; `invoices.issue` allocates approved time
irreversibly and `invoices.send` puts a document in front of a paying client,
so the two groups no longer turn on together.

### Scope groups, presets and Admin (#582)

The credential picker (personal API tokens and OAuth apps) and the OAuth consent
screen show the 28 agent scopes in groups rather than one flat list. The catalog is
`App\Support\AgentApi\AgentApiScopePresentation`, bound as the auth package's
`ScopePresentation`; `AgentApiScopePresentationTest` pins every id, order and scope.
It is presentation only: grants, tokens and OAuth clients still store explicit scope
ids, and no endpoint accepts a group or preset id.

| Group | Scopes |
| --- | --- |
| Account | `identity:read`, `mcp:use` (MCP connections only; REST credentials are never offered it) |
| Projects & tasks | `projects:read`, `projects:write`, `tasks:read`, `tasks:write` |
| Collaboration & files | `collaboration:read`, `collaboration:write`, `files:read` |
| Time | `time:read`, `time:write` |
| Clients & proposals | `clients:read`, `proposals:read` |
| Billing & payments | `billing:read`, `billing:write`, `payments:read` |
| Expenses & finance | `expenses:read`, `expenses:write`, `finance:read` |
| Admin (sensitive) | `billing:deliver`, `payments:record`, `finance:reconcile`, `proposals:write`, `proposals:accept`, `time:approve`, `clients:write`, `files:write`, `workspaces:create` |

A group shows only the scopes the surface offers, and is omitted when it has none.
**Admin** collects every sensitive scope: sending invoices and proposals to clients,
recording or reconciling money, accepting agreements, approving time and rates,
changing clients, sharing files and creating workspaces. "Admin" is not an OAuth
scope. Ticking it in the picker stores the explicit scopes, so a sensitive scope
added later never reaches an existing grant without a new consent. On the consent
screen an Admin scope appears in its own section with the group description and a
warning that these permissions allow sensitive actions.

Presets tick a routine set of scopes in one click, replacing the selection; the
boxes stay editable. A preset is offered only where all its scopes are offered.

| Preset | Scopes |
| --- | --- |
| Read-only | `identity:read` plus every `:read` scope, derived from the catalog so a new read scope joins it; listed in the order `identity:read`, `projects`, `tasks`, `collaboration`, `files`, `time`, `clients`, `proposals`, `billing`, `payments`, `expenses`, `finance`, then any newer read scope in catalog order |
| Time logging | `identity:read`, `projects:read`, `tasks:read`, `time:read`, `time:write` |
| Project work | Time logging plus `tasks:write`, `collaboration:read`, `collaboration:write`, `files:read` |
| Invoice drafting | `identity:read`, `projects:read`, `clients:read`, `time:read`, `billing:read`, `billing:write`, `payments:read` |

**Presets never include a sensitive scope.** The package validator and a unit test
both refuse a preset containing an Admin scope; an agent that needs one is given it
by ticking Admin, or the individual scope, deliberately.

**Implications.** `AgentApiScopePresentation` also declares which scopes the picker
ticks together. Ticking a scope ticks everything it needs, and unticking a scope
unticks whatever needs it; presets and the Admin checkbox cascade the same way. Every
preset is closed under the map (the package validator and a unit test check it).

| Scope | Needs |
| --- | --- |
| `projects:write`, `tasks:write`, `collaboration:write`, `time:write`, `billing:write`, `expenses:write` | its own `:read` (`projects:read`, `tasks:read`, ...) |
| `billing:deliver` | `billing:read` |
| `payments:record` | `payments:read` |
| `finance:reconcile` | `finance:read` |
| `proposals:write`, `proposals:accept` | `proposals:read` |
| `time:approve` | `time:read` |
| `clients:write` | `clients:read` |
| `files:write` | `files:read` |

The credentials index carries the map as `scope_implications` (and
`token_scope_implications`), limited to scopes that surface offers. It is a picker
convenience only: the store endpoints still take explicit ids, add nothing and reject
no set that lacks a requirement, and authorization never infers one scope from
another, so no existing grant widens.

The setup page reads the whole picker payload (scopes, groups, presets, implications,
lists, action URLs) from the package's JSON index, `GET /account/api-credentials`,
through bwh-auth's `ApiCredentialsSection`; the page props carry only the connector
URLs and that index URL.

### Operator-issued agent tokens

An operator with shell access on the host can issue a short-lived agent token
for one exact person without a browser consent flow:

```bash
php artisan svc:auth:issue-agent-token USER_PUBLIC_UUID "Support session" --apply \
  mcp:use identity:read projects:read --ttl=60
```

Each scope must be one of the agent scopes listed above; an unknown scope,
a non-UUID or unknown user, a blank or over-long name or one with control characters, or a
`--ttl` outside 1-240 minutes (default 60) is refused and nothing is issued. Nothing is issued
while `AGENT_API_OAUTH_SERVER_ENABLED` is off, as for any other new credential. A
token is bound to one resource, chosen by its scopes: naming `mcp:use` makes an
MCP token for `APP_URL/api/v1/mcp`, and omitting it makes a REST token for
`APP_URL/api/v1`. The token is an ordinary personal access token on the same
issuance path as the deployment smoke's, so the resource checks, the identity
provider policy and the person's own workspace, company and project roles apply
to it exactly as to an OAuth-connected agent's; its scopes are a ceiling, never a
grant. For a person bound to the identity provider it records the provider
subject and credential generation, and issuing is refused when the provider
reports the identity inactive (or, with enforcement on, cannot be asked).

The command prints one JSON object on stdout - `token`, `expires_at`, `name`,
`scopes` and `resource` - and the token appears nowhere else. It logs an
`agent_token.issued` entry carrying the actor, the person's public UUID, the name, scopes,
resource, expiry and a SHA-256 fingerprint of the credential id, never the token.

Every agent token SVC mints itself - this command, the post-deploy smoke
(`svc:mcp:deploy-smoke-credentials`) and the pre-merge smoke
(`scripts/mcp-smoke-credentials.php`) - goes through one issuer,
`AgentTokenIssuer`, so all of them share these rules: the OAuth server switch,
the provider identity check, the lifetime actually signed, the resource binding,
the one personal access client Passport issues agent-principal tokens from
(created only when none exists), and the `agent_token.issued` audit entry, whose
`actor` is `console`, `deploy-smoke` or `ci-smoke`. Every revocation - by name,
by a connection disconnecting itself, the smoke's cleanup, and the emergency
`svc:auth:revoke-connections` - goes through one revoker, `CredentialRevoker`:
one transaction on Passport's connection that locks the access-token rows,
revokes their refresh tokens before the access tokens themselves (through
Passport's repository, so revocation listeners hear of each), never deletes a row,
and logs one `agent_token.revoked` entry after it commits. Owner/client and
connection revocations use the auth package's durable `CredentialLineages`
guard in that same transaction. A code exchange or refresh that already passed
validation cannot persist a child of a revoked lineage, however long it stalls.
Self-disconnect follows that connection's tracked rotations and preserves
same-client sibling connections. Fresh authorization after a broad revocation
is allowed immediately. The canonical issuer captures its decision before its
remote identity check, so it cannot adopt a revocation that happened meanwhile.
No cache TTL, cache flush or Passport purge lifts the guard.

Revoke by name, which takes effect on the next request:

```bash
php artisan svc:auth:revoke-agent-token USER_PUBLIC_UUID "Support session" --apply
```

The name is stored under a reserved prefix, so revocation by name only ever
selects tokens this command issued, never a person's own personal token or an
OAuth-connected agent's. Expired tokens are ignored; when more than one live
token shares the name the command fails closed unless `--all` is given. It logs `agent_token.revoked`
with the actor, the person's public UUID, the name, the count and the credential fingerprints.
To cut off every agent credential a person holds, see
[emergency revocation](mcp-operations.md#emergency-revocation-of-a-persons-agent-connections).

### First-party applications

Another of the operator's own applications that acts in SVC for a person - a
finance system reconciling payments, for example - connects through the same
authorization server as any agent, as a pre-registered confidential client:

```bash
php artisan svc:auth:register-first-party-client "Finance" --apply \
  --redirect-uri=https://finance.example.test/oauth/svc/callback \
  --scope=identity:read --scope=finance:read --scope=finance:reconcile
```

The client is ownerless, confidential and registered for the authorization
code grant with refresh only; its scopes (REST operation scopes, never
`mcp:use`) are its ceiling, and its redirect URIs must be https or loopback
http. A second live client with the same name is refused. The command prints
`client_id` and `client_secret` once as JSON (only the secret's hash is kept)
and logs `oauth_client.registered` with the client id.

Registering does not skip consent. A person signed in through the identity
provider connects such a client with no consent screen only once its id is in
`AGENT_API_FIRST_PARTY_OAUTH_CLIENTS` (comma-separated), and only while it is
still what the command made: a client a person registered (it has an owner),
a dynamically registered one, a revoked one, or one allowed any other grant
asks for consent even when listed (`App\Support\Auth\OAuthClient`). PKCE, the
resource binding and the scope ceiling apply either way.

Two protections hold for every client. Every authorization response - a code
or an error, from the consent screen or a skipped one - carries RFC 9207's
`iss`, and the metadata advertises `authorization_response_iss_parameter_supported`,
so a client of several authorization servers can check which one answered
before it exchanges a code. And `grant_type=client_credentials` is refused at
`/oauth/token` with `unsupported_grant_type`, whatever the client: every
credential acts for a person.

A REST connection disconnects itself with `DELETE /api/v1/connection`
(`connections.disconnect`, any REST credential): every credential the person
holds for the calling OAuth client - pending authorization codes, refresh
tokens, then access tokens - is revoked through `CredentialRevoker` and the durable guard,
so a first-party application turned off by the person cannot act or refresh
again. Other applications' connections, and other people's connections to the
same application, are untouched; a personal API token, which shares one client
with the person's other personal tokens, ends only itself. An MCP connection
uses `connections.revoke` instead.

A client that needs to discard just its current credential uses
`DELETE /api/v1/oauth/token` (`oauth.token.revoke`), sending that credential as
its bearer with no request body. It returns 204 and revokes only that access
token's durable family, including tracked rotations and refresh tokens. Other
connections of the same person and client, other people's credentials and
unexchanged authorization grants remain active. A replay returns 204 or 401.
Both REST and MCP resource credentials are admitted, including personal OAuth
tokens, without additional scopes; normal signature, issuer, expiry, owner,
resource binding and scope-ceiling checks still apply. A browser session or
Sanctum token cannot substitute for the OAuth bearer. SVC's existing policy
refuses client-credentials issuance, so all supported credentials act for a
person. The operation is explicitly REST-only: credential disposal is a
connecting client's lifecycle responsibility, not an MCP agent tool.

## Public capability inventory

With all read scopes, discovery exposes these read-only tools, in this order:

`context.get`, `operations.summary`, `projects.list`, `projects.get`,
`tasks.list`, `tasks.get`, `time_entries.list`, `expenses.list`, `invoices.list`, and
`invoices.get`, `agreements.list`, `agreements.get`,
`clients.list`, `clients.get`,
`billing_schedules.list`, `billing_schedules.get`, `capacity_ledger.get`,
`billing.audit_unplaceable_invoices`,
`billing.audit_undated_collectible_invoices`, and
`billing.audit_missing_billed_overage`,
`billing.audit_opening_rollover`, `billing.preflight_schedule_generation`, and
`billing.audit_unlinked_proposal_agreements`. While Square is configured, managers also
see the read-only `square.*` pass-through tools described under
[Square read-only pass-through](#square-read-only-pass-through-438). Managers holding
`finance:read` also see `finance.invoice_payments.list`,
`finance.payouts.list` and `finance.processor_movements.list`, described under
[Finance reconciliation](#finance-reconciliation-457).

Agreement tools require `billing:read` and an SVC workspace-manager role. Each
agreement names the client it is with (`client_id`, `client_name`) and, when it
is scoped to one, its project (`project_id`); an agreement with no project
covers the whole client. Client tools require `clients:read` and the same role.
They return only the existing directory's allowlisted, derived agreement DTO.
The DTO preserves the stored cadence for compatibility and separately reports
the effective cadence, effective first-cycle proration, and monthly and
per-period retainer terms read by the billing engine;
project-scoped users and client portal users receive the same non-existence
response as for an inaccessible agreement.

`time_entries.log`, `time_entries.update`, and `time_entries.delete` appear
only while the time-entry write flag is enabled and the token has the needed
scope. Like every MCP write tool, they and
`tasks.create` / `tasks.update` run their REST operation (see
[Write tools run their REST operation](#write-tools-run-their-rest-operation)).
For `time_entries.log`, supplying `client_visible_description`
without `is_visible_to_client` makes the entry client-visible by default; set
`is_visible_to_client: false` to stage the client text privately. The broader
write flag also retains a legacy compatibility
registration for `time_entries.approve`. The nine invoice registrations -
`invoices.create_draft`, `invoices.update_draft`, `invoices.update_details`,
`invoices.discard_draft`, `invoices.issue`, `invoices.send`, `invoices.correct`,
`invoices.reopen` and `invoices.void` - require *both* that
flag and `AGENT_API_INVOICE_WRITES_ENABLED`, so enabling the broader flag alone
registers `time_entries.approve` and no invoice tool. All nine enter the
versioned API through its REST routes, whose controllers are thin adapters:
`invoices.issue` runs `IssueInvoiceAction` (see
[Issuing an invoice](#issuing-an-invoice)), and `invoices.create_draft`,
`invoices.update_draft` and `invoices.send` are validated, authorized and
carried out by `AgentInvoiceOperations`, one implementation for both
transports.

`invoices.create_draft` takes the invoice screen form's fields (#454):
`invoice_number` (default: the workspace's next number; a number already used
in the workspace is refused as a field error), `issue_date` (default: the day it
is issued), `service_period_start` and `service_period_end` (either alone, as
on the screen; the end on or after the start when both are given) and `is_visible_to_client` (default `false`;
issuing always makes the invoice visible). `invoices.send` takes the compose
dialog's: an optional `subject` (default: the invoice's own), an optional
covering `message`, and `bcc_self` to blind-copy the signed-in user's own address.
No other blind copy can be named, on the screen or through the API, and the
screen and the API compose the message through the same
`InvoiceEmailDraft::composed()`. Fields a tool does not take are refused by name
rather than ignored. They are disabled by
default and are not a PR 6/7 production-ready write path: approval, invoice,
and externally consequential workflows require their own application-action
migration plus the approved confirmation design before general availability.

`invoices.send` adds `data.delivery` beside the existing invoice result. It is
the exact delivery registered by that idempotency receipt: `{id, status,
error_summary}`, or `null` when an older receipt did not retain its delivery
identity. `sent` means the mailer accepted the message, not that the recipient
received it. `pending` and `sending` are unresolved; `sending` also covers a
lost transport response or accepted mail whose local result could not be saved.
Only a preparation failure before handoff is `failed`, with a sanitized error.
The send still runs explicitly after commit in the request and needs no worker.
Repeat an uncertain request with the same key and unchanged body to read its
recorded outcome without another handoff. Do not automatically resend a `null`,
`pending` or `sending` result. A proven `failed` result allows an operator to
make a new confirmed attempt with a new key and current invoice version.

`time_entries.log` accepts `approve: true` to approve the logged entries in
the same transaction and under the same idempotency receipt, so a batch is
either logged and approved or not written at all. It carries every gate
`time_entries.approve` does - `AGENT_API_WRITES_ENABLED`, the `time:approve`
scope, and the approver role on each project - and rechecks the scope, flag
and role before replaying a receipt. The flag is part of the request digest,
so the same key with and without it is a conflict. When the token also holds
`time:read` and `time_entries.list` is not switched off, the tool returns its
rows exactly as `time_entries.list` presents them, so an agent needs no
follow-up list call: it asks the REST route for `?view=read`, which answers
through `AgentReadService` for a caller with `time:read` and with the write
shape otherwise. That includes the list's rule that rates are shown to
workspace owners and admins only, so a project-role manager who approves sees
the status but not the rate. The view is not part of the request digest.

### Write tools run their REST operation

An MCP write tool is an adapter over its REST operation (#459). The handler
names the registry operation and its path parameters to `AgentMcpRestCall`,
which takes the method and path from that operation's REST binding and sends
the request through `InternalAgentApiTransport`, addressed to the caller's own
scheme and host so returned links match REST's. The route then does all of the
work - workspace visibility, scopes, cutovers, validation, version checks,
receipts and audit - and only refusals written for the caller cross back
(`AgentMcpApiFailure`): a 409 or 422 comes back as a tool error carrying the
API's message, a 403 as a permission refusal, anything else as a generic
failure, never as the SDK's internal error. The tool keeps only what is MCP's
own: its input schema and annotations, the kill switch of a capability folded
into it (`approve` on `time_entries.log` answers to `time_entries.approve`,
`payment` on `invoices.issue` to `payments.record`) and, for the two writes
that can answer in a read's shape, whether to ask for `?view=read`.
`AgentMcpWriteToolRestDispatchTest` calls every write tool's handler against a
recording transport and fails if one sends anything but exactly one request to
its own operation's route. It has no exceptions: the payment tools
(`payments.record`, `payments.correct`, `payments.set_status`,
`payments.refund`, `payments.reverse_refund`) send the same body to their route
as any REST client, so a receipt written over either transport replays over the
other.

Every agent operation is declared once in `AgentOperationCatalog`, on the shared
operation registry (`bherila/mcp-laravel-bridge`, #408); `AgentMcpToolSchemas`
derives tool schemas from the checked-in OpenAPI document (spec-first
`SchemaRef`), except for the few tools that declare theirs inline. Inputs are validated before dispatch, output is
validated after dispatch, and failures are mapped to safe MCP errors. Read
tools and the REST controller both use `AgentReadService`, the single
tenant-scoped query/presentation boundary. Read tools do not invoke
controllers or internal HTTP routes; write tools always do, and no MCP
capability exposes Eloquent models.

`svc://context` is a bounded JSON resource equivalent to `context.get`; it is
advertised and readable only with `identity:read`. The `agreement` resource
template is `svc://workspaces/{workspace_id}/agreements/{agreement_id}`. It
uses the same workspace-scoped `AgentAgreementReadService` and allowlisted DTO
as `agreements.get`, requires `billing:read` and a workspace-manager role, and
does not expose list/search or arbitrary paths. Prompts are conditional:
`log-time-across-projects` is advertised only when its required
context/project/time tools are discoverable; `prepare-invoice-safely` appears
only with the full authorized invoice-draft workflow. Prompts provide guidance
only and do not bypass tool authorization.

Cursor pagination is available on project, task, time-entry, and invoice
lists, with a maximum page size of 100. The server's discovery pagination
limit is also 100. Current schemas and output field allowlists are in
`public/openapi/svc-agent-v1.json` and are protected by
`AgentMcpContractTest`.

### #187 read capability matrix

This matrix covers the additive read surface proposed by #187. Every entry
uses a workspace-scoped application read service; the MCP handler only
resolves authenticated context, validates its DTO, and maps the result.

| Capability                                        | Operator/UI workflow and backing service                                                                                  | Scope and policy                                                                    | Bounds and privacy contract                                                                                                                                                     | Flag and coverage                                                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `agreements.list`, `agreements.get`               | Client-directory agreement view; `AgentAgreementReadService` and the shared `AgreementReadPresenter`                      | `billing:read`; `AgentAccess::isWorkspaceManager`; workspace-scoped agreement query | Status filter, 1–100 page, query-bound cursor; stored cadence plus effective cadence/proration and monthly/per-period retainer terms; inaccessible objects are not found        | `mcp.read.agreements`; MCP contract, parity, and tenant-isolation tests                                               |
| `agreement` resource template                     | Canonical agreement representation; the same `AgentAgreementReadService` and `AgreementReadPresenter` as `agreements.get` | `billing:read`; `AgentAccess::isWorkspaceManager`; workspace-scoped agreement query | Fixed `svc://workspaces/{workspace_id}/agreements/{agreement_id}` URI shape; UUID variables are bounded before lookup; allowlisted DTO only; inaccessible objects are not found | `mcp.read.agreements`; discovery, direct-read, hidden-template, and tenant-isolation tests                            |
| `billing_schedules.list`, `billing_schedules.get` | Billing schedule view; `AgentBillingScheduleReadService` and `BillingScheduleReadPresenter`                               | `billing:read`; manager; workspace-scoped schedule query                            | Active filter, 1–100 page, query-bound cursor; only agreement ID, cadence, next-run date, and active state                                                                      | `mcp.read.billing_schedules`; MCP contract, parity, and tenant-isolation tests                                        |
| `capacity_ledger.get`                             | Time-sheet capacity display and billing ledger; `AgentCapacityLedgerReadService` over `InvoiceLedgerBuilder`, and `DeferredBacklogReadService` for its `deferred_backlog`; also `GET /api/v1/workspaces/{workspace}/agreements/{agreement}/capacity-ledger` | `billing:read`; manager; workspace-scoped agreement query                           | 1–60 trailing months; allowlisted rows include `signed_available_hours` (current and carried unused capacity positive, deficit negative); `deferred_backlog` states the termination collection set, its amount at the hourly rate as termination prices it, and its trend over the same months ([deferred billing](client-management/deferred-billing.md#deferred-backlog)); inaccessible agreement is not found   | `mcp.read.capacity_ledger`; ledger, schema, REST/MCP parity, termination parity and cross-workspace tests                                                 |
| `billing.audit_unplaceable_invoices`              | `svc:billing:audit-unplaceable-invoices`; `AgentBillingAuditReadService` over `UnplaceableInvoiceAuditor`                 | `billing:read`; manager; workspace-scoped audit                                     | No record identifiers or raw amounts beyond aggregate, per-workspace totals; no pagination                                                                                      | `mcp.read.billing.audit_unplaceable_invoices`; aggregate/redaction and authorization tests                            |
| `billing.audit_undated_collectible_invoices`      | `svc:billing:audit-undated-collectible-invoices`; `AgentBillingAuditReadService` over `UndatedCollectibleInvoiceAuditor`  | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts and bounded per-currency integer balances only; no record identifiers                                                                                          | `mcp.read.billing.audit_undated_collectible_invoices`; aggregate/redaction and authorization tests                    |
| `billing.audit_missing_billed_overage`            | `svc:billing:audit-missing-billed-overage`; `AgentBillingAuditReadService` over `MissingBilledOverageAuditor`             | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts only; no record identifiers or raw models                                                                                                                      | `mcp.read.billing.audit_missing_billed_overage`; aggregate/redaction and authorization tests                          |
| `billing.audit_opening_rollover` (deprecated, #500) | `svc:billing:audit-opening-rollover`; `AgentBillingAuditReadService` over the shared `OpeningRolloverAuditor`             | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts and capacity minutes only; immutable DTO cannot carry record identifiers                                                                                       | `mcp.read.billing.audit_opening_rollover`; CLI parity, aggregate/redaction, authorization, and tenant-isolation tests |
| `billing.preflight_schedule_generation`           | `svc:billing:preflight-schedule-generation`; `AgentBillingAuditReadService` over `ScheduleGenerationPreflight`            | `billing:read`; manager; the caller's workspace only                                | Counts per refusal reason and schedule defect, through the workspace's today, at most 60 periods per schedule and 5 calls per ten minutes; `complete` false when a backlog was not fully examined | `mcp.read.billing.preflight_schedule_generation`; parity and tenant-isolation tests                                   |
| `billing.audit_unlinked_proposal_agreements`      | `svc:engagement:audit-unlinked-proposal-agreements`; `AgentBillingAuditReadService` over `UnlinkedProposalAgreementAuditor` | `billing:read`; manager; the caller's workspace only                              | Counts only; nothing is repaired or guessed                                                                                                                                     | `mcp.read.billing.audit_unlinked_proposal_agreements`; parity and tenant-isolation tests                              |
| Duplicate-time diagnostics                        | No checked-in workflow/service establishes the grouping key                                                               | Deferred from read GA                                                               | Not implemented: a canonical application query, grouping contract, bounded DTO, and privacy review must exist before MCP can adapt it                                           | No flag or release cohort                                                                                             |
| Session workspace selection                       | Existing clients select an authorized workspace on every scoped call                                                      | Accepted v1 compatibility contract                                                  | Credential/client sessions are bound; every selector is active-membership scoped and reauthorized per call. Session pinning requires a future versioned migration.              | Context, membership-removal, credential-binding, and multi-workspace compatibility tests                              |

## Compatibility and current coverage

The shared [`/api/v1` compatibility policy](finance-api.md#compatibility) states
which additions are compatible, how consumers handle unknown enum values, and
when a breaking change requires a new version or a deprecation period.

Existing clients depend on the public tool and prompt names, dotted naming,
tool ordering, schema shapes, safe error messages, OAuth/PKCE discovery, and
credential-bound sessions. Additive capabilities must not rename, remove, or
weaken these contracts. A future replacement must retain an alias and an
explicit deprecation window.

### Deprecations

An operation is deprecated before it is removed, never removed outright. Its
`AgentRestDocumentation` entry carries a `deprecation` reason that names the
removal issue, and that one entry marks every door:

- the OpenAPI operation gets `deprecated: true` and a `Deprecated:` paragraph in
  its description;
- the MCP tool description ends with the same `Deprecated:` paragraph, and the
  tool keeps its name, schema and behaviour;
- the console twin says `Deprecated` in its help and prints the notice in text
  output (JSON output keeps its shape).

The operation is removed only by its removal issue, after the deprecation window.

| Deprecated                                        | Since | Removal | Replacement |
| ------------------------------------------------- | ----- | ------- | ----------- |
| `billing.audit_opening_rollover` (`GET /workspaces/{workspace}/billing-audits/opening-rollover`, `svc:billing:audit-opening-rollover`) | #460 | #500 | None: the #134 repair it sized is done |

Every feature-test HTTP exchange under `/api/v1` now checks the published
OpenAPI contract through `Tests\TestCase`: accepted requests validate their
parameters and bodies, and all responses, including refusals, validate their
status, media type and JSON shape. Deliberately invalid negative-test inputs may
be refused; they cannot be accepted outside the schema. The MCP transport
endpoint retains its separate JSON-RPC envelope and tool-schema contract tests.
Historical receipt replays and ignored-input security regressions explicitly pin
a closed request schema for that one named operation at the test call; their
responses still use the current published schema.
Tests disable debug responses so refusal shapes match the deployed API rather
than Laravel's development exception traces. Protected-resource discovery links
the public contract with `service_documentation`.

Current coverage includes initialization, scope-filtered discovery, prompts,
tool schema closure, input and output validation, REST parity, credential
session isolation, route authentication, origin handling, optimistic versions,
idempotency, tenant isolation, persisted credential validation, request
context selection, and safe time-entry mutations. The operation
registry drives tool discovery and registration and the REST route gate:
required scopes, the workspace-manager policy, schemas, rate-limit/audit
classification, write cutovers and the global/per-capability MCP kill switches,
evaluated by `Availability` (`AgentAvailability`). REST routes are bound with
`Route::operation()`; `EnsureOperationDeployed` answers 404 for a switched-off
cutover before the gate, which answers 401/403 with the withheld reason. Cursor envelopes are
encrypted and bound to the workspace and canonical filter query. A temporary
legacy base64 cursor reader remains for existing REST/MCP clients and is
controlled by `AGENT_API_ACCEPT_LEGACY_CURSORS`; newly emitted cursors never
use it. `scripts/mcp-smoke.mjs` uses the pinned official MCP JavaScript client
against a supplied short-lived bearer token. The concurrent CI smoke job starts
Laravel with ephemeral OAuth keys and generated `.test`-only data, then runs
the client through handshake, discovery, and `context.get`. Tool, resource,
resource-template, and prompt execution are centrally throttled by reviewed `mcp-read`
(120/minute) and `mcp-write` (20/minute) buckets, keyed to the authenticated
credential and capability; the route's `throttle:60,1` remains the broad outer
limit. A capability call
fails closed with a safe retry-later error if its limiter backend is
unavailable. Capability execution is also limited to four concurrent requests
per authenticated credential and capability, using 60-second cache-backed
leases; a saturated or unavailable lock store returns a safe retry-later error.
Every tool call, resource read (including a resource-template read), and prompt
retrieval also
emits the metadata-only `mcp.capability.executed` audit event and a matching
payload-free `McpCapabilityInvoked` application event for metrics integrations
(including hidden or unknown direct tool attempts): request ID, capability,
bucket, audit classification, outcome, duration, subject public ID, and one-way
credential/client fingerprints. Arguments, results, headers, and raw tokens
are excluded by contract. Audit and metrics sink failures never alter the MCP
response or expose their implementation details. The source-controlled
deployment-neutral dashboard, dimensions, and initial alert thresholds are in
[the MCP operational runbook](mcp-operations.md#monitoring-and-alerts); alert
delivery is configured by the deployment's logging/metrics platform.
Incident containment, OAuth-connection revocation, recovery, and rollback are
documented in [the MCP operational runbook](mcp-operations.md).

## #187 disposition and related work

Issue #187 had no comments or explicit acceptance decision when this baseline
was written. The product owner subsequently approved every read-only proposal
subject to the existing privacy boundary; the consequential maintenance
workflow is outside the read-GA authorization:

| Proposal                                       | Current disposition                                                                                                                                      |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Agreement list/get with derived terms          | Implemented; manager-only `agreements.list` / `agreements.get`                                                                                           |
| Signed monthly capacity ledger                 | Implemented; manager-only bounded `capacity_ledger.get` over `InvoiceLedgerBuilder`, with the agreement's deferred backlog (#366), on REST and MCP          |
| Duplicate-time diagnostics                     | Deferred: no checked-in canonical query or privacy-reviewed grouping contract exists. It will not be inferred from raw time rows.                        |
| Read-only `svc:billing:audit-*` operations     | Implemented as six aggregate-only, manager-scoped tools, including `billing.audit_opening_rollover` and `billing.preflight_schedule_generation`, over the same auditors used by their CLI workflows. Each is a `GET /workspaces/{workspace}/billing-audits/...` REST operation in the OpenAPI document, and its MCP tool reaches it through that route (#459), so the scope, the manager check, the preflight's 60-period cap and its 5-per-ten-minutes workspace budget apply to both. `svc:billing:audit-overpayment-credit` takes `--workspace=` and is not yet exposed |
| Billing-schedule visibility                    | Implemented; manager-only `billing_schedules.list` / `billing_schedules.get`                                                                             |
| Imported-duplicate maintenance preview/execute | Obsolete for #187 after #196 retired the importer; no maintenance write is authorized                                                                    |
| One-workspace-per-session binding              | Per-call authorized selection is the accepted v1 contract; any pinned-session replacement requires a future versioned migration                          |

The former PR 1–8 plan is superseded by the read-GA tracker in #202. New or
widened writes and consequential workflows remain separate product work and
require explicit authorization.

[#172](https://github.com/bherila/svc/pull/172) is the merged appearance
selector work and has no MCP contract dependency. [#175](https://github.com/bherila/svc/pull/175) tightened null-tolerant billing-cycle attribution and
refusal behavior. Any future ledger or audit capability must preserve those
application-service semantics rather than reconstruct them in MCP.

### Expense recording

`expenses.list` requires `expenses:read` and follows the browser expense list's
project visibility. Workspace owners/admins can read company-level expenses;
other workspace members can read expenses attributed to their assigned projects.
The cursor is bounded to 100 rows (default 25) and tied to workspace and filters.

`expenses.log`, `expenses.update` and `expenses.delete` require `expenses:write`
and workspace owner/admin permission. They are available only when both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_EXPENSE_WRITES_ENABLED` are true; the
expense flag defaults to false. The same cutover applies to MCP discovery/calls,
REST writes and advertised capabilities. Nothing enables this flag at deployment.

Recording accepts an atomic batch of at most 20 draft expenses, dates in exact
`YYYY-MM-DD` form, uppercase currency codes and positive integer minor units.
All writes use the existing actor/workspace/OAuth-client/operation-scoped
idempotency contract. Retrying a key with a different request is a conflict.
Updates replace the facts using `expected_version`; omitting `project_id` leaves
attribution intact, while explicit null clears it. Browser edits and approval
transitions advance the same revision. Agent updates and deletes refuse approved,
invoiced and unknown statuses. The browser's existing ability to discard an
approved expense is unchanged. A delete retry checks current manager permission
without needing to reload its deleted row.

The equivalent OAuth REST endpoints are GET/POST
`/api/v1/workspaces/{workspace}/expenses` and PATCH/DELETE
`/api/v1/workspaces/{workspace}/expenses/{expense}`. Responses include public
identifiers, status labels, current versions, and edit/delete eligibility; they
contain no internal database identifiers. This slice adds no approval tools,
receipt upload, recurrence or invoice claim/release integration.

### Client and agreement management

Portal contacts use `clients.portal_access.list`, `clients.portal_access.grant`,
`clients.portal_access.update` and `clients.portal_access.revoke`. Their REST
routes are GET/POST `/clients/{client}/portal-access` and PATCH/DELETE
`/clients/{client}/portal-access/{user}` under the workspace API prefix. Owners
and admins may use them; every write checks that authority under the access lock.
Listing requires `clients:read`, granting requires `clients:write`, and changing
scope or revoking requires both scopes. Writes use the client-management cutover
flags and the MCP `mcp.write.clients` switch.

The listing returns each contact's scope, selected projects, version and finished
URLs, with cursor pagination capped at 100 contacts. Granting identifies an
existing account by exact email; it must be bound to the configured identity
provider and have a subject. Every write requires `confirm: true` and an
idempotency key. Scope changes and revocations also require `expected_version`
from the latest listing, checked under the lock against the membership and its
exact project reach. Company scope takes no project selection; project scope
requires projects belonging to that client in the same workspace. REST and MCP
share receipts and the same `WorkspaceAccess` boundary as the web forms.

Portal invitations (#430) use `clients.portal_invitations.list`,
`clients.portal_invitations.create`, `clients.portal_invitations.resend` and
`clients.portal_invitations.revoke`: GET/POST `/clients/{client}/portal-invitations`,
POST `/clients/{client}/portal-invitations/{invitation}/resend` and DELETE
`/clients/{client}/portal-invitations/{invitation}`. They invite an address nobody
signs in with yet: the identity provider emails an admission link to this
application only, and the invited scope is given when that person first signs in
with a matching address, if the inviter is still an owner or admin and the projects
still belong to the client. An address that already signs in is refused; grant it
portal access instead. Owners and admins only; listing requires `clients:read`, and
each write `clients:write`, `confirm: true` and an idempotency key. **The
acceptance link is never returned to agents** - it is a bearer credential, shown
only to managers on the client's settings page - and replays return the stored
response without asking the provider again. All four are behind
`SVC_PORTAL_INVITATIONS_ENABLED` (off by default); the writes also need the
client-management cutovers. Provider refusals answer 429 with `Retry-After` when
rate limited, 503 when invitations are unavailable or misconfigured, and 409 for an
invitation that is no longer open.

`clients.create`, `clients.update`, `clients.archive`, `clients.restore`,
`agreements.create`, `agreements.update`, `agreements.activate` and
`agreements.terminate` require `clients:write` and workspace owner/admin
permission. They are available only when both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_CLIENT_WRITES_ENABLED` are true; the client flag defaults to false and
nothing enables it at deployment. REST and MCP share these workflows
and take an idempotency key with the same actor/workspace/OAuth-client/operation
contract: a retry returns the first result and reusing a key for a different
request is a conflict.

Equivalent REST routes live under `/api/v1/workspaces/{workspace}`:

| Tool | Method and path |
| --- | --- |
| `clients.list`, `clients.create` | GET/POST `/clients` |
| `clients.get`, `clients.update` | GET/PATCH `/clients/{client}` |
| `clients.archive`, `clients.restore` | POST `/clients/{client}/archive`, POST `/clients/{client}/restore` |
| `agreements.list` | GET `/agreements` |
| `agreements.get`, `agreements.update` | GET/PATCH `/agreements/{agreement}` |
| `agreements.create` | POST `/clients/{client}/agreements` |
| `agreements.activate`, `agreements.terminate` | POST `/agreements/{agreement}/activate`, POST `/agreements/{agreement}/terminate` |

Reads require `clients:read` for clients and `billing:read` for agreements.
Every existing-record write requires `expected_version` from its latest read;
agreement creation uses the parent client's version. Client creation has no
existing record to version. Versions advance for web edits too, and are checked
under the row lock. REST writes require an `Idempotency-Key` header; MCP uses
`idempotency_key`. Receipts are shared across transports. Activation changes the
billing terms in force and termination is irreversible, so both require
`confirm: true` after explicit user confirmation. Signing remains web-only.

Nothing is deleted. "Delete" is **archive**: `clients.archive` deactivates a
company and `clients.restore` reverses it; `agreements.terminate` ends an
agreement (`ends_on` defaults to today in the workspace timezone, never extends
an earlier end and never lands before the start). Invoices, time, payments and
capacity history keep pointing at the archived record. Termination is one-way -
a terminated agreement cannot be reactivated, so a mistake is corrected with a
new agreement.

These are the same workflows the web forms call, not a second implementation:
`CreateClientCompany`, `UpdateClientCompany` and `AgreementWorkflow`. The
validation rules are the forms' own, so a value the browser refuses is refused
here. `clients.update` and `agreements.update` change only the fields sent -
omitting a field leaves it alone and sending a nullable field as `null` clears it.
Enabling automatic invoice delivery needs `automatic_invoice_email_delay_days` and
someone to send to; disabling it cancels deliveries still scheduled.

Deliberately not exposed: signing an agreement, editing an agreement's `status`
directly, consuming a proposal through `agreements.create`, scoping one to a project,
and any removal of a row. Proposal-derived agreements instead come from confirmed
`proposals.accept`. `agreements.create` makes a draft that bills nothing until
`agreements.activate`, which refuses an overlap with another active agreement for
the same client.

## Received-payment bookkeeping

`payments.list` requires `payments:read` and an explicit `invoice_id` or
`company_id`. It follows invoice visibility, defaults to 25 rows and caps pages
at 100 using an opaque cursor. Portal readers see only payments on their visible
issued, partially paid or paid invoices. Project-scoped portal users must be granted every project on an invoice; mixed-grant and unattributed invoices are withheld.
The reference, the operator's private `notes` and the
`external_finance_transaction_uuid` the payment was recorded against are returned
to workspace owners/admins - who alone may write them - and are null for every
other reader. Processor identifiers and finance reconciliation allocations are
never part of this response. `manual` says whether the payment was recorded by
hand rather than by a processor. `refunds` lists a hand-recorded payment's
refunds and the reversals answering them, oldest first, each with its `id`,
`kind` (`refund` or `reversal`), `amount`, the `reverses_refund_id` /
`reversed_by_id` linking the two, `recorded_at` and a `reason` returned to
owners/admins only; a processor payment's list is empty, because its refunds are
the processor's report of a total. Each row carries an opaque `version`, which
every payment write requires. The operator's invoice screen renders each payment
and line through the same presenters as `payments.list` and `invoices.get`, so
the screen and the API cannot drift apart.

`payments.record` records money already received; it does not collect money,
create a Stripe intent, issue a refund, or change an existing payment's status.
It requires an owner/admin, the `payments:record` scope, and both
`AGENT_API_WRITES_ENABLED=true` and `AGENT_API_PAYMENT_WRITES_ENABLED=true`.
The payment flag defaults to false and the outer flag remains the emergency stop.
The MCP catalog, REST route and capability inventory use the same cutover.

Provide the invoice ID, positive integer amount in minor units, matching uppercase
currency and method (at most 40 characters, the width of its column) explicitly;
never infer an amount. The fields are the invoice screen's payment form's
(#454): `received_on` (`Y-m-d`) is the day the money arrived and defaults to
today on the workspace calendar - give the actual date, never the invoice date;
`reference` (for example, cash need not have one), `notes` and
`external_finance_transaction_uuid` are optional; `status` is `succeeded` (the
default) or `pending` for money not yet cleared, such as a cheque, which
`payments.set_status` later settles. `failed`, `canceled`, `refunded` and
`disputed` are where a recorded payment ends up and are reached only by
transitioning it, never recorded directly - on the screen as through the API. A
mandatory idempotency key protects retries; reusing a key with another payload is
refused, and a field sent as `null` is the same request as one omitted. The
existing service refuses a succeeded payment over the balance and draft/void
invoices, and accepts dates within its workspace-calendar two-year window. An
identical retry remains safe after full settlement and when the original date
ages below the creation window.

REST uses GET/POST `/api/v1/workspaces/{workspace}/payments`, with the same scopes
and roles; POST takes the key in `Idempotency-Key`. The separate finance API
`/invoice-payments` lists reconciliation data and is not this recording endpoint.
The CLI `svc:billing:payment` records payments through the same
`ReceivedPaymentData` rules and `RecordReceivedPayment` operation, so it accepts
exactly the statuses and fields `payments.record` does. Browser URLs remain the route for initiating a customer payment.

`payments.record` also takes an optional, closed `finance_reconciliation`
(`external_system_slug`, `external_transaction_uuid`, `allocated_amount`),
which writes the new payment's finance allocation in the same transaction and
receipt, as `finance.reconciliations.upsert` would. It needs the
`finance:reconcile` scope as well (checked again on a replay) and a
`succeeded` payment, never sets `external_finance_transaction_uuid`, and is
audited under both operations. Null means none. See
[the finance API](finance-api.md#recording-a-deposit-and-its-allocation-in-one-call).

For OAuth and MCP callers, `payments.record` refuses a new payment, under the
invoice lock, while another payment on the invoice is `pending`, is a Stripe
payment stored as `failed` (whose intent can still be confirmed), or carries a
status SVC cannot read exactly, and while Square collects the invoice. Recording
any payment, even a pending one, advances the invoice's version. `invoices.get`
shows owners and admins `pending_payments` and `collection_owner` (`svc` or
`square`) for exactly these two checks; both are null for other readers. A
completed receipt replays before either check, so a retry of a payment already
recorded still answers with it.

The website calls the same operation with a server-selected operator
bookkeeping policy: an owner/admin may explicitly record money already
received on an invoice Square collects, preserving that website capability.
Only the trusted authenticated session adapter can select this policy; it is
not a request field and OAuth/MCP callers cannot select it. The invoice version,
agreement/snapshot checks and in-flight payment refusal still apply. Recording
this money initiates no processor charge and does not remove the Square link.

An invoice with any disputed payment is `disputed`, even when only part of the
payment is contested or replacement payments already cover its balance.
`invoices.list` accepts that status. The invoice remains visible and charged;
collection and reminders pause, while `payments.record` can still record money
already received against the remaining balance. Disputed payments supply no
spendable credit until restored; restored excess after a win is available to
future drafts. See the [disputed invoice contract](finance-api.md#disputed-invoice-state).

### Correcting a recorded payment

`payments.correct` corrects a payment's **descriptive** fields - `method`,
`reference`, `notes` and `received_on` - and nothing else. It is gated as
`payments.set_status` and `payments.refund` are: the `payments:record` scope
together with `payments:read`, which supplies the version it checks, an
owner/admin, both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_PAYMENT_WRITES_ENABLED`, and the MCP kill switch, each rechecked
before a receipt is replayed. REST is
`PATCH /api/v1/workspaces/{workspace}/payments/{payment}` with the key in
`Idempotency-Key`.

**Grant change (#473).** Until #473, `payments.correct` needed only
`payments:record`. It now also needs `payments:read`. An existing OAuth client,
dynamic registration, personal API token or operator-issued agent token that holds
`payments:record` without `payments:read` keeps every other payment tool, but
`payments.correct` disappears from its `tools/list` (`context.get` lists it as
withheld, `scope_not_granted`) and the REST route answers 403 with
`error="insufficient_scope", scope="payments:read"`. Nothing is migrated
silently: re-authorize the client requesting both scopes, or issue a
replacement token with both (`svc:auth:issue-agent-token ... payments:record
payments:read`) and revoke the old one. A client that could already call
`payments.set_status` or `payments.refund` holds both and is unaffected; in
practice every client that corrects a payment already reads it first with
`payments.list` and so already holds `payments:read`.

- `expected_version` is required: the `version` a `payments.list` row carries.
  Any write to the payment - a status change, a refund, another correction -
  moves it, and a stale one is refused with a conflict before anything is
  written. Re-read and decide again.
- `reason` is required (at most 500 characters) and kept in the client history.
- Name at least one of `method`, `reference`, `notes` and `received_on`; the
  published schema and the tool input schema both require it. Omitted fields are
  unchanged; an explicit `null` reference or note clears it.
  `received_on` has the same `Y-m-d`, workspace-calendar two-year window as
  recording, and `method` the same 40-character bound as its column.
- Amount, currency, status, refunded amount, the invoice and processor fields
  are refused. Money is corrected by the operations that preserve history:
  `payments.set_status` and `payments.refund`, below.
- `notes` is returned by `payments.list` to the owners/admins who may correct it,
  so a correction can be read back and checked.
- A correction that changes nothing records nothing. Otherwise one
  `invoice.payment_corrected` activity records a before/after of only the fields
  that changed, plus the reason.
- The response is the corrected row exactly as `payments.list` returns it,
  including its new `version`.

Operators use the same service without tinker:

```
php artisan svc:billing:correct-payment <payment> --workspace=<workspace> \
    [--method=<text>] [--reference=<text>] [--notes=<text>] \
    [--received-on=YYYY-MM-DD] --reason=<text> \
    [--expected-version=<version>] [--dry-run] [--format=json]
```

An option left out is left alone; one given empty (`--reference=`) clears it.
`--dry-run` runs every check inside a transaction that is always rolled back.
The output carries the payment's version for a following `--expected-version`.


### Withdrawing, failing or refunding a recorded payment

`payments.set_status` (`POST .../payments/{payment}/status`) and
`payments.refund` (`POST .../payments/{payment}/refunds`) change what a payment
recorded by hand is worth (#455). Both are gated as the other payment writes
are - an owner/admin, both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_PAYMENT_WRITES_ENABLED`, the MCP kill switch - and require
`payments:record` with `payments:read`, which supplies the version. Both need
`confirm: true`, the payment's `version` from `payments.list` as
`expected_version`, a `reason` kept in the client history, and an idempotency
key; every gate is rechecked before a receipt replays.

- `payments.set_status` takes `status`: `succeeded` when a pending payment
  cleared, `failed` when it bounced or was reversed, `canceled` when it was
  recorded in error. A partly refunded payment is refunded in full instead, and
  `failed`, `canceled`, `refunded` and `disputed` are final - money that arrives
  later is recorded with `payments.record`.
- `payments.refund` takes `amount`: what was returned to the client now, in
  minor units, at most what is left. SVC never sends the money; it records that
  it was returned.
- The invoice is recomputed, so a paid invoice reopens by the amount that
  stopped counting. Correct or void it separately if nothing is owed.
- A payment recorded by a processor (`manual: false`), a reconciled amount, and
  any transition outside the table in
  [payments.md](client-management/payments.md#withdrawing-failing-or-refunding-a-payment-recorded-by-hand)
  are refused. The response is the payment exactly as `payments.list` returns it.

`payments.reverse_refund`
(`POST .../payments/{payment}/refunds/{refund}/reversal`, #472) corrects a
refund recorded in error - the money never left - with a reversing entry linked
to it, gated and confirmed exactly as above. The refund is named by its
`refunds[].id` from `payments.list` (`refund_id` over MCP) and is never edited:
the reversal is a second entry, and the activity records both ids. `amount`
reverses part of the refund; omitted, all of it. A refund is reversed once,
never by more than it was, and a reversal is not itself reversed. The money
counts again, so a fully refunded payment succeeds again and the invoice is
recomputed - refused on a void invoice or where successful payments would exceed
the invoice total. A processor payment's refunds follow its own events and are
refused. See
[payments.md](client-management/payments.md#reversing-a-refund-recorded-in-error).

Operators use the same rules without the agent gates through
`svc:billing:payment-status`, `svc:billing:refund-payment` and
`svc:billing:reverse-refund`, each with `--expected-version` and `--dry-run`.

### Finance reconciliation (#457)

`finance.invoice_payments.list`, `finance.payouts.list`,
`finance.processor_movements.list`, `finance.reconciliations.acknowledge`,
`finance.reconciliations.upsert` and `finance.reconciliations.deactivate` are
the finance reconciliation routes (`/api/v1/workspaces/{workspace}/invoice-payments…`
and `…/payouts`, see
[the finance reconciliation API](finance-api.md)) as operations: in the OpenAPI
document, gated by `finance:read` and `finance:reconcile`, and served as MCP tools
through `AgentMcpFinanceTools`, which dispatches each tool to its own REST
binding with `AgentMcpRestCall` like every other routed tool, so a tool and its
route run one
`FinanceReconciliationOperations`. The `svc:finance:payments`,
`svc:finance:payouts`, `svc:finance:processor-movements`, `svc:finance:reconcile` and `svc:finance:unreconcile`
commands call the same operation.

- Over OAuth and MCP every finance operation needs a workspace owner/admin; the
  tools are manager-only in discovery. The finance integration's Sanctum token
  keeps its original contract on the same routes, where its `finance.read` /
  `finance.reconcile` abilities stand for the two scopes.
- The writes are named by their path - payment, external system, transaction -
  and are the same write however often they are sent, so the tools take no
  `idempotency_key` and the routes no `Idempotency-Key`. Each write is audited in
  `agent_mutation_audits`.
- They sit behind no write cutover, since the finance integration never did; the
  MCP switches (`mcp.read`, `mcp.write`, or the tool name) withhold the tools.
- The tool arguments are the REST ones, with the path parameters named
  `payment_id`, `external_system` and `transaction_id`, and the list's invoice
  filter `invoice_id`.

### Changing a due date or scheduled issue date

A draft and an issued invoice take different doors, because changing one is
editing a working document and changing the other is altering something the
client may already have.

`invoices.update_details` (`PATCH .../invoices/{id}/details`, `billing:write`)
changes a draft's `due_date` and/or `notes` and nothing else. It accepts every
draft kind, which `invoices.update_draft` cannot: that tool replaces lines, so
it refuses generated drafts, which are regenerated instead. Regeneration
rewrites a generated draft's period facts and lines but never these two
columns, so the change survives it. A due date may not precede the draft's
issue date. A draft without one is issued today, so a draft whose due date has
already passed cannot be issued until its due date is moved; this tool is how.
The change is recorded as `invoice.details_updated` in the client history.

The same tool sets or clears (`null`) a draft's `scheduled_issue_date`: the day
an operator plans to issue it, which used to live only in free-text notes.
`invoices.create_draft` and `invoices.update_draft` accept it too, the website
edits it beside the due date, and invoice reads return it. It is a plan, not a
trigger: nothing issues, sends or generates on it, and it is held to neither
the due date nor the issue date. `billing_audit.stale_and_missing` flags the
draft once the day has passed.

`invoices.correct` (`POST .../invoices/{id}/correct`, `billing:deliver`,
`confirm: true`, a `reason`) is the website's audited correction of an unpaid
issued invoice that has not been emailed to the client, through
`InvoiceCorrectionService`. It changes the due date and/or line wording, and
money only on operator-authored lines. Omitted `lines` are kept as they are, so
a due-date correction need not restate them; supplied lines must name every
existing line. The opaque version is compared on the locked row. The document
revision advances, any automatic client delivery is held for explicit release,
and the change is recorded as `invoice.corrected`. Paid, partially paid, sent
and void invoices are refused: void and reissue those.

### Reopening an invoice for reissue

`invoices.reopen` (#594) takes an issued, unpaid cadence invoice back to a
draft that keeps its invoice number, so that a snapshot made stale by time
approved after it was built - the retainer application lines are not
re-measured at issue - can be rebuilt and issued again without a void and a
replacement number. It requires `billing:write`, a workspace owner/admin, both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_INVOICE_WRITES_ENABLED`, and the
`invoices.reopen` MCP kill switch. It takes the `invoices.void` contract:
`confirm: true`, the invoice's `expected_version` (compared on the locked row,
so a change committed while the request waited is a 409), a required `reason`
of at most 1000 characters, and an `idempotency_key`; the same receipt,
transaction and replay rules apply. It is marked destructive, like void.

Reopening takes void's guards with the verb changed - nothing paid, no pending
or unreadable-status payment, no client delivery in flight (the messages are
void's: "A paid invoice cannot be reopened.", and so on) - and refuses, with a
422 naming the cause:

- an invoice Square collects, linked or on a billed-in-Square agreement (unlink
  it first with `invoices.square_unlink`; the series marker is cleared on the
  agreement);
- an invoice corrected in place, whose corrections are evidence of what the
  client was told and are never superseded (corrections have no resolution
  state, so such an invoice cannot be reopened; void it if it must be replaced);
- any invoice that is not an issued cadence invoice of an agreement, because
  only those can be regenerated;
- a regeneration whose catch-up charge would differ from the one a later live
  invoice was sized against. Unlike a void, a reopen is not refused merely
  because a later invoice relies on the charge: the invoice stays and keeps
  charging, so it is refused only if the rebuild would change it.

Under the agreement lock the allocations are released as a void releases them
(`deferred_buydown` reservations remain excluded), the invoice becomes a draft
with `reopened_at`, the reason and `invoice.reopened` in the client history, its
automatic delivery is cancelled as a void cancels it, and
`ClientInvoicingService::regenerateDraftInvoice()` rebuilds it from the time
approved now, **in the same transaction**: releasing alone would leave a draft
whose lines still describe time no longer attached to it, so there is no
committed state between reopened and regenerated, and a regeneration that cannot
proceed rolls the reopen back. The time returns to `approved` and the regenerated
draft consumes it again; it becomes `invoiced` when the invoice is issued.

Reissue is the plain `invoices.issue`, with the normal checks including charge
re-measurement under the agreement lock. Because the invoice was reopened it is
a reissue: its document revision advances rather than restarting (deliveries and
administrator notices are keyed by revision, and the first issue's stay on the
record), `reissued_at` is set, `invoice.reissued` is recorded beside the original
`invoice.issued` and `invoice.reopened`, a new automatic delivery is scheduled
by the company's settings, and the delivery (`is_reissue`), its default subject
("Invoice N (reissued)"), the email and the PDF say it was reissued under the
same number.

### Issuing an invoice

`invoices.issue` requires `billing:deliver`, a workspace owner/admin, both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_INVOICE_WRITES_ENABLED`, and the
`invoices.issue` MCP kill switch. It takes the draft's current
`expected_version` and `confirm: true`, and runs the REST route, which runs
the tenant-scoped `IssueInvoiceAction` inside `AgentMutationExecutor`:
one receipt, one transaction, a digest-bound idempotency key, and a replay
guard that rechecks both cutovers and the role. A plain issue hashes exactly as
the REST route always has, so receipts written before the migration replay.

Issuing never emails the client itself. If the client company has automatic
invoice email enabled, `InvoiceLifecycleService::issue()` schedules a delivery
for `svc:billing:dispatch-invoice-emails` to send after the company's
configured delay, exactly as a browser issue does; otherwise nothing is
scheduled. SVC's internal issued-invoice review notice to the workspace
administrator is queued as it is for every issuance.

The optional `payment` object (`amount` in minor units, `currency`,
`received_on`, `method` of at most 40 characters as on `payments.record`,
optional `reference`) records money already collected
elsewhere - a card autopay, say - in the same transaction and under the same
receipt, so the draft ends issued and paid (or partially paid) or is not
changed at all. `applyPayment()` cancels the automatic delivery `issue()` just
scheduled; because both happen in one transaction the dispatcher can never see
a sendable, unpaid invoice for money already received. Doing it as
`invoices.issue` followed by `payments.record` leaves exactly that window.

The payment carries every gate `payments.record` has: the `payments:record`
scope, `AGENT_API_PAYMENT_WRITES_ENABLED` (inside the outer cutover), the
owner/admin role, and the `payments.record` MCP kill switch, each rechecked
before a receipt is replayed. It never initiates a charge, refuses overpayment
and currency mismatch, keeps `applyPayment()`'s idempotency semantics (its
domain key is namespaced by caller, OAuth client, operation and key, so reusing
a key with `payments.record` records a separate payment), and is audited as
both `invoices.issue` and `payments.record`. Any refusal - a gate, a stale
version, an overpayment - writes nothing. A payment is accepted only on a
draft; an issued invoice takes `payments.record`. The option is folded into
`invoices.issue` rather than `payments.record` because the consequential step
is issuing, which already has the explicit-confirmation and version contract,
and the response is the invoice.

With `billing:read`, and while `invoices.get` is not switched off, the MCP
response is the invoice exactly as `invoices.get` returns it; otherwise it is
the plain mutation shape (`InvoiceIssueResponse`). The tool gets it by asking
the REST route for `?view=read`; without the view, or without `billing:read`,
REST returns the mutation shape. The view is not part of the request digest.
The REST route accepts the same `payment` object and applies the same gates.

### Square links (#438)

SVC is the system of record; some clients still pay through Square. These
operations record SVC's side of that relationship and never call Square:
Square identifiers are opaque, shape-checked strings.

| Operation | REST | Scopes | Write cutover |
|---|---|---|---|
| `invoices.square_link` | `POST /workspaces/{workspace}/invoices/{invoice}/square-link` | `billing:write`, `billing:read` | invoices |
| `invoices.square_unlink` | `DELETE /workspaces/{workspace}/invoices/{invoice}/square-link` | `billing:write`, `billing:read` | invoices |
| `invoices.square_cover` | `POST /workspaces/{workspace}/invoices/{invoice}/square-coverage` | `billing:write`, `billing:read` | invoices |
| `invoices.square_covered_payments` | `GET /workspaces/{workspace}/invoices/{invoice}/square-coverage/{squareInvoice}/payments` | `billing:read` | read |
| `invoices.square_covered_payment_record` | `POST /workspaces/{workspace}/invoices/{invoice}/square-coverage/{squareInvoice}/payments/{payment}` | `billing:read`, `payments:record` | payments |
| `invoices.square_uncover` | `DELETE /workspaces/{workspace}/invoices/{invoice}/square-coverage` | `billing:write`, `billing:read` | invoices |
| `companies.square_customer.get` | `GET /workspaces/{workspace}/clients/{client}/square-customer` | `billing:read` | - |
| `companies.square_customer.set` | `PUT /workspaces/{workspace}/clients/{client}/square-customer` | `billing:write`, `billing:read` | clients |
| `companies.square_customer.clear` | `DELETE /workspaces/{workspace}/clients/{client}/square-customer` | `billing:write`, `billing:read` | clients |
| `agreements.square_series.set` | `PUT /workspaces/{workspace}/agreements/{agreement}/square-series` | `billing:write`, `billing:read` | clients |
| `agreements.square_series.clear` | `DELETE /workspaces/{workspace}/agreements/{agreement}/square-series` | `billing:write`, `billing:read` | clients |

All are workspace-manager only, take an idempotency key and the record's
current `expected_version`, and are recorded in the client's activity.

- **Links.** Each side has at most one active link. Linking a Square invoice
  that is linked anywhere in the workspace is refused with 409, and so is
  linking an invoice that is already linked unless `replace: true` re-points it.
  Unlinking needs `confirm: true` and is soft: the row stays as history with who
  and when, and either side can be linked again. The link records the Square
  number, its series key (the six-digit prefix of a recurring `NNNNNN-R-NNNN`
  number; null for a one-off), the invoice's currency (a supplied `currency`
  must match it), and how it was found: `manual`, or `suggested` with the score
  and reasons the person confirmed. Nothing is ever linked automatically.
- **Covered by (#487).** Links stay one-to-one. When one SVC invoice covers
  what Square split into several, link the primary pair, then record each other
  Square invoice as covered by that SVC invoice with `invoices.square_cover`
  (`square_invoice_id`, `square_invoice_number`, optional `currency`). The
  invoice must be linked and not void; a Square invoice linked or covered
  anywhere is refused with 409, and a covered Square invoice cannot be linked
  until `invoices.square_uncover` clears the coverage (kept as history). Links
  and coverages are serialised on the workspace row, so the two claims cannot
  both commit. An invoice that covers others cannot be unlinked until those
  coverages are cleared, and `invoices.square_candidates` lists a covered Square
  invoice under `excluded.linked_elsewhere` rather than offering it. A
  coverage is not a link and never starts automatic collection or allocation.
  Review its current completed payments with `invoices.square_covered_payments`
  (or the invoice screen's **Review payments**) and explicitly record one with
  `invoices.square_covered_payment_record`. Confirmation requires the current
  invoice `expected_version`, selected payment `reviewed_version`, `confirm: true`
  and an idempotency key. The operation refetches provider facts and disputes,
  rechecks the active coverage and invoice under locks, and records the payment's
  canonical Square identity once. Refunded, disputed, over-balance or in-flight
  cases return a refusal and no record URL; they remain manual review cases.
  A payment stays in the billing audit until it is actually recorded. The billing audit does not report a
  covered series member as an unmirrored period. `invoices.get` lists coverages
  in `square.covers`.
- **Customer mapping.** One active mapping per client and per Square customer.
- **Billed in Square.** An agreement's `square_series_key` and
  `square_location_id` mark it as billed by that Square series. Square numbers
  invoices uniquely per location only, so a series is its prefix in one
  location, and one agreement per location may hold a prefix (#488). Omit
  `location_id` to take the one location SVC's mirror holds the series in; a
  series the mirror holds in no location, in more than one, or with an invoice
  naming none is refused with 422 until `location_id` names it. A marker set
  before markers named a location has `square_location_id: null` and still
  holds the prefix in every location until the agreement is marked again.

**Square collects what Square bills.** An invoice that is linked, or whose
agreement is billed in Square, may still be generated, issued and have payments
recorded, but SVC never collects it: every client-facing collection path -
invoice email (website, `invoices.send`, keyed retries, after-commit sends, the
scheduled dispatcher), scheduling automatic delivery at issue, releasing a held
delivery, and the Stripe payment intent behind the client's payment link -
refuses it with a 422 that says why. `SquareCollectionGuard::SITES` lists them,
and `SquareCollectionSitesTest` fails if code reaches a collection primitive
anywhere else.

Reads: `invoices.get` carries `square` (link, agreement series, whether Square
collects, and the refusal text) for managers and null otherwise;
`clients.get` and `clients.list` carry `square_customer`; agreement reads carry
`square_series_key` and `square_location_id`. The hosted payment address comes only from the linked
invoice's mirrored `public_url`. Managers also receive `payment_mismatch` when
SVC is paid outside Square while Square remains open, with a finished dashboard
link. `billing_audit.stale_and_missing` includes the same flags in
`square_payment_mismatches`, with a count and at most 100 invoices, and the
phase-two flags in `square_billing_audit`: billed series periods with no linked
svc invoice, Square payments svc has not recorded, unlinked customers with an
open balance, and series drift from the agreement (see
[billing-audit flags](square-status.md#billing-audit-flags)).
See [payment status transport](square-status.md) for the signed receiver and sweep.

### Payment reminders (#438)

| Operation | REST | Scopes | Write cutover |
|---|---|---|---|
| `invoices.reminders.list` | `GET /workspaces/{workspace}/invoices/{invoice}/reminders` | `billing:read` | - |
| `invoices.send_reminder` | `POST /workspaces/{workspace}/invoices/{invoice}/reminders` | `billing:write`, `billing:read` | invoices |
| `companies.payment_reminders.get` | `GET /workspaces/{workspace}/clients/{client}/payment-reminders` | `billing:read` | - |
| `companies.payment_reminders.set` | `PUT /workspaces/{workspace}/clients/{client}/payment-reminders` | `billing:write`, `billing:read` | clients |

All are workspace-manager only; the website uses the same routes. A reminder is
an email from SVC's usual invoice sender to the client's billing address and
portal users about an issued or partially paid invoice with a balance. An
invoice Square collects carries Square's own payment page from the mirror and
is refused, never sent with SVC's own link, when that page is unusable. Other
invoices link to the client portal when the client could open it there, and
carry the PDF. `invoices.send_reminder` takes the invoice's `expected_version`,
`confirm: true` and an idempotency key; it registers the reminder with its
receipt and sends it once that commits, so a replay sends nothing more. The
automatic cadence (3 days before the due date, on it, then 7 and 14 days after)
is off until `companies.payment_reminders.set` turns it on for a client. Every
reminder is logged with who or what sent it, the recipients, the step, the pay
link and the result; see
[payment reminders](client-management/invoice-delivery.md#payment-reminders).

## Legacy REST receipt cutover

REST time-entry, task, and invoice writes now derive their idempotency namespace
from the authenticated `oauth_client_id`, matching MCP. Earlier REST receipts used
`testing-client`; neither those receipts nor their mutation audits retained the
real client identity. The migration therefore performs no attribution or backfill.
Expense and received-payment writes already used authenticated namespaces.

An existing legacy receipt for the actor, workspace, operation, and key returns
409 without disclosing its result or invoking the mutation, regardless of payload
or receipt status. Older receipts with a null workspace also refuse that actor's
operation/key because their tenant provenance is unknown. Receipts in another
known workspace or for another actor do not interfere. Operators must establish
whether the original action succeeded before intentionally submitting a new action
with a fresh key; callers must not automatically replace conflicting keys.

For fresh keys, the executor first inserts a `namespace_guard` receipt under the
old namespace, then reserves the real client's receipt, executes the mutation,
and completes the real receipt. All of this commits or rolls back together. A
guard has a fixed protocol digest, no result IDs, and no completion timestamp;
all fields are verified before treating an existing row as a guard. Distinct
clients can share a guard while retaining independent authenticated receipts.

The old and new writers contend on the same unique index. If an old writer wins,
the new writer refuses its ambiguous receipt. If the new writer wins, the old
writer encounters a non-completed guard and cannot run its callback or replay a
result. Duplicate authenticated inserts are isolated in a savepoint, so replaying
an existing MCP receipt also commits its new compatibility guard. Failed new
mutations roll back new guards; no effect was committed to protect in that case.

Retain guards and receipts: pruning either requires a separately reviewed retention
and rollout protocol. This cutover supports coexistence with the immediately prior
workspace-scoped writer; it does not authorize deploying older code that creates
null-workspace receipts. No production data or environment flags are changed by
this migration.

### Withdrawing time approval and reading allocations

`time_entries.unapprove` calls
`POST /api/v1/workspaces/{workspace_id}/time-entries/{entry_id}/unapprove`.
It requires `time:approve`, a workspace owner/admin, the current
`expected_version`, and an idempotency key. Both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_TIME_ENTRY_WRITES_ENABLED` must be enabled for agent callers. Project
managers can approve time but cannot withdraw approval. The operation shares the
web time workflow: it locks the entry and any draft invoice, checks the version,
clears the approver and approval time, and returns the entry to draft. Explicit
rates remain; rates inferred at approval are cleared. Draft-invoice time is
released and the invoice regenerated atomically. Time already invoiced or linked
to an issued, paid, void, or unknown-status invoice is refused. Replays recheck
current authorization and are audited without repeating the mutation.

Every time-entry row includes `invoice_id` (a public UUID or `null`) and
`allocation_state`: `unallocated`, `reserved` on a draft invoice, or `consumed`
on a non-draft invoice. This classification follows the allocation, even when a
legacy entry still has status `approved`. Only allocations whose pivot, line,
and invoice belong to the entry's workspace and client company affect these
fields. Allocation reads are eager loaded for a page.

The opaque time-entry `version` binds both the entry revision and these visible
allocation fields. Reserving time, releasing it, changing its invoice, or moving
from reserved to consumed changes the token even if the entry itself was not
edited. Use the latest token for a following time mutation. Tokens identify the
current representation; they are not ordered revisions or a change feed, and
returning to an identical representation may restore a previous token. Listing
still uses ordinary ID pagination, so detecting allocation changes requires
rereading the relevant entries rather than treating a cursor as a watermark.

`time_entries.list` and its REST route accept `allocation_state` and
`is_billable` filters. `allocation_state: unallocated` includes draft and deferred
rows that have no allocation. To find the exact work behind
`operations.summary.time.approved_billable_unallocated_minutes`, pass
`unallocated: true`: it selects approved, priced, billable, non-deferred entries
with no tenant-owned allocation. The summary and this filter use the same
selector, including flat-hourly subcontractor pricing. Combine it with existing
project/date filters to narrow the result. Pagination cursors are bound to all
filters; fetch a new first page when changing them. Pages default to 25 rows and
are capped at 100; descriptions are preserved.

## Browser client records

Client creation, company details and business contacts on Settings use the same
`clients.*` and `clients.contacts.*` operations as REST and MCP. Their former web
mutation routes are retired (#385). Settings reads share the API presenters and
opaque versions; edits send `expected_version` and an idempotency key. A lost
response retains the key for the same edit, while a refusal keeps the entered
values visible. Successful changes reload the affected props.

Existing contacts archive through the explicit-confirmation operation and restore
through its lifecycle twin. Updating contact details does not change archival
state. Adding a contact still creates no login or invitation. The signed-in
browser remains usable when agent write cutovers are off, under the same role and
workspace checks. Client creation returns a finished `Location` for its new home;
the client switcher follows that server URL.

## Expense finance provenance

`expenses.log` accepts optional `external_finance_transaction_uuid` on each
entry: an opaque UUID identifying the finance consumer's transaction. It is
creation-only provenance, not a reconciliation allocation, receipt, or proof of
payment. Existing requests may omit it or send null.

Keep the same `idempotency_key` when retrying the same request. Changing the UUID
under that key returns `409`; a different key is a distinct request, even with
the same UUID. There is no UUID uniqueness or deduplication rule. Do not change
keys to work around a retry conflict.

`expenses.update` cannot replace or clear the reference. Ordinary edits preserve
it, and generated recurring occurrences do not inherit it. The response returns
it only to workspace owners and administrators, including readers whose write
cutovers are disabled; other readers receive null. Generic model serialization
also hides it. Receipt upload and existing expense scopes are unchanged.

## Expense approvals, receipts, and recurrence

Workspace owners and administrators can use `expenses.approve` and
`expenses.unapprove` with `expenses:read` and `expenses:write`, a stable `idempotency_key`, and the
current expense `expected_version` from `expenses.list`. Approval stamps the
actor and time. Unapproval clears those stamps. Invoiced expenses and unknown
statuses refuse both transitions. Web and API calls share `ExpenseAction` and
the workspace-scoped lifecycle boundary.

`expense_schedules.list` requires `expenses:read` and manager access. It accepts
an optional `company_id`, `limit` (1–100), and workspace/filter-bound `cursor`.
`expense_schedules.create` requires `clients:read` and `expenses:write`; `.update`
and `.generate` require `expenses:read` and `expenses:write`. All three require
both expense write cutovers. Creation checks `expected_version` against the
locked client company; update and generation check the locked schedule. Read
client versions with `clients.get`. Schedules expose their own opaque `version`.
Editable facts are amount (minor units), currency, description, project, and
active status. Omitted or null project on schedule update clears attribution;
calendar anchor, cadence, and generation cursor cannot be edited.

Generation requires explicit user confirmation and `confirm: true`. It creates
at most 24 due draft expenses using the workspace calendar, preserves the
original anchor through short months, and never approves or invoices them. A
retry with the same key and request returns the original `generated_count`;
subsequent batches need the newly returned schedule version and a new key.

Receipt tools require manager access:

- `expenses.receipts.list` (`expenses:read`) lists at most the 100 newest
  available receipts and returns the current `expense_version`.
- `expenses.receipts.download` (`expenses:read`) returns a ten-minute signed
  `download_url` for one receipt belonging to that expense.
- `expenses.receipts.upload_url` (`expenses:read` and `expenses:write`, both write cutovers) prepares
  a ten-minute signed `upload_url` and returns the current `expected_version`.
  Preparing the URL does not write a receipt. POST multipart form fields `file`
  and `expected_version` to that URL with an `Idempotency-Key` header. Direct
  REST callers may POST to `/workspaces/{workspace_id}/expenses/{expense_id}/receipts`
  without preparing a URL first.

Both URL types require the caller's authorized API credential on use; the URL
alone grants no access. Each use rechecks manager access and the live expense.
Uploads accept at most 50 MiB, check the expense version under its lock, and
advance that version. The file digest, length, filename, and version bind the
idempotency key, so retries cannot replace the receipt bytes. Uploads use the
same private attachment storage adapter as the web receipt screen and record
success, replay, and refusal in the mutation audit.

Receipt upload bytes are staged, promoted and verified before the expense lock. A durable staged recovery row records both object keys before promotion. The locked mutation only checks the current expense version, publishes the prepared row and advances the expense revision. A process termination or rollback leaves recoverable staged state; failed cleanup preserves that state for `svc:attachments:repair`. Replays return the original receipt and discard their unused preparation.

`expenses.receipts.delete` uses `DELETE /workspaces/{workspace}/expenses/{expense}/receipts/{receipt}`
with `expenses:read` and `expenses:write`, `Idempotency-Key`, `confirm: true`,
`expected_version` for the receipt and `expected_parent_version` for the expense.
Receipt metadata now includes its opaque `version` and `lifecycle_state`; list,
upload and deletion responses also include `expense_version`. Deletion marks the
receipt unavailable through the existing retention lifecycle and advances the
expense revision once. It does not change expense money, approval or posting.
A completed retry rechecks current manager authority and exact parent lineage.
OAuth/MCP dual Portal identities remain refused. Trusted first-party sessions
use native staff authority for all receipt routes, so current managers retain
upload, list, download and deletion even if they also have portal access.

Native CLI deletion previews by default, with no receipt, audit or storage writes:

```sh
php artisan svc:attachments:delete-expense-receipt WORKSPACE_UUID EXPENSE_UUID RECEIPT_UUID \
  --actor=MANAGER_UUID --expected-version=RECEIPT_VERSION \
  --expected-parent-version=EXPENSE_VERSION --format=json
# Add --apply --confirm --key=STABLE_RETRY_KEY only after reviewing the preview.
```


### Completing an invoice workflow

All paths below start with `/api/v1/workspaces/{workspace}`. The four writes
require an `Idempotency-Key`, the current `expected_version`, a workspace
manager, and both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_INVOICE_WRITES_ENABLED`. The required `billing:read` scope lets the caller obtain the current invoice or agreement version. MCP uses the same actions and receipts.

| Tool | REST path | Scope |
| --- | --- | --- |
| `invoices.hold_delivery` | `POST /invoices/{invoice}/automatic-delivery/hold` | `billing:write`, `billing:read` |
| `invoices.release_delivery` | `POST /invoices/{invoice}/automatic-delivery/release` | `billing:deliver`, `billing:read` |
| `invoices.add_time` | `POST /invoices/{invoice}/time` | `billing:write`, `billing:read` |
| `invoices.generate_period` | `POST /agreements/{agreement}/invoices` | `billing:write`, `billing:read` |
| `invoices.preview_period` | `GET /agreements/{agreement}/invoices/preview?period_start=` | `billing:read` |
| `invoices.pdf` | `GET /invoices/{invoice}/pdf-link` | `billing:read` |
| `billing_audit.stale_and_missing` | `GET /billing-audits/stale-and-missing` | `billing:read` |
| `billing.audit_unplaceable_invoices` | `GET /billing-audits/unplaceable-invoices` | `billing:read` |
| `billing.audit_undated_collectible_invoices` | `GET /billing-audits/undated-collectible-invoices` | `billing:read` |
| `billing.audit_missing_billed_overage` | `GET /billing-audits/missing-billed-overage` | `billing:read` |
| `billing.audit_opening_rollover` | `GET /billing-audits/opening-rollover` | `billing:read` |
| `billing.preflight_schedule_generation` | `GET /billing-audits/schedule-generation-preflight` | `billing:read` |
| `billing.audit_unlinked_proposal_agreements` | `GET /billing-audits/unlinked-proposal-agreements` | `billing:read` |

The aggregate audits answer 404 to a caller who does not manage the workspace,
as for an unseen workspace, and the preflight answers 429 with `Retry-After`
once a workspace has run it 5 times in ten minutes. Over MCP that refusal
carries the same message, which says when to retry.

Holding applies to scheduled or failed automatic delivery on an issued invoice.
Release requires `confirm: true` and a held invoice; it schedules delivery through
the existing delivery flow. Neither operation sends the document immediately. Managers can inspect
`automatic_delivery_status` and `automatic_delivery_due_at` on invoice reads;
other viewers receive null for these operational fields.

`invoices.add_time` accepts 1–100 distinct approved, billable, unallocated
`time_entry_ids` from that invoice's workspace and client. Ad-hoc drafts retain
their existing lines. Cadence and interim overage drafts regenerate their whole
period with the shared billing engine; every selected entry must belong to that
service period and agreement project and appear in the resulting draft. This
preserves retainer, rollover and overage accounting. Other draft kinds are
refused. Use the returned version for a subsequent write.

`invoices.generate_period` requires an active recurring agreement, its version,
`confirm: true`, and an explicit `period_start` on a cadence boundary that has
already begun. This is the **retainer cycle start**: the resulting invoice's
`service_period_start` and `service_period_end` describe the preceding work
cycle. Monthly agreements use calendar months; other cadences follow the
agreement start date. Generation creates a draft and uses the canonical billing
engine, including any interim reconciliation it requires. It does not issue or
send. Repeating the same key replays the result and writes nothing; another key
for a period with a non-void invoice is refused with 409.

Where a deployment turns on `SVC_BILLING_SCHEDULED_CADENCE_DRAFTS`
(`svc.billing.scheduled_cadence_drafts`), the scheduler runs
`svc:billing:generate-cadence-drafts --apply` hourly. It creates the draft for
every active recurring agreement whose *current* period has started with no
non-void invoice, through the same guard and engine as
`invoices.generate_period`, and skips any agreement with an active billing
schedule, which issues its own invoices. A period whose draft would bill
nothing - a $0 retainer with no priced line, billable time or milestone - is
rolled back and reported rather than created, and stays in the audit's missing
periods. It only creates drafts: issuing and
sending stay an operator's. It does not reach back for older gaps. A refusal is
logged and retried on the next run. With the flag off, or for an older period,
a started period without a draft is waiting for an agent or operator to call
`invoices.generate_period` (the on-demand design of #377).
`billing_audit.stale_and_missing` lists every active recurring agreement whose
started period has no non-void invoice, with the `agreement_id` and
`period_start` these two tools take.

`invoices.preview_period` (manager only, `billing:read`) answers what
generation would write without writing it. It runs generation's own guard
under the same locks, then the same `ClientInvoicingService` call, inside a
transaction it always rolls back - the rehearsal command's method narrowed to
one period - so it refuses exactly what generation refuses, with the same
status, and its lines are the lines generation writes: `invoices.get` returns
the generated draft's lines identically, less their ids. It also lists the
client's other invoices the same generation would create, rewrite or remove,
such as interim overage drafts it creates or strips of their claims first
(only drafts can change), and returns `agreement_version`, the
`expected_version` a following `invoices.generate_period` must present. It
takes no idempotency key and leaves no receipt or audit row. It is a read, so
it stays available when the invoice write cutover is off.

`invoices.pdf` returns a URL and `expires_at`, valid for five minutes. The URL
uses the configured service origin and still requires current authentication,
`billing:read`, and visibility of that invoice. It is not a public bearer link.
The binary REST route is `GET /invoices/{invoice}/pdf`; supplied signatures are
validated and expired or altered signed links are refused.

`invoices.list` accepts `company_id`, `invoice_kind`, inclusive
`issue_date_from`/`issue_date_to` and `due_date_from`/`due_date_to`,
`service_period_overlaps: {from, to}`, and boolean `collectible`/`overdue`. REST
encodes the overlap as `service_period_overlaps[from]` and
`service_period_overlaps[to]`. An overlap requires both dates and excludes
invoices with no stored period. Collectible means issued or partially paid with
a positive balance; overdue additionally means due before today in the
workspace timezone. List and detail include `company_name` and nullable service
period dates. Cursors are bound to the chosen filters.

The MCP tool returns snapshots and does not accept `updated_since` or
`watermark`. The REST invoice listing also offers a change feed using those
parameters, restricted to workspace owners and admins; other authorized viewers
receive `422` naming `change_feed`. Portal callers can continue reading snapshots,
which omit invoices that have become hidden or void.

The manager-only stale/missing audit counts drafts whose due date or scheduled
issue date is before today in the workspace timezone, and sums minor-unit
balances separately by currency. A null date is no plan and never makes a draft
stale by itself; a draft late on both dates is counted once. It also counts started, active
recurring agreements without a non-void cadence invoice for the current
retainer period. Each identifier list contains at most 100 entries; counts and
balances remain complete, and truncation flags say when more rows exist.

The web payment form, `payments.record`, and `invoices.issue` with a payment
share `RecordReceivedPayment` and immutable `ReceivedPaymentData` facts.
Transport adapters retain their own permission and receipt checks. Standalone
agent payment keys retain their historical namespace, and issue-with-payment
keys remain separate; existing receipts continue to replay. The web form and
`payments.record` take the same optional date, status and bookkeeping fields;
a payment folded into `invoices.issue` remains limited to succeeded money
already received on a stated date. No processor charge is initiated.

### Recurring billing schedules

`billing_schedules.list` and `billing_schedules.get` now also have REST endpoints
at `/workspaces/{workspace_id}/billing-schedules`. Reads require `billing:read`
and workspace owner/admin access; list supports `is_active`, `limit`, and a
filter-bound cursor. Both transports return the current opaque schedule version.

`billing_schedules.create` (`billing:write` and `billing:read`) takes an explicit `company_id`,
`client_agreement`, cadence, next run date, due days, currency and bounded line
 template. Its `expected_version` is the **parent agreement** version from
`agreements.get`; there is no schedule row yet. One schedule per agreement is
allowed. Creation does not issue an invoice.

`billing_schedules.generate` (`billing:deliver` and `billing:read`) requires the **schedule** version
and `confirm: true` after explicit user confirmation. It invokes the same atomic
preflight and generation service as the website, generating and issuing every due
period through today. Normal automatic client delivery settings apply. An inactive
or not-yet-due schedule returns no invoices and keeps its revision. The result
contains the updated schedule revision and issued invoice references. Both writes
require an `Idempotency-Key` (MCP `idempotency_key`), current manager access on
replay, and both `AGENT_API_WRITES_ENABLED` and `AGENT_API_INVOICE_WRITES_ENABLED`.
Changed retry payloads and stale versions return 409; the original key replays the
original operation without generating additional invoices.

### Project administration and member access

Workspace owners/admins can administer projects through REST and MCP:

| Tool | REST route | Version to read first |
| --- | --- | --- |
| `projects.create` | `POST /api/v1/workspaces/{workspace_id}/projects` | Client company version from `clients.get` |
| `projects.update` | `PATCH /api/v1/workspaces/{workspace_id}/projects/{project_id}` | Project version from `projects.get` |
| `projects.archive` | `POST /api/v1/workspaces/{workspace_id}/projects/{project_id}/archive` | Project version |
| `projects.members.list` | `GET /api/v1/workspaces/{workspace_id}/projects/{project_id}/members` | Returns the project version |
| `projects.members.update` | `PUT /api/v1/workspaces/{workspace_id}/projects/{project_id}/members` | Project version from the member listing |

Client settings uses these same project operations with the signed-in browser
session, finished server-provided URLs, explicit confirmation, and stable retry
keys. Project create uses the client version; edit and member access use the
current project version. The former nested project creation, update, and access
web mutation routes have been retired. A refused write keeps the operator’s
unsaved edits and displays its error.


Writes require `projects:write` and the scope that exposes the current revision:
`clients:read` for create, or `projects:read` for update, archive and member access.
Both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_PROJECT_WRITES_ENABLED` must be enabled. An idempotency key, the current
`expected_version`, and literal user confirmation (`confirm: true`) are required. Project
changes affect portal descriptions, visibility, and access, so the MCP server
instructs the caller to confirm these decisions. Task/project roles alone do
not grant project administration. `tasks:write` and `clients:write` do not
substitute for `projects:write`; the new consent text names project and member
administration explicitly. Read listings require `projects:read`; member
listings additionally require a workspace owner/admin.

Create takes a client `company_id`, `name`, and optional `description`,
`repository`, and `is_visible_to_client` (default `true`). Repository references
are normalized to the same canonical form as the browser form. Update is a
patch: omitted facts remain unchanged, while explicit `null` clears description
or repository. Status is `active` or `archived`; archiving preserves tasks, time,
and financial history. A project cannot be moved between clients or workspaces.

The member listing shows existing workspace members who can receive a grant,
including members with role `none`. It returns public `user_id`, name, and
project role; it does not expose email or account search. Pages default to 25
and cap at 100, with cursors bound to workspace and project. Set `role` to
`owner`, `manager`, `contributor`, or `viewer`, or `none` to remove access.
Workspace owners/admins already have workspace-wide access and cannot receive
explicit project grants. A nonmember or foreign user is refused.

Browser and API mutations share `WorkspaceProjectMutationAction`, including
tenant-scoped locking, repository normalization, and access checks. API writes
use `AgentMutationExecutor` for receipts and audit; retries recheck current
manager authorization. Every access change advances the project's revision, so
a stale grant or stale project edit is refused. The browser retains its existing
redirects and numeric form revision error.


### Proposals

The Operations proposal draft/send controls, agreement activation and billing
schedule creation/generation call the canonical REST operations through the
signed-in browser session. Agreement rename and terms editing use
`agreements.update`. The server supplies finished URLs and current opaque
versions; viewer controls are absent. Activation, sending and generation require
explicit confirmation. An uncertain POST response retains the original payload
and receipt key for a safe retry; a stale revision refreshes the current page.
The former seven engagement/schedule mutation routes have been retired. Portal
proposal acceptance and interactive agreement signatures keep their browser
flows.


`proposals.list` and `proposals.get` require `proposals:read`. Managers can read
workspace proposals; portal recipients can read only sent or accepted proposals
in their own company and project grants. Company-wide proposals remain visible
to company portal members. Responses include bounded item details, minor-unit
amounts, an opaque version and a server-generated browser URL.

`proposals.create` and `proposals.send` require `proposals:write` and a workspace
owner/admin. Create locks the company and checks its `expected_version`; optional
project attribution must belong to that company and workspace. It creates a
draft. Send requires the current proposal version and explicit `confirm: true`;
it marks the proposal sent and publishes it in the portal, without sending email.

`proposals.accept` requires `proposals:accept`, the current proposal version,
`confirm: true` and an explicit `signer_name` (optional `signer_title`). Authorized
portal recipients can accept within their project grants; owner/admins can
record offline acceptance. Acceptance signs and activates the resulting agreement.
Expired proposals and unsafe agreement links are refused by the same workflow
used by the website. Direct agreement signing remains an interactive web action.

All proposal writes require a stable idempotency key and both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_PROPOSAL_WRITES_ENABLED`. Identical
retries reuse the result and recheck current authorization. A changed body with
an old key or a stale version returns a conflict. Send and accept lock the
proposal before checking its version; acceptance then locks its company before
visibility reads or agreement decisions can establish a database snapshot.
### Workspace and generic file parity (#386, #382)

| Capability | Shared website action/query | Scope and deployment gate |
| --- | --- | --- |
| `workspaces.create` | `CreateWorkspace`; actor/client/key reservation before tenant-owned mutation receipt | `workspaces:create`; workflow + workspace write flags |
| `search` | `WorkspaceSearch::forWorkspace` | `identity:read` plus each result kind's read scope; explicit workspace |
| `attachments.list`, `attachments.get`, `attachments.download_url` | `AttachmentRecordResolver`, `AttachmentStorageService` | `files:read`; owner/admin; generic records only |
| `attachments.upload_url`, REST multipart `attachments.upload` | `AttachmentAction::store`, `AttachmentStorageService` | `files:write`; workflow + file write flags; owner/admin; locked parent version |
| `attachments.delete` | `AttachmentAction::delete`, existing retention lifecycle | `files:write`; same flags/role; file version + explicit confirmation |

MCP prepares the upload URL; it never accepts file bytes as a tool argument.
The caller posts multipart bytes with the same bearer credential and an
`Idempotency-Key` header. Download and upload URLs expire in ten minutes and
require current scopes and membership when followed. Expense receipt routes
remain separate. The focused `AgentWorkspaceMiscTest` covers both transports,
replay/digest conflicts, rollback compensation, versions, tenant isolation,
scope/role/flag refusals, URL expiry and lock ordering.

`context.get` includes `withheld_tools`, from the same `Availability`
evaluation as `tools/list`, with `deployment_disabled`, `scope_not_granted`
(and its missing `scope`), or `role`. Use this metadata to explain absent capabilities before suggesting
website work. Invoice descriptions never promise payment recording unless the
connection actually exposes the tool. A new cutover is a flag in `AgentDeploymentFlags`, named by each operation it
withholds; nothing else needs to change for it to reach discovery, context and
REST.

### Square read-only pass-through (#438)

Some invoices are also issued and collected in Square. Agents cannot reach
Square, so SVC exposes bounded, read-only pass-through reads that let an agent
find the Square record to link to an SVC record. Linking itself is a separate
SVC operation. SVC remains the system of record and never creates, publishes,
cancels, refunds or marks anything paid in Square.

| Capability | REST (`/api/v1/workspaces/{workspace}/square/...`) | Notes |
| --- | --- | --- |
| `square.locations.list` | `GET locations` | Square invoice reads take a `location_id` from here |
| `square.customers.list` | `GET customers` | `limit` 1–100, `cursor` |
| `square.customers.search` | `GET customers/search` | `email` and/or `phone`, `match` `fuzzy` (default) or `exact`; Square has no name search |
| `square.customers.get` | `GET customers/{customer}` | |
| `square.invoices.list` | `GET invoices` | `location_id` required; `limit` 1–50, `cursor` |
| `square.invoices.search` | `GET invoices/search` | `location_id` required, optional `customer_id` (Square accepts one of each), `sort_order` |
| `square.invoices.get` | `GET invoices/{invoice}` | |
| `square.orders.get` | `GET orders/{order}` | The order behind an invoice: line items, totals, tenders |
| `square.payments.list` | `GET payments` | `begin_time`/`end_time` (RFC 3339), `location_id`, `sort_order`; `limit` 1–50, `cursor` |
| `square.payments.get` | `GET payments/{payment}` | |

- **Gate.** `billing:read` and a workspace owner/admin role, as for the other
  billing reads; no new scope. The feature is off - every tool withheld as
  `deployment_disabled` and every route answering 404 - until the server is
  configured with a Square access token and bound to exactly one workspace.
  Any other workspace answers "Square is not connected to this workspace."
- **Read-only by construction.** `SquareClient` can only send a request built
  from a `SquareEndpoint` case. The allow-list is ten reads: GETs plus the two
  `/search` POSTs Square uses for reads. `SquareReadOnlyAllowListTest`
  enumerates both the cases and the client's public methods. The access
  token cannot be scope-limited, so this allow-list is the only enforcement.
- **Shapes.** Responses are allow-listed projections (`SquarePresenter`), not
  Square's raw objects. Card fingerprints, BINs, expiry dates, stored-card ids,
  receipt links, version tokens, addresses and notes are never returned; a
  stored card on an invoice appears only as `card_on_file`. Money is
  `{amount, currency}` in minor units. Every invoice carries `series_key` - the
  six-digit prefix every invoice of a recurring `NNNNNN-R-NNNN` series shares
  - and `series_occurrence` (the suffix); both are null for a one-off. A
  dashboard series has no series id in Square. An invoice that Square's
  Subscriptions API generated also carries its `subscription_id`.
- **Bounds.** Page sizes are capped as listed, and all of an account's
  locations (Square allows 300) come back in one answer. Nothing is cut
  silently. A page larger than its cap is refused (502), since Square's cursor
  would skip what was dropped. Each nested list (payment requests, reminders,
  line items, tenders, processing fees, refund ids) has a `<list>_truncated`
  flag beside it. Square cursors pass through opaquely as `meta.next_cursor`. Every workspace has a shared per-minute
  budget of Square reads across REST and MCP (`SQUARE_RATE_LIMIT_PER_MINUTE`,
  default 60); over it, the answer is 429 with `Retry-After`.
- **Errors.** The client sends the pinned `Square-Version` on every call. It
  uses bounded timeouts, never follows a redirect, and retries only these
  reads on connection failures, 429 or 5xx, with doubling backoff. Failures
  become a fixed message plus Square's error category and code:
  - a missing record is 404;
  - a refused request is 422;
  - a refused credential is 502 for an operator;
  - Square's rate limit is 429;
  - an unreachable Square is 503.

  The access token, request headers and Square's free-text detail never
  appear in a response, exception or log line. A response that would contain
  the token is refused.

Configuration (server environment only; nothing is stored in the database):

| Variable | Meaning |
| --- | --- |
| `SQUARE_ACCESS_TOKEN` | Square access token; empty keeps the feature off |
| `SQUARE_ENVIRONMENT` | `production` (default) or `sandbox`; anything else keeps it off |
| `SQUARE_APPLICATION_ID` | Square application id (recorded for later webhook work) |
| `SQUARE_WORKSPACE` | Public id of the one workspace the Square account belongs to |
| `SQUARE_VERSION` | Pinned `Square-Version` (default `2026-09-16`) |
| `SQUARE_TIMEOUT_SECONDS`, `SQUARE_CONNECT_TIMEOUT_SECONDS` | Request and connect timeouts (defaults 10 and 5, bounded) |
| `SQUARE_MAX_ATTEMPTS`, `SQUARE_RETRY_BASE_MILLISECONDS` | Attempts for a retryable read (default 3, at most 5) and the first backoff (default 250 ms) |
| `SQUARE_RATE_LIMIT_PER_MINUTE` | Per-workspace pass-through budget (default 60) |

### Square mirror (#438)

SVC keeps a read-only mirror of the connected Square account, so the explore
screen, series and link suggestions never wait on Square. Square stays
the source of truth; the mirror is a cache that a sync refreshes.

| Operation | REST (`/api/v1/workspaces/{workspace}/square/…`) | Notes |
| --- | --- | --- |
| `square.sync` | `POST sync` | `billing:write`, `Idempotency-Key`; refresh the mirror now and return the run |
| `invoices.square_reconcile` | `POST /workspaces/{workspace}/invoices/{invoice}/square-reconcile` | `billing:write`, `billing:read`, `Idempotency-Key`, empty body; reconcile one linked invoice's payments and refunds now and return its Square state; 20 per workspace per ten minutes |
| `square.mirror.invoices.list` | `GET mirror/invoices` | `location_id`, `status`, `customer_id`, `series_key`, `due_from`/`due_to`; up to 100 per page, opaque cursor |
| `square.series.list` | `GET series` | Recurring series by `series_key`, optionally for one `location_id` or `customer_id`; up to 50 per page |
| `square.series.get` | `GET series/{series}` | One series with every mirrored member by occurrence |
| `square.customers.unlinked` | `GET customers/unlinked` | Customers with invoices and no confirmed mapping; balances, series keys, optional `with_open_balance`; up to 500, with explicit truncation |
| `square.import_customer` | `POST customers/{customer}/import` | `billing:write`, `Idempotency-Key`, `confirm: true`; create one client and optionally a draft agreement for `series_key` |

`invoices.square_candidates` is `GET /api/v1/workspaces/{workspace}/invoices/{invoice}/square-candidates`
with `billing:read`. It ranks the confirmed customer's invoices and an agreement's
explicit Square series, suggests at 70 points, and caps scores at 100. The amount,
period, cadence, due date and customer signals explain the ranking. Suggestions
never link anything; tied top suggestions are marked ambiguous. Canceled invoices
are omitted unless `include_canceled=true`; other currencies and invoices linked
elsewhere are excluded. `limit` is at most 25 and a bounded pool reports truncation.

Import is explicit per customer and uses only the mirror. It creates a client,
confirms the customer mapping and records provenance together. `series_key` may
also create a draft agreement with the mirrored cadence, currency and amount,
marked billed in Square in the series' one location; a series billed from more
than one location is refused with 422. The agreement awaits human review and activation.
Already mapped customers or assigned series return 409; retries with the same
idempotency key return the same records. Optional `company_name`, `billing_email`
and `agreement_title` override the defaults. No existing record is edited, so
there is no `expected_version` to supply. Import requires both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_CLIENT_WRITES_ENABLED`.

Operator and client invoice pages offer **View or pay in Square** only for an
active link whose mirrored `public_url` is an absolute HTTPS address on an
allowed Square host. SVC never constructs that address from an identifier.
The client portal resolves the invoice through its existing company and
workspace visibility checks before looking up the hosted page.

The reads are `billing:read`, for workspace managers of the connected
workspace, and never reach Square: each list's `meta` carries
`last_synced_at` (the last completed run) and `last_run` (the latest run,
however it ended), so a reader can tell how fresh the answer is. Workspace
managers also get a **Square invoices** screen (account menu) with the same
filters, the series, the sync status and a **Sync now** control that calls
`square.sync`; it links each invoice to its Square `public_url`.

- **What is mirrored.** Locations; customers (the allow-listed fields of
  `square.customers.get`); and every location's invoices with their number,
  `series_key` and occurrence, status, currency, total, paid and next amounts,
  due, scheduled and service dates, Square's payment requests with their
  reminder schedule, `public_url`, autopay, and - for paid invoices - the
  Square payment ids from the invoice's order. Records pass through the same
  allow-listed projection as the pass-through reads before anything is stored.
- **When.** `svc:square:sync` runs every 15 minutes (never more often) without
  overlapping, and does nothing while Square is not configured.
  `square.sync` runs one now; a workspace gets a few manual syncs per ten
  minutes, and repeating an idempotency key returns the same run without
  reading Square again.
- **How.** Square has no "changed since" filter on its invoice or customer
  lists, so each run pages through them whole (bounded: a run that stops early
  says `truncated`). A fingerprint of each record decides whether its row is
  written, so an unchanged account costs reads and no writes; the run records
  the newest Square `updated_at` it saw as its `high_water_mark`. Rows are
  unique per Square id and an older Square version never replaces a newer
  one, so two syncs at once leave one row per Square object. The
  `Square-Version` sent is recorded on the run and logged with every page.
- **Series.** A series is the invoices sharing a `series_key`. Its cadence
  (`weekly`, `monthly`, `quarterly`, `semi_annual`, `annual`, `irregular` or
  `unknown`) is the median spacing of due dates per occurrence, so a skipped
  month does not change it; occurrence numbers no mirrored invoice carries are
  listed as `missing_occurrences`; `amount` is the latest billed member's and
  `amount_varies` flags a price change; `open_amount` sums unpaid and
  partially paid members (scheduled ones are not issued yet). Canceled, draft
  and failed members set neither amount nor cadence.
- **Runs.** A run ends `completed`, or `failed` with an `error_code` and a
  readable `error` (Square unavailable, rate limited, an unreadable response,
  a refused credential, or a run a dead process abandoned). A failed run is a
  200 answer, not an error: the mirror keeps what earlier runs stored.

### Agreement handovers

Agreement succession follows `(workspace, company, project)` and `starts_on`, then
internal id for same-day replacements. Draft agreements do not replace active terms.
Generation refuses a period that sells coverage on or after its successor starts
(422). Cadence generation still uses the existing `ends_on` proration; edit the
outgoing terms to end the day before the successor when appropriate. Schedule
line templates charge a fixed amount and do not acquire automatic proration, so
their period must end before the successor. No automatic truncation is introduced.

`billing.preflight_schedule_generation` counts these as the schedule defect
`agreement_handover_overlap`, using the same coverage policy as generation.
`billing_audit.stale_and_missing.agreement_handovers` reports issued cadence
invoice coverage crossing a handover, with a total count and at most 100 rows.
Stored dates are evidence to review: a row may already have been prorated, and
this audit neither infers a financial overcharge nor rewrites an issued invoice.
The operator equivalent is `svc:billing:audit-handovers --workspace=<public-id>
--format=json`; omitting the workspace audits all tenants as an operator.

### Deferred backlog settlement

`deferred_backlog.preview` is a manager-only `billing:read` operation, served by
`GET /api/v1/workspaces/{workspace}/agreements/{agreement}/deferred-backlog/preview`.
Supply `hours` (whole minutes expressed as hours) or `amount` (gross minor units),
optionally `round_up`, `discount_amount` and `discount_reason`. It returns public
entry IDs, minutes/hours, currency, gross/discount/net amounts and `backlog_version`.
Selection and pricing are the termination candidate set and grouped pricing rule;
it does not consume any retainer capacity. Entryless carried ledger balances are
not selectable.

`invoices.create_deferred_buydown` requires `billing:write`, `billing:read`, manager
permission, the invoice write cutover, `Idempotency-Key`, and the same selection
arguments plus `expected_backlog_version`. Its POST path replaces `/preview` with
`/invoices`. It creates a reserved draft only (201); stale shelves are 409.
Idempotent replay returns the existing draft. Ordinary issue/send confirmations
and Square collection ownership still apply. Void/discard releases the work,
while unpaid issued work remains billed. The dedicated invoice kind is
`deferred_buydown`; its system-only line type was reserved in #375.

`agreements.create` and `agreements.update` also accept the boolean
`show_deferred_backlog_to_client`, and agreement reads report it. It defaults to
false. Opting in shows the backlog on the existing visible agreement portal page,
without allowing a client to create a settlement or exposing internal issue detail.

The additive `finance.processor_movements.list` read tool shares the REST/CLI
change-feed implementation and existing `finance:read` scope. Its workspace
capability is `finance:processor_movements:read`. It always uses cursor pagination
and accepts only immutable provider, kind, payment and occurrence-date filters.
Its closed signed-money records contain no account keys or raw source objects;
see [signed processor movements](finance-api.md#signed-processor-movements) for
the field list, signs and inclusive watermark protocol.

`finance.reconciliations.acknowledge` is the explicit manager-only write for an
allocation that is still correct after a processor review. It requires the
existing `finance:reconcile` scope, `confirm: true`, `expected_payment_version`,
`expected_review_reason` and `expected_review_flagged_at`, alongside the natural
allocation path arguments. It only clears the exact current review; stale or
already-cleared proof returns a conflict requiring a fresh read. There is no
idempotency key or replay receipt. See [allocation review acknowledgement](finance-api.md#acknowledge-an-allocation-review)
for the effect-idempotent retry contract and unchanged money/activity rules.

### Native collaboration

`collaboration.topics.list/get/create/update/delete` and
`collaboration.comments.list/create/update/delete` expose native notes,
discussions and flat task/topic comments through the same REST actions as MCP.
They never activate imported history. Both topic kinds accept comments.
`collaboration:read` permits reads; creates need `collaboration:write`, and
versioned writes also need `collaboration:read`. Agent writes require both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_COLLABORATION_WRITES_ENABLED` (the latter
defaults false). Signed-in first-party web sessions bypass these deployment
switches. The website's create, edit, sharing and removal forms use these
session-authenticated API operations; the six parallel browser writers are
retired. New topics and comments follow the server's finished `web_url`,
including the comment focus and page. Drafts survive refused or uncertain
requests, and retries retain their receipt key until authoritative content
loads. Native web and CLI use their current staff permissions independently.
A website session with internal membership, including a dual staff identity,
retains Internal authority; a portal-only session keeps scoped Portal reads
and cannot write.

The server chooses the audience: an agent actor with any current portal
membership in the workspace, including a dual staff identity, receives the
portal view and cannot write. Portal reads require the exact company/project
grants and each ancestor's disclosure. Client replies remain disabled.
Internal authors may edit their own text with current access; managers publish
and moderate. Authors, kind and parent lineage are immutable. Text is plain
text. Deletes require `confirm: true` and an `expected_version`, create
tombstones, and preserve attribution. Receipts recheck current locked authority
on replay. No collaboration operation emails anyone.

Bodies allow 50,000 Unicode characters. Transport ceilings stay bounded and
are global: request 1 MiB (`AGENT_API_MCP_MAX_BODY_BYTES`), capability result
2 MiB (`AGENT_API_MCP_MAX_RESULT_BYTES`), complete response 4 MiB
(`AGENT_API_MCP_MAX_RESPONSE_BODY_BYTES`). The latter accommodates both MCP text
and structured representations of a valid worst-case Unicode body. Comment
lists default to one row, with `limit` from 1 to 30; native UI pages retain 30.
A list containing several maximal bodies can exceed the result ceiling: reduce
`limit` and follow `next_href`. Comment `web_url` uses an authorized comment UUID
focus, so API page size never changes the native permalink destination.

Covered-payment CLI: `svc:square:covered-payments <workspace UUID> <invoice UUID>
<covered Square invoice id> --actor=<manager UUID> --format=json` reviews without
recording. To record the selected safe payment, supply `--apply --confirm`,
`--payment`, `--expected-version`, `--reviewed-version` and `--idempotency-key`
from that review. The same canonical operation owns validation, provider dedup,
receipts and audit for CLI, REST, MCP and the signed-in website.
