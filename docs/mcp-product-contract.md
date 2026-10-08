# SVC Agent API and MCP product contract

SVC exposes its agent surface through a versioned REST API and a remote Streamable
HTTP MCP endpoint at `/api/v1/mcp`. MCP read tools and migrated task/draft-time
tools adapt the same application queries and actions as REST; they do not query
Eloquent models directly. Legacy approval/invoice registrations remain disabled by
default until their controller-transport migration and consequential-workflow
approval are complete.

## Scope and exclusions

The operations v1 release lets authorized users view projects, tasks, time,
invoices, and received payments. Its feature-gated write catalog manages tasks,
draft time, time approval, invoice draft creation/update/discard, invoice
issue/send/void workflows, and recording money already received. Project creation,
archival, and deletion remain website actions. Attachment metadata/download
access and file uploads are deferred from the currently shipped catalog. Payment
collection, initiation, refunds, card data, and provider identifiers are out
of scope. Invoice responses contain a role-authorized browser URL so a user can
continue a payment flow in the website.

## Roles

Workspace `owner` and `admin` retain full workspace access. Internal users can be
assigned to a project as `owner`, `manager`, `contributor`, or `viewer`. Owners and
managers can manage tasks and approve project time. Contributors can view assigned
projects and manage only their own draft time. Viewers cannot read team time or log
time. Client-company members remain read-only
and see only records that existing client-visibility rules permit.

## Agent operations

The core read catalog includes `context.get`, `operations.summary`, `projects.list`,
`projects.get`, `tasks.list`, `tasks.get`, `time_entries.list`, `invoices.list`,
`invoices.get`, and `payments.list`. When the explicit write cutover flag is
enabled, the additional tools
are `tasks.create`, `tasks.update`, `time_entries.log`, `time_entries.update`,
`time_entries.delete`, and `time_entries.approve`. The eight invoice write tools —
`invoices.create_draft`, `invoices.update_draft`, `invoices.update_details`,
`invoices.discard_draft`, `invoices.issue`, `invoices.send`, `invoices.correct`,
and `invoices.void` — additionally require
`AGENT_API_INVOICE_WRITES_ENABLED`, a second cutover nested inside the first, so
that agent-assisted time approval does not arrive with agent-initiated invoice
delivery attached. `payments.record` requires `payments:record`, workspace owner/admin
permission, and both `AGENT_API_WRITES_ENABLED` and
`AGENT_API_PAYMENT_WRITES_ENABLED`; the payment flag defaults to false. It records
money already received and cannot initiate a charge. `payments.correct` carries the
same gates and corrects only a recorded payment's method, reference or received
date, with its version and a reason. There is no generic CRUD tool.

All resources use public UUIDs. Lists use cursors with a maximum page size of 100.
Every mutable representation contains an opaque `version`; updates and lifecycle
transitions require `expected_version`. Every Agent API mutation requires an
idempotency key and runs through the same reservation-first transaction and audit
boundary. The write flag remains disabled until all production-readiness blockers
and the final write-authority cutover are complete. Cross-workspace identifiers
resolve as 404.

Tool discovery is filtered by the current access token's scopes. `context.get`
reports the intersection of token scope, the write cutover flag, and current role,
including per-project capabilities when `projects:read` permits disclosing project
IDs. `operations.summary` always returns the workspace ID and conditionally includes
project, time, and billing sections only when their respective read scopes are
present. Invoice amounts are grouped by currency and distinguish drafts,
collectible balances, and overdue balances; invoice-ready time excludes deferred,
nonbillable, unrated, and already allocated entries.

