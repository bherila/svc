# SVC Agent API and MCP product contract

SVC exposes its agent surface through a versioned REST API and a remote Streamable
HTTP MCP endpoint at `/api/v1/mcp`. MCP read tools adapt the same application
queries as REST, and MCP write tools run the REST operation itself - its
authorization, validation, receipts and refusals, including payment bookkeeping.
Transport adapters do not query Eloquent models directly. Invoice and payment write registrations remain disabled by default
behind their nested cutovers.

## Categories and exclusions

The agent surface covers workspace identity and creation, operational summaries and
search; clients, contacts and portal access; projects and member access; tasks,
organization and planning; time; agreements and proposals; invoices, billing
schedules, capacity and billing audits; received-payment bookkeeping and finance
reconciliation; expenses and recurrence; native collaboration and files; and
configured Square reads, mirroring and SVC-side links. Each family below links to
the generated capability inventory rather than repeating its operation list.

Project deletion remains website-only. Workspace settings, workspace membership
administration and deletion, credential issuance and OAuth-app registration,
interactive agreement signing and portal proposal signing, integration setup,
and initiating a customer payment also remain browser workflows; the catalog
does not supply general CRUD for every model. Proposal acceptance may separately
record confirmed offline acceptance through the agent surface. Operator repair,
retention and deployment commands are not general agent operations.

Payment initiation and collection, executing processor refunds, full card numbers, card-on-file and payment-method credentials, and processor API credentials are out of scope. Bookkeeping operations may record money already received or returned, and never move money. Square read and link operations expose only the allowlisted record identifiers and the card summary (brand, type and last four digits) documented in `docs/mcp.md`.

The card summary is `card_brand`, `card_type` and `last_4`; an invoice payment
request reduces its card credential to the boolean `card_on_file`. Invoice
responses contain a role-authorized browser URL so a user can continue a
payment flow in the website.

## Roles

Workspace `owner` and `admin` retain full workspace access. Internal users can be
assigned to a project as `owner`, `manager`, `contributor`, or `viewer`. Owners and
managers can manage tasks and approve project time. Contributors can view assigned
projects and manage only their own draft time. Viewers cannot read team time or log
time. Client-company identities see only records permitted by current portal visibility
and project scope. They cannot use staff mutation authority; confirmed proposal
acceptance is a separately scoped exception for an authorized recipient.

## Agent operations

