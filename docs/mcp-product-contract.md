# SVC Agent API and MCP product contract

SVC exposes its agent surface through a versioned REST API and a remote Streamable
HTTP MCP endpoint at `/api/v1/mcp`. MCP tools are adapters over the REST operations;
they do not query Eloquent models directly.

## Scope and exclusions

The first release lets authorized users view and manage project work, time, invoices,
tasks, and attachment metadata. Project creation, archival, and deletion remain web
application actions. File upload/download bytes and payment collection, initiation,
recording, refunds, and provider identifiers are out of scope. Invoice responses may
contain a role-authorized canonical browser URL so a user can continue a payment flow
in the website.

## Roles

Workspace `owner` and `admin` retain full workspace access. Internal users can be
assigned to a project as `owner`, `manager`, `contributor`, or `viewer`. Owners and
managers can manage tasks and approve project time. Contributors can view assigned
projects and manage only their own draft time. Client-company members remain read-only
and see only records that existing client-visibility rules permit.

## Agent operations

The initial catalog is `context.get`, `operations.summary`, project/task reads,
time-entry list/log/update/delete/approve, invoice list/get/create_draft/update_draft/
issue/send/void, and attachment metadata listing. There is no generic CRUD tool.

All resources use public UUIDs. Lists use cursors with a maximum page size of 100.
Every mutable representation contains an opaque `version`; mutation requests require
`expected_version` and an idempotency key. Cross-workspace identifiers resolve as 404.

Time follows `draft -> approved -> invoiced`. A draft invoice may include manual lines
and explicitly selected time-entry IDs only. Selected entries must be approved,
billable, non-deferred, currency-compatible, and unallocated. Invoice issue, send,
and void are distinct confirmation-gated actions.

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
409 conflict. Audit events record actor, OAuth client, workspace, operation, affected
public IDs, result, request ID, and timestamp only. Request/response bodies, free text,
tokens, filenames, blob data, payment data, and provider identifiers are never logged.
