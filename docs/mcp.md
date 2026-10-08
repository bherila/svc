# SVC MCP public contract

This document records the MCP surface shipped by SVC and the privacy-safe read
general-availability work tracked by
[#202](https://github.com/bherila/svc/issues/202). The feature tests in
`tests/Feature/Mcp` are the executable compatibility contract.

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
- `bherila/auth-laravel` is locked to signed `v0.12.0`
  (`cda94048c952eaaf10726be2c71d6f27cc8c6061`). Its opt-in Passport
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
  `AGENT_API_MCP_MAX_BODY_BYTES` (262144 by default); capability results are
  bounded after serialization by `AGENT_API_MCP_MAX_RESULT_BYTES` (also 262144
  by default), while complete non-streamed transport responses are independently capped by
  `AGENT_API_MCP_MAX_RESPONSE_BODY_BYTES` (1048576 by default). Responses are
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
continues enforcing already-issued credentials. Every protected `/api/v1` route
sets the configured expected resource before Passport authentication; a bound
token therefore fails closed on an unmarked or differently marked Passport
route. Dynamic registrations persist their exact scope ceiling. A legacy
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

## Public capability inventory

With all read scopes, discovery exposes these read-only tools, in this order:

`context.get`, `operations.summary`, `projects.list`, `projects.get`,
`tasks.list`, `tasks.get`, `time_entries.list`, `expenses.list`, `invoices.list`, and
`invoices.get`, `agreements.list`, `agreements.get`,
`clients.list`, `clients.get`,
`billing_schedules.list`, `billing_schedules.get`, `capacity_ledger.get`,
`billing.audit_unplaceable_invoices`,
`billing.audit_undated_collectible_invoices`, and
`billing.audit_missing_billed_overage`, and
`billing.audit_opening_rollover`.

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
scope. They and `tasks.create` / `tasks.update` use tenant-scoped application
actions directly. For `time_entries.log`, supplying `client_visible_description`
without `is_visible_to_client` makes the entry client-visible by default; set
`is_visible_to_client: false` to stage the client text privately. The broader
write flag also retains a legacy compatibility
registration for `time_entries.approve`. The eight invoice registrations -
`invoices.create_draft`, `invoices.update_draft`, `invoices.update_details`,
`invoices.discard_draft`, `invoices.issue`, `invoices.send`, `invoices.correct`
and `invoices.void` - require *both* that
flag and `AGENT_API_INVOICE_WRITES_ENABLED`, so enabling the broader flag alone
registers `time_entries.approve` and no invoice tool. `invoices.issue` now runs
through the tenant-scoped `IssueInvoiceAction` (see
[Issuing an invoice](#issuing-an-invoice)); the others still enter the
versioned Agent API through `InternalAgentApiTransport`. They are disabled by
default and are not a PR 6/7 production-ready write path: approval, invoice,
and externally consequential workflows require their own application-action
migration plus the approved confirmation design before general availability.

`time_entries.log` accepts `approve: true` to approve the logged entries in
the same transaction and under the same idempotency receipt, so a batch is
either logged and approved or not written at all. It carries every gate
`time_entries.approve` does - `AGENT_API_WRITES_ENABLED`, the `time:approve`
scope, and the approver role on each project - and rechecks the scope, flag
and role before replaying a receipt. The flag is part of the request digest,
so the same key with and without it is a conflict. When the token also holds
`time:read` and `time_entries.list` is not switched off, the tool returns its
rows through `AgentReadService`, exactly as `time_entries.list` presents them,
so an agent needs no follow-up list call. That includes the list's rule that
rates are shown to workspace owners and admins only, so a project-role manager
who approves sees the status but not the rate.

The tool catalog is `AgentMcpToolCatalog`; `AgentMcpInputSchemaFactory` and
`AgentMcpOutputSchemaFactory` derive public schemas from the checked-in
OpenAPI response catalog. Inputs are validated before dispatch, output is
validated after dispatch, and failures are mapped to safe MCP errors. Read
tools and the REST controller both use `AgentReadService`, the single
tenant-scoped query/presentation boundary. Read tools and the direct
task/draft-time write actions do not invoke controllers or internal HTTP
routes, and no MCP capability exposes Eloquent models. The disabled legacy
approval/invoice write registrations above - every one except `invoices.issue` -
are the explicit exception pending their migration; they must not be used as a
pattern for new MCP work.

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
| `capacity_ledger.get`                             | Time-sheet capacity display and billing ledger; `AgentCapacityLedgerReadService` over `InvoiceLedgerBuilder`              | `billing:read`; manager; workspace-scoped agreement query                           | 1–60 trailing months; allowlisted rows include `signed_available_hours` (current and carried unused capacity positive, deficit negative); inaccessible agreement is not found   | `mcp.read.capacity_ledger`; ledger, schema, and cross-workspace tests                                                 |
| `billing.audit_unplaceable_invoices`              | `svc:billing:audit-unplaceable-invoices`; `AgentBillingAuditReadService` over `UnplaceableInvoiceAuditor`                 | `billing:read`; manager; workspace-scoped audit                                     | No record identifiers or raw amounts beyond aggregate, per-workspace totals; no pagination                                                                                      | `mcp.read.billing.audit_unplaceable_invoices`; aggregate/redaction and authorization tests                            |
| `billing.audit_undated_collectible_invoices`      | `svc:billing:audit-undated-collectible-invoices`; `AgentBillingAuditReadService` over `UndatedCollectibleInvoiceAuditor`  | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts and bounded per-currency integer balances only; no record identifiers                                                                                          | `mcp.read.billing.audit_undated_collectible_invoices`; aggregate/redaction and authorization tests                    |
| `billing.audit_missing_billed_overage`            | `svc:billing:audit-missing-billed-overage`; `AgentBillingAuditReadService` over `MissingBilledOverageAuditor`             | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts only; no record identifiers or raw models                                                                                                                      | `mcp.read.billing.audit_missing_billed_overage`; aggregate/redaction and authorization tests                          |
| `billing.audit_opening_rollover`                  | `svc:billing:audit-opening-rollover`; `AgentBillingAuditReadService` over the shared `OpeningRolloverAuditor`             | `billing:read`; manager; workspace-scoped audit                                     | Aggregate counts and capacity minutes only; immutable DTO cannot carry record identifiers                                                                                       | `mcp.read.billing.audit_opening_rollover`; CLI parity, aggregate/redaction, authorization, and tenant-isolation tests |
| Duplicate-time diagnostics                        | No checked-in workflow/service establishes the grouping key                                                               | Deferred from read GA                                                               | Not implemented: a canonical application query, grouping contract, bounded DTO, and privacy review must exist before MCP can adapt it                                           | No flag or release cohort                                                                                             |
| Session workspace selection                       | Existing clients select an authorized workspace on every scoped call                                                      | Accepted v1 compatibility contract                                                  | Credential/client sessions are bound; every selector is active-membership scoped and reauthorized per call. Session pinning requires a future versioned migration.              | Context, membership-removal, credential-binding, and multi-workspace compatibility tests                              |

## Compatibility and current coverage

Existing clients depend on the public tool and prompt names, dotted naming,
tool ordering, schema shapes, safe error messages, OAuth/PKCE discovery, and
credential-bound sessions. Additive capabilities must not rename, remove, or
weaken these contracts. A future replacement must retain an alias and an
explicit deprecation window.

Current coverage includes initialization, scope-filtered discovery, prompts,
tool schema closure, input and output validation, REST parity, credential
session isolation, route authentication, origin handling, optimistic versions,
idempotency, tenant isolation, persisted credential validation, request
context selection, and safe time-entry mutations. `McpCapabilityRegistry`
now drives tool discovery and registration, including required scope, policy
reference, schemas, workspace requirement, rate-limit/audit classification,
and global/per-capability configuration kill switches. Cursor envelopes are
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
| Signed monthly capacity ledger                 | Implemented; manager-only bounded `capacity_ledger.get` over `InvoiceLedgerBuilder`                                                                      |
| Duplicate-time diagnostics                     | Deferred: no checked-in canonical query or privacy-reviewed grouping contract exists. It will not be inferred from raw time rows.                        |
| Read-only `svc:billing:audit-*` operations     | Implemented as four aggregate-only, manager-scoped tools, including `billing.audit_opening_rollover`, over the same auditors used by their CLI workflows |
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
directly, creating one from a proposal, scoping one to a project, and any
removal of a row. `agreements.create` makes a draft that bills nothing until
`agreements.activate`, which refuses an overlap with another active agreement for
the same client.

## Received-payment bookkeeping

`payments.list` requires `payments:read` and an explicit `invoice_id` or
`company_id`. It follows invoice visibility, defaults to 25 rows and caps pages
at 100 using an opaque cursor. Portal readers see only payments on their visible
issued, partially paid or paid invoices. Project-scoped portal users must be granted every project on an invoice; mixed-grant and unattributed invoices are withheld. References are withheld from portal readers.
Private notes, processor identifiers and finance reconciliation records are never
part of this response. Each row carries an opaque `version`, which
`payments.correct` requires.

`payments.record` records money already received; it does not collect money,
create a Stripe intent, issue a refund, or change an existing payment's status.
It requires an owner/admin, the `payments:record` scope, and both
`AGENT_API_WRITES_ENABLED=true` and `AGENT_API_PAYMENT_WRITES_ENABLED=true`.
The payment flag defaults to false and the outer flag remains the emergency stop.
The MCP catalog, REST route and capability inventory use the same cutover.

Provide the invoice ID, positive integer amount in minor units, matching uppercase
currency, actual `received_on` payment date (`Y-m-d`) and method (at most 40
characters, the width of its column) explicitly; never
infer an amount or substitute the invoice date. Reference is optional (for example,
cash need not have one). A mandatory idempotency key protects retries; reusing a
key with another payload is refused. The existing service refuses overpayment and
draft/void invoices, accepts dates within its workspace-calendar two-year window,
and records a succeeded payment. An identical retry remains safe after full
settlement and when the original date ages below the creation window.

REST uses GET/POST `/api/v1/workspaces/{workspace}/payments`, with the same scopes
and roles; POST takes the key in `Idempotency-Key`. The separate finance API
`/invoice-payments` lists reconciliation data and is not this recording endpoint.
The CLI `svc:billing:payment` already records payments through the same lifecycle
service. Browser URLs remain the route for initiating a customer payment.

### Correcting a recorded payment

`payments.correct` corrects a payment's **descriptive** fields - `method`,
`reference` and `received_on` - and nothing else. It is gated exactly as
`payments.record` is: the `payments:record` scope, an owner/admin, both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_PAYMENT_WRITES_ENABLED`, and the MCP
kill switch, each rechecked before a receipt is replayed. REST is
`PATCH /api/v1/workspaces/{workspace}/payments/{payment}` with the key in
`Idempotency-Key`.

- `expected_version` is required: the `version` a `payments.list` row carries.
  Any write to the payment - a status change, a refund, another correction -
  moves it, and a stale one is refused with a conflict before anything is
  written. Re-read and decide again.
- `reason` is required (at most 500 characters) and kept in the client history.
- Name at least one of `method`, `reference` and `received_on`; the published
  schema and the tool input schema both require it. Omitted fields are
  unchanged; an explicit `null` reference clears it.
  `received_on` has the same `Y-m-d`, workspace-calendar two-year window as
  recording, and `method` the same 40-character bound as its column.
- Amount, currency, status, refunded amount, the invoice and processor fields
  are refused. Money is corrected by the operations that preserve history:
  cancel and re-record, or record a refund.
- `notes` is not offered to agents. `payments.list` never returns it, and a field
  an agent cannot read back is one it cannot check its own write against; the
  operator command below accepts it.
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


### Changing a due date

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

### Issuing an invoice

`invoices.issue` requires `billing:deliver`, a workspace owner/admin, both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_INVOICE_WRITES_ENABLED`, and the
`invoices.issue` MCP kill switch. It takes the draft's current
`expected_version` and `confirm: true`, and runs `IssueInvoiceAction`, the same
tenant-scoped action the REST route now uses, inside `AgentMutationExecutor`:
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
the plain mutation shape (`InvoiceIssueResponse`). REST returns the mutation
shape. The REST route accepts the same `payment` object and applies the same
gates.

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
| `invoices.pdf` | `GET /invoices/{invoice}/pdf-link` | `billing:read` |
| `billing_audit.stale_and_missing` | `GET /billing-audits/stale-and-missing` | `billing:read` |

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
send. Repeating the same key replays the result; another key for a period with a
non-void invoice is refused. No automatic cadence-draft generation job is
deployed: inspect the missing-period audit and request a period deliberately.

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

The manager-only stale/missing audit counts drafts due before today and sums
minor-unit balances separately by currency. It also counts started, active
recurring agreements without a non-void cadence invoice for the current
retainer period. Each identifier list contains at most 100 entries; counts and
balances remain complete, and truncation flags say when more rows exist.

The web payment form, `payments.record`, and `invoices.issue` with a payment
share `RecordReceivedPayment` and immutable `ReceivedPaymentData` facts.
Transport adapters retain their own permission and receipt checks. Standalone
agent payment keys retain their historical namespace, and issue-with-payment
keys remain separate; existing receipts continue to replay. The web form keeps
its optional date, status and bookkeeping fields, while agent recording remains
limited to succeeded money already received. No processor charge is initiated.
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
