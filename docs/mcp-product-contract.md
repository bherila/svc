# SVC Agent API and MCP product contract

SVC exposes its agent surface through a versioned REST API and a remote Streamable
HTTP MCP endpoint at `/api/v1/mcp`. MCP read tools and migrated task/draft-time
tools adapt the same application queries and actions as REST; they do not query
Eloquent models directly. Legacy approval/invoice registrations remain disabled by
default until their controller-transport migration and consequential-workflow
approval are complete.

## Scope and exclusions

The operations v1 release lets authorized users view projects, tasks, time, and
invoices. Its feature-gated write catalog manages tasks, draft time, time approval,
invoice draft creation/update/discard, and invoice issue/send/void workflows. Project
creation, archival, and deletion remain website actions. Attachment metadata/download
access and file uploads are deferred from the currently shipped catalog. Payment
collection, initiation, recording, refunds, card data, and provider identifiers are out
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

The read catalog is `context.get`, `operations.summary`, `projects.list`,
`projects.get`, `tasks.list`, `tasks.get`, `time_entries.list`, `invoices.list`, and
`invoices.get`. When the explicit write cutover flag is enabled, the additional tools
are `tasks.create`, `tasks.update`, `time_entries.log`, `time_entries.update`,
`time_entries.delete`, `time_entries.approve`, `invoices.create_draft`,
`invoices.update_draft`, `invoices.discard_draft`, `invoices.issue`, `invoices.send`,
and `invoices.void`. There is no generic CRUD tool.

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
hour quantity is display-only. Invoice issue, send, and void are distinct
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
delivery actions additionally require `billing:deliver`.
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

Browser MCP traffic uses an exact configured origin allowlist for preflight and the
actual POST/DELETE request. A disallowed preflight receives no allow-origin header;
an actual disallowed-origin request is rejected. Origin-less native clients remain
supported.

## Safety and observability

Mutation retries are keyed by OAuth client, user, operation, and idempotency key. An
identical retry returns its original result; key reuse with another request body is a
409 conflict. Receipt reservation, business writes, receipt completion, and success
audit commit atomically. Failed mutations roll back the receipt and business writes,
then record a metadata-only failure audit. Audit events record actor, OAuth client,
workspace, operation, affected public IDs, outcome/error category, request ID, and
timestamp only. Request/response bodies, free text, tokens, filenames, blob data,
payment data, and provider identifiers are never logged.

The canonical wire contract is `public/openapi/svc-agent-v1.json`. MCP output schemas
are packaged from each tool's declared REST success component and enforced at runtime;
the MCP layer does not maintain a second response-schema tree.

The MCP initialize response front-loads the operational rules a harness needs for safe
time and invoice work. Clients that implement MCP prompts can also expose the guided
`log-time-across-projects` and `prepare-invoice-safely` workflows. Tool descriptions,
schemas, and annotations remain the authority for each individual call.