Time follows `draft -> approved -> invoiced`. Approval snapshots the hourly rate and
currency from the most recently effective active agreement, preferring a
project-specific agreement over a company-wide agreement. Billable approval fails
when no applicable rate exists unless the approving manager supplies an explicit
amount and currency; nonbillable time needs no rate. A draft invoice may include
manual lines and explicitly selected time-entry IDs only. Selected entries must be
approved, billable, non-deferred, currency-compatible, and unallocated. Time-derived
line totals are rounded from integer minutes and hourly minor units; their four-place
hour quantity is display-only. Invoice issue, send, correct, and void are distinct
confirmation-gated actions. Draft update is replace-all for the explicit time selection
and manual lines. Removing time, discarding a draft, or voiding an unpaid invoice
releases its allocation; issued linked time returns from `invoiced` to `approved`.
Invoice responses describe linked time as `reserved`, `consumed`, or `released`.
Invoice numbers come from a transaction-locked workspace counter consumed in the same
transaction as invoice creation. Manual-line projects must belong to both the invoice
workspace and client company.

Client-visible time requires a non-empty, explicitly authored client-facing
description. Client reads never fall back to an internal time description, including
for legacy records that predate this invariant.

## Authorization

Users authenticate in a browser through Bherila.net, then grant SVC-specific OAuth
access. SVC validates a token's resource audience, scopes, current account status, and
current workspace/project/company permissions for every request. Initial scopes are
`mcp:use`, `identity:read`, `projects:read`, `tasks:read`, `tasks:write`, `time:read`,
`time:write`, `time:approve`, `billing:read`, and `billing:write`; invoice lifecycle
delivery actions additionally require `billing:deliver`. Received-payment reads
require `payments:read`; recording requires the separate `payments:record` grant.
Project detail embeds tasks only when the connection also has `tasks:read`.

OAuth public clients use authorization code plus rotating refresh tokens, `code`
response type, S256 PKCE, exact resource binding, and no token-endpoint client
authentication. Dynamic registration accepts only that profile and safe HTTPS or
loopback redirect URIs. Registrations are marked, their exact scope ceiling and
last token use are persisted,
and a daily retention command removes only stale registrations with no active access
or refresh credential. SVC configures the shared auth package's consent screen after
the Bherila.net login. Access-token JWT issuer, audience, and resource claims agree
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

Apps that use the REST API without MCP connect one of two ways (#384). A person
registers an OAuth app on the setup page - exact HTTPS or loopback redirect URIs,
public (PKCE only) or confidential (a client secret shown once, used with
`client_secret_basic` or `client_secret_post`) - with a scope ceiling, and the app
runs the authorization-code flow with S256 PKCE. Or the person mints a personal
API token with chosen scopes and a 30, 90 or 365-day lifetime, shown once. Both are
bound to `/api/v1`, never carry `mcp:use`, are revocable from the setup page, and
are issued only in the browser: no OAuth credential can mint another. Because
generic OAuth clients rarely send RFC 8707 `resource`, an omitted resource is bound
to `/api/v1` (`assume_omitted_resource`); a different explicit resource is still
refused. The OpenAPI document declares both the `oauth2` scheme and an `apiToken`
bearer scheme on every operation. `/api/openapi.json` serves that document with its server and OAuth endpoints taken from this installation's configuration; connectors import it from the setup page.

Behind Cloudflare, per-client limits key on the real client address:
`config/proxies.php` trusts `X-Forwarded-For` (and its scheme and port, never the
host) only from Cloudflare's published ranges, so the client is the address
Cloudflare appended. The origin also answers direct connections, so it never
trusts `*`, and a direct caller's forged header is ignored. A weekly workflow fails
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

The MCP initialize response front-loads the operational rules a harness needs for safe
time and invoice work. Clients that implement MCP prompts can also expose the guided
`log-time-across-projects` and `prepare-invoice-safely` workflows. Tool descriptions,
schemas, and annotations remain the authority for each individual call.


Received-payment bookkeeping uses separate `payments:read` and `payments:record`
grants. Recording is owner/admin-only, default-disabled behind the payment and
outer workflow flags, and requires an explicit invoice, amount, currency, payment
date, method and idempotency key. This records an already completed receipt of
money; it never starts a charge, refunds funds or edits a payment's status.
Correcting a recorded payment's method, reference or received date uses the same
grant and gates, requires the payment's version and a reason, and cannot touch
amount, currency, status or refunds.
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
