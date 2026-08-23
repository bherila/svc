# SVC Agent API and MCP product contract

SVC exposes its agent surface through a versioned REST API and a remote Streamable
HTTP MCP endpoint at `/api/v1/mcp`. MCP tools are adapters over the REST operations;
they do not query Eloquent models directly.

## Scope and exclusions

The operations v1 release lets authorized users view projects, tasks, time, and
invoices. Its feature-gated write catalog manages tasks, draft time, time approval,
invoice draft creation, and invoice issue/send/void workflows. Project creation,
archival, and deletion remain website actions. Attachment metadata/download access,
file uploads, and invoice draft update/discard are deferred from the currently shipped
catalog. Payment collection, initiation, recording, refunds, card data, and provider
identifiers are out of scope. Invoice responses contain a role-authorized browser URL
so a user can continue a payment flow in the website.

## Roles

Workspace `owner` and `admin` retain full workspace access. Internal users can be
assigned to a project as `owner`, `manager`, `contributor`, or `viewer`. Owners and
managers can manage tasks and approve project time. Contributors can view assigned
projects and manage only their own draft time. Client-company members remain read-only
and see only records that existing client-visibility rules permit.

## Agent operations

The read catalog is `context.get`, `operations.summary`, `projects.list`,
`projects.get`, `tasks.list`, `tasks.get`, `time_entries.list`, `invoices.list`, and
`invoices.get`. When the explicit write cutover flag is enabled, the additional tools
are `tasks.create`, `tasks.update`, `time_entries.log`, `time_entries.update`,
`time_entries.delete`, `time_entries.approve`, `invoices.create_draft`,
`invoices.issue`, `invoices.send`, and `invoices.void`. There is no generic CRUD tool.

All resources use public UUIDs. Lists use cursors with a maximum page size of 100.
Every mutable representation contains an opaque `version`; updates and lifecycle
transitions require `expected_version`. Every Agent API mutation requires an
idempotency key and runs through the same reservation-first transaction and audit
boundary. The write flag remains disabled until the remaining time and invoice
lifecycle correctness work is complete. Cross-workspace identifiers resolve as 404.

Time follows `draft -> approved -> invoiced`. Approval snapshots the hourly rate and
currency from the most recently effective active agreement, preferring a
project-specific agreement over a company-wide agreement. Billable approval fails
when no applicable rate exists unless the approving manager supplies an explicit
amount and currency; nonbillable time needs no rate. A draft invoice may include
manual lines and explicitly selected time-entry IDs only. Selected entries must be
approved, billable, non-deferred, currency-compatible, and unallocated. Time-derived
line totals are rounded from integer minutes and hourly minor units; their four-place
hour quantity is display-only. Invoice issue, send, and void are distinct
confirmation-gated actions.

## Authorization

Users authenticate in a browser through Bherila.net, then grant SVC-specific OAuth
access. SVC validates a token's resource audience, scopes, current account status, and
current workspace/project/company permissions for every request. Initial scopes are
`mcp:use`, `identity:read`, `projects:read`, `tasks:read`, `tasks:write`, `time:read`,
`time:write`, `time:approve`, `billing:read`, and `billing:write`; invoice lifecycle
delivery actions additionally require `billing:deliver`.

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