The [generated public capability inventory](mcp.md#public-capability-inventory)
is the authoritative operation list, including bindings, schemas, scopes and gates.
The resource families have these responsibilities:

**`context`:** Identity, authorized workspaces, capabilities and withheld-tool reasons. See the [inventory](mcp.md#public-capability-inventory).

**`operations`:** Role- and scope-filtered workspace operational summaries. See the [inventory](mcp.md#public-capability-inventory).

**`workspaces`:** Creation of a workspace owned by the caller, with its own creation scope and nested cutover. See the [inventory](mcp.md#public-capability-inventory).

**`search`:** Bounded, tenant-selected resource search filtered by each resource’s read scope. See the [inventory](mcp.md#public-capability-inventory).

**`clients`:** Client companies, contacts, portal access and invitations, with manager authorization and applicable product switches. See the [inventory](mcp.md#public-capability-inventory).

**`companies`:** Client payment-reminder preferences and SVC-side Square customer mappings. See the [inventory](mcp.md#public-capability-inventory).

**`projects`:** Authorized project reads, manager creation, edits, archival and member access; deletion stays website-only. See the [inventory](mcp.md#public-capability-inventory).

**`tasks`:** Authorized task reads, eligible assignee lookup and task mutations under project roles. See the [inventory](mcp.md#public-capability-inventory).

**`task_organization`:** Lists, ordering, movement and dependencies under the task-organization product switch. See the [inventory](mcp.md#public-capability-inventory).

**`task_planning`:** Optional planning details and options under the planning product switch. See the [inventory](mcp.md#public-capability-inventory).

**`time_entries`:** Draft logging and edits, approval and withdrawal, with the independent time-write emergency cutoff. See the [inventory](mcp.md#public-capability-inventory).

**`agreements`:** Authorized agreement reads and manager drafting, activation, termination and Square-series mappings. See the [inventory](mcp.md#public-capability-inventory).

**`proposals`:** Commercial-term reads, manager drafting and publishing, and separately scoped confirmed acceptance. See the [inventory](mcp.md#public-capability-inventory).

**`invoices`:** Drafting, generation, time allocation, lifecycle transitions including reopening and reissuing, PDFs, delivery controls, reminders and SVC-side Square links. See the [inventory](mcp.md#public-capability-inventory).

**`billing_schedules`:** Manager recurring-billing configuration and confirmed generation under the invoice cutover. See the [inventory](mcp.md#public-capability-inventory).

**`capacity_ledger`:** The canonical agreement capacity ledger, including opening rollover as ongoing ledger behaviour. See the [inventory](mcp.md#public-capability-inventory).

**`deferred_backlog`:** Preview of an agreement’s deferred work and settlement facts. See the [inventory](mcp.md#public-capability-inventory).

**`billing`:** Aggregate billing data-quality audits and schedule-generation preflight. See the [inventory](mcp.md#public-capability-inventory).

**`billing_audit`:** The bounded stale-and-missing billing audit and its SVC/Square bookkeeping discrepancies. See the [inventory](mcp.md#public-capability-inventory).

**`payments`:** Received-payment reads and deliberate bookkeeping of receipts, corrections, status changes, returned money and reversal of an erroneous refund record; no provider transaction is initiated. See the [inventory](mcp.md#public-capability-inventory).

**`finance`:** Manager payment-allocation, payout and processor-movement reads and reconciliation writes under separate finance scopes. See the [inventory](mcp.md#public-capability-inventory).

**`expenses`:** Authorized expense recording, approval, withdrawal, draft deletion and dedicated receipt access. See the [inventory](mcp.md#public-capability-inventory).

**`expense_schedules`:** Recurring-expense reads, configuration and bounded generation under the expense cutover. See the [inventory](mcp.md#public-capability-inventory).

**`collaboration`:** Authorized native topics, comments and parent-scoped files, with staff write authority and portal read visibility. See the [inventory](mcp.md#public-capability-inventory).

**`attachments`:** Generic file metadata, bytes, upload preparation, upload, deletion and sharing under current parent and publisher authority. See the [inventory](mcp.md#public-capability-inventory).

**`square`:** Configured bounded provider reads and mirror/bootstrap operations; SVC remains the billing system of record and does not initiate provider payments. See the [inventory](mcp.md#public-capability-inventory).

**`oauth`:** REST-only revocation of the calling OAuth token family. See the [inventory](mcp.md#public-capability-inventory).

**`connections`:** REST-only revocation of the caller’s connection or application credentials. See the [inventory](mcp.md#public-capability-inventory).

**`resources`:** MCP context and agreement resources governed by their operation scopes and visibility. See the [inventory](mcp.md#public-capability-inventory).

**`prompts`:** MCP guided time and invoice workflows, visible only when their required operations are available. See the [inventory](mcp.md#public-capability-inventory).

Business resources use public UUIDs. Paginated lists use bounded cursors with a
maximum page size of 100; search and diagnostic projections have their own documented
bounds. Mutable records expose opaque versions; updates and lifecycle transitions
require the revisions declared in their request schemas. Persistent business
mutations use idempotency keys and the reservation-first transaction and audit
boundary. URL preparation creates no receipt, and credential revocation is a
separate idempotent cleanup operation. Cross-workspace identifiers resolve as 404.

The outer `AGENT_API_WRITES_ENABLED` flag defaults to false. Invoice, payment,
expense, client, collaboration, project, proposal, workspace and file write flags
also default to false and nest inside it. Time-entry writes instead use independent
`AGENT_API_TIME_ENTRY_WRITES_ENABLED`, default true, so time logging has its own
emergency cutoff. MCP defaults on; transport, group and per-tool switches filter
MCP independently of REST. Task organization, task planning, attachment sharing and
portal invitations have their own product switches, default off; first-party
sessions do not bypass product availability. Configured Square access is an
availability precondition, not a write cutover.

Tool discovery is filtered by the current access token's scopes. `context.get`
reports the intersection of token scope, the write cutover flag, and current role,
including per-project capabilities when `projects:read` permits disclosing project
IDs. `operations.summary` always returns the workspace ID and conditionally includes
project, time, and billing sections only when their respective read scopes are
present. Invoice amounts are grouped by currency and distinguish drafts,
collectible balances, and overdue balances; invoice-ready time excludes deferred,
nonbillable, unrated, and already allocated entries. With `billing:read`, a
workspace manager also receives `deferred_backlog`: the active or terminated
agreements with deferred work outstanding, largest first, each with the hours
and the amount the termination invoice would charge for them (see
[deferred billing](client-management/deferred-billing.md#deferred-backlog)).

Time follows `draft -> approved -> invoiced`. Approval snapshots the hourly rate and
currency from an agreement effective on the work date, including agreements now
paused, terminated or expired. Project-specific agreements win over company-wide
ones, then currently active agreements over other lifecycle states, then the most
recent start and record id. Billable approval fails
when no applicable rate exists unless the approving manager supplies an explicit
amount and currency; nonbillable time needs no rate. The manual draft endpoint accepts
manual lines and explicitly selected time-entry IDs; agreement-period generation
uses its separate ledger workflow. Selected entries must be
approved, billable, non-deferred, currency-compatible, and unallocated. Time-derived
line totals are rounded from integer minutes and hourly minor units; their four-place
hour quantity is display-only. Invoice issue, send, correct, reopen and void are distinct
confirmation-gated actions. Draft update is replace-all for the explicit time selection
and manual lines. Removing time, discarding a draft, or voiding an unpaid invoice
releases its allocation; issued linked time returns from `invoiced` to `approved`.
Invoice responses describe linked time as `reserved`, `consumed`, or `released`.
Invoice numbers follow `InvoiceNumberAllocator` inside invoice creation
transactions: it supports workspace-counter and client/period numbering, and
manual drafts may supply a workspace-unique number. Manual-line projects must belong to both the invoice
workspace and client company.

Client-visible time requires a non-empty, explicitly authored client-facing
description. Client reads never fall back to an internal time description, including
for legacy records that predate this invariant.

## Authorization

Users authenticate in a browser through the configured identity provider, then grant SVC-specific OAuth
access. SVC validates a token's resource audience, scopes, current account status, and
current workspace/project/company permissions for every request. The MCP endpoint
(`/api/v1/mcp`) and the REST API (`/api/v1`) are separate protected resources: an MCP
connection's token is requested for `/api/v1/mcp`, carries `mcp:use`, and is refused
by ordinary REST business routes; a REST token never carries `mcp:use` and is
refused by the MCP endpoint. Credential disposal is a deliberate exception:
`oauth.token.revoke` accepts the calling token’s configured resource, and
`connections.revoke` has its own MCP-resource binding. The scope catalog and descriptions live in `AgentApiScopes`, covering
identity, projects, collaboration, tasks, time, billing, expenses, payments,
finance, clients, proposals, workspace creation and files. The REST and MCP
resource ceilings are derived from that catalog; only MCP includes `mcp:use`.
Invoice lifecycle delivery uses `billing:deliver`, and received-payment
bookkeeping uses the separate `payments:record` grant.
Project detail embeds tasks only when the connection also has `tasks:read`.

OAuth public clients use authorization code plus rotating refresh tokens, `code`
response type, S256 PKCE, exact resource binding, and no token-endpoint client
authentication. Dynamic registration accepts only that profile and safe HTTPS or
loopback redirect URIs. Registrations are marked, their exact scope ceiling and
last token use are persisted,
and a daily retention command removes only stale registrations with no active access
or refresh credential. SVC configures the shared auth package's consent screen after
the configured provider login. Access-token JWT issuer, audience, and resource claims agree
with the authorization-code, access-token, and refresh-token database bindings.

The signed-in website calls the same `/api/v1` routes on its own session
(#385). A request without a bearer token that carries the session cookie runs the
web group's session and request-forgery middleware; a signed-in user then
authenticates as the `svc-web` client holding every API scope except `mcp:use`.
Roles and policies apply unchanged. The agent write cutovers
(`AGENT_API_*_WRITES_ENABLED`) gate OAuth principals only, so switching agent
writes off never breaks the website. A bearer token is never combined with a
session, and the MCP endpoint does not accept one. Pages receive each API
operation as a finished URL plus the record's opaque version, call it through
`resources/js/lib/api.ts` with a fresh idempotency key, and use request types
generated from the OpenAPI document (`pnpm run api:types`; CI fails when the
generated file is stale).

The browser mutation allowlist is closed to business operations. Its named
exceptions cover credential issuance, revocation and connected-client management, sign-out, the client
management redirect, three provider/email webhooks, the Stripe payment hand-off,
and interactive agreement/proposal signing. The portal recipient's proposal
signature remains a browser act; authorized REST/MCP clients can separately
record confirmed offline acceptance with `proposals.accept`. Inertia resource
props use the same pure resource presenters as API responses, with explicit
safe field selections and page navigation/capability extras.

The one read-only browser UI helper is `GET /search`, used by the command
palette to jump between already-authorized destinations across the operator's
workspaces, including manager-only History links and the workspace selector.
It returns only navigation rows (`kind`, stable `id`, `title`, distinguishing
`subtitle`, finished `href`, and workspace label); selecting one navigates to
its page. It does not refetch resource facts or supply mutation inputs. Business
resource search remains the scoped `search` API operation and shared
`WorkspaceSearch` query, with API read-scope filtering unchanged. This navigation
helper is separate from the named mutation exceptions above; resource option
lookups and JSON list appends use the canonical API.

`BrowserJsonReadParityTest` checks the actual task editor/organization options
and native/portal attachment continuation URLs. It also keeps the nine retired
task-assignee/planning lookup and attachment JSON index routes absent while
preserving Inertia pages and authorized byte-download destinations. The guard
tracks these consumer contracts; unused native read handlers are not a promise
of an additional resource API.

Apps that use the REST API without MCP connect one of two ways (#384). A person
registers an OAuth app on the setup page - exact HTTPS or loopback redirect URIs,
public (PKCE only) or confidential (a client secret shown once, used with
`client_secret_basic` or `client_secret_post`) - with a scope ceiling, and the app
runs the authorization-code flow with S256 PKCE. Or the person mints a personal
API token with chosen scopes and a 30, 90 or 365-day lifetime, shown once. Both are
bound to the REST resource `/api/v1`, never carry `mcp:use`, are revocable from the setup page, and
are issued only in the browser: no OAuth credential can mint another. Because
generic OAuth clients rarely send RFC 8707 `resource`, an omitted resource is bound
to `/api/v1` (`assume_omitted_resource`); a different explicit resource is still
refused. The OpenAPI document declares both the `oauth2` scheme and an `apiToken`
bearer scheme on every operation. `/api/openapi.json` serves that document with its server and OAuth endpoints taken from this installation's configuration; connectors import it from the setup page.

Provider identity enforcement (`BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED`, off until
the operator enables it) ties every credential of an account bound to the identity
provider to that identity: the browser session, the session path into `/api/v1`, OAuth
access and refresh tokens, personal API tokens and the finance integration's Sanctum
tokens stop working once the provider reports the identity disabled, deleted, reset or
ungranted. Each credential records the provider subject and credential generation it
was issued under, every request checks it against one status observation per person
that is fresh for under five minutes, and writes - website, REST or MCP - ask the
provider freshly; an MCP write tool asks once per call. An unavailable provider is a
retryable `503` that keeps the session and the token. Credentials issued before
stamping are refused once enforcement is on and must be re-authorized. Accounts with
no provider binding, such as the deployment's smoke principal, are left alone.

Behind a CDN, per-client limits key on the real client address. The auth
package's trusted-proxy helper (`bherila-auth.trusted_proxies`, `TRUSTED_PROXIES`:
`cloudflare` by default, an explicit list, or empty to trust nothing) honours
`X-Forwarded-For` and its scheme - never the forwarded port, which Cloudflare passes
through from the client, nor the host - only from the configured proxies, so the
client is the address the edge appended. An origin accepting direct connections must not trust `*`; with the bounded
proxy configuration, a direct caller’s forged header is ignored. A weekly workflow runs `bherila-auth:check-cloudflare-ranges` and fails
when Cloudflare revises its ranges.

Browser MCP traffic uses an exact configured origin allowlist for preflight and the
actual POST/DELETE request. A disallowed preflight receives no allow-origin header;
an actual disallowed-origin request is rejected. Origin-less native clients remain
supported.

## Safety and observability

Mutation retries are keyed by workspace, authenticated OAuth client, user, operation, and idempotency key. An
identical retry returns its original result; key reuse with another request body is a
409 conflict. Receipt reservation, business writes, receipt completion, and success
audit commit atomically. Failed mutations roll back the receipt and business writes,
then record a metadata-only failure audit. Audit events record actor, OAuth client,
workspace, operation, affected public IDs, outcome/error category, request ID, and
timestamp only. Request/response bodies, free text, tokens, filenames, blob data,
payment data, and provider identifiers are never logged.

Historical REST time, task, and invoice receipts stored an ambiguous `testing-client`
namespace. Those keys now fail closed with 409, even for an identical retry: neither
receipts nor audits identify the original authenticated client. No historical result
is disclosed, reassigned, or executed again. A receipt lacking workspace provenance
also blocks that actor/operation/key; a receipt in another known workspace does not.
An operator must reconcile the original outcome before intentionally issuing a new
mutation with a fresh key. Changing keys automatically would bypass this safeguard.

New requests use the authenticated client and atomically reserve a non-completed
compatibility guard in the legacy namespace before reserving their own receipt.
This prevents old in-flight REST code from executing the same key during rollout.
Guards contain no result IDs, can serve distinct authenticated clients independently,
and are retained permanently with the receipts. Do not prune or backfill them, or
roll back to the old writer as a way to clear conflicts. See
[the rollout details](mcp.md#legacy-rest-receipt-cutover).

The canonical wire contract is `public/openapi/svc-agent-v1.json`. MCP output schemas
are packaged from each tool's declared REST success component and enforced at runtime;
the MCP layer does not maintain a second response-schema tree.

The checked OpenAPI document is generated from `AgentOperationCatalog` and
`AgentRestDocumentation` through `AgentOpenApi` and the package’s
`OpenApiDocumentBuilder`. `OpenApiDocumentGenerationTest` requires byte-for-byte
parity with the generated document. Regenerate it with
`UPDATE_OPERATION_SNAPSHOTS=1`, then regenerate and check the TypeScript API types.

The MCP initialize response front-loads the operational rules a harness needs for safe
time and invoice work. Clients that implement MCP prompts can also expose the guided
`log-time-across-projects` and `prepare-invoice-safely` workflows. Tool descriptions,
schemas, and annotations remain the authority for each individual call.

Received-payment bookkeeping uses separate `payments:read` and `payments:record`
grants. Recording is owner/admin-only, default-disabled behind the payment and
outer workflow flags, and requires an explicit invoice, amount, currency,
method and idempotency key. The received date defaults to today on the workspace
calendar. It records a receipt of money or its permitted
pending state; it never starts a charge or sends a refund. Correcting a recorded
payment's method, reference, notes or received date uses the same grant and gates,
requires the payment's version and a reason, and cannot change amount, currency
or status. Separate confirmed bookkeeping operations change eligible manual
payment statuses, record money already returned and reverse erroneous refund
records; none moves funds.
Reference remains optional. Read responses follow invoice visibility and omit
private finance reconciliation and processor identifiers.

Withdrawing time approval requires both `time:approve` and `time:read`, so the caller can obtain the current opaque revision from the public time read response.

Billing schedule creation and generation require `billing:read` alongside their write or delivery scope, so callers can read the parent agreement or schedule revision first.

Task creation and updates now share `WorkspaceTaskMutationAction` across the
website, REST and MCP. The web form retains its manager-only gate, validation
limits, redirects and client-visible default; agents retain authorized project
owner/manager access, their existing validation limits and private default.
Partial agent updates preserve completion time unless status is supplied;
completing records the current UTC instant and reopening clears it. Agent updates
lock the workspace-owned task before checking the opaque version. Identical
replays still recheck access, and no-op agent updates still advance the revision.

Project administration requires a workspace owner/admin and `projects:write`.
Create additionally requires `clients:read` to obtain the client company revision;
update, archive and member access require `projects:read` to obtain the project
revision. REST and MCP use the same scope requirements. Inaccessible and absent
workspaces have identical JSON refusals and private no-store cache headers on all
new project routes. The context advertises project writes only when at least one
project operation can read its revision under the granted scopes.

## Proposal parity (#386)

The shared `ProposalMutationAction` backs web creation/send/portal acceptance and
REST/MCP operations `proposals.create`, `proposals.send`, `proposals.accept`.
`proposals.list`/`proposals.get` expose authorized facts and current versions.
The proposal scopes separate reading commercial terms, manager drafting/publishing,
and accepting a legally effective agreement. Existing billing/client scopes grant
none of these actions. Portal acceptance obeys the same company and project scope
as portal reads. Confirmed sends publish to the portal; they do not email.

Proposal creation also requires `clients:read` to obtain the parent version; send and acceptance require `proposals:read` to obtain the current proposal version.

## Workspace creation, search and generic files (#386)

`workspaces.create` requires `workspaces:create`, `AGENT_API_WRITES_ENABLED`
and `AGENT_API_WORKSPACE_WRITES_ENABLED`. It uses the website's `CreateWorkspace`
action and creates the actor's owner membership atomically. A new tenant has no
previous `expected_version`. An actor/client/idempotency-key reservation binds
its name and resulting tenant; replay checks current owner/admin membership and
cannot return another client's reservation. Failed creation rolls back the
reservation, tenant and membership together.

`search` uses `identity:read` and the website's workspace search service. It is
bounded to five results per kind and applies current record access and each
kind's read scope (`clients:read`, `projects:read`, `tasks:read`, `billing:read`)
before returning results. Searches never span an implicitly selected tenant.

Generic attachments use `files:read` for `attachments.list`, `attachments.get`
and `attachments.download_url`. Workspace owners/admins can read metadata for
company, project, task, proposal, agreement and invoice attachments. Expense
receipts retain their dedicated expense routes. Metadata includes opaque file
versions and excludes storage keys and uploader identifiers. Lists use bounded cursor pagination over available files, newest first, and
return the parent's current version.

`attachments.upload_url` requires `files:write` and both
`AGENT_API_WRITES_ENABLED` and `AGENT_API_FILE_WRITES_ENABLED`. It prepares a URL
without persisting an upload or reserving an idempotency key. Upload multipart
`file` and `expected_version` to that URL with the same bearer credential and
an `Idempotency-Key` header. The upload is limited to 50 MiB and checks the
current version while holding the tenant-scoped parent row lock. A file is an
immutable append; it does not edit the parent's business facts or revision.
The mutation executor binds retries to parent, version, filename, media type,
length and SHA256 and records success/replay/failure audits. A rolled-back
upload compensates its newly published object.

`attachments.delete` requires the same file write gates, the attachment's own
current `expected_version`, and explicit `confirm: true`. Deletion makes the
file unavailable immediately; existing retention/repair handles physical purge.
Both upload and download URLs expire after ten minutes and still require the
current scope and owner/admin role. A signed URL is never a bearer credential.

`context.get` reports `withheld_tools` with a stable tool name and one reason:
`deployment_disabled`, `scope_not_granted` (with the missing scope), or `role`.
This comes from the complete MCP registry and the same discovery gates as
`tools/list`. The global `capabilities` array advertises `workspaces:create`
when its scope and deployment gates permit it, including before the user owns
a workspace. Invoice descriptions direct callers to this metadata instead of
claiming payment recording is always available (#382).

## Write preconditions

The generated OpenAPI operations publish `x-agent-write-safety` from the registry.
Confirmation metadata follows the request body: a required `confirm` is the JSON
boolean `true`, not a truthy string or number. `payments.record`, `payments.correct`,
`invoices.square_link`, `companies.square_customer.set` and `clients.archive` require
confirmation. Recording a received payment also requires `billing:read` to obtain the invoice's current
`expected_version`; it is checked under the invoice lock. A completed idempotent
retry returns the original payment even after its invoice version advances.
Draft `time_entries.delete` and `expenses.delete` do not require confirmation.

`attachments.upload_url` and `expenses.receipts.upload_url` authorize writes and
obey the MCP write-group switch, with no read-only hint. Preparing a URL has no request body or
persistent receipt; the subsequent multipart upload requires its own idempotency
key and parent version.

Task creation defaults to hidden in the API. The website request must send its
visibility choice explicitly. Both creation surfaces accept titles up to 255
characters and descriptions up to 10,000. Expense deletion requires draft status
on every surface; unapprove an approved expense first. Web and API expense facts
require uppercase three-letter currency codes. The browser expense list now
uses the same canonical expense operations on
`/api/v1`: current opaque versions guard edits and lifecycle moves, and a dialog
keeps one idempotency key across retries. Its rows extend the API presenter with
scoped display names and finished action URLs. The retired expense web mutation
routes have no remaining browser callers; recurring expenses have their own
canonical API family.

New archives validate confirmation inside their receipt callback. A completed
archive receipt from before the confirmation requirement can replay its original
body without writing again; every replay still requires current manager access.
Confirmed archive receipts bind the confirmation field, so omitting it changes the
request rather than replaying a newly confirmed archive.
