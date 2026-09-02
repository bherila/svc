# SVC MCP current-state contract

This document freezes the MCP surface shipped by SVC before the expansion
proposed in [#187](https://github.com/bherila/svc/issues/187). It is an
inventory, not a product commitment. The feature tests in `tests/Feature/Mcp`
are the executable compatibility contract.

## Pinned implementation and transport

- `mcp/sdk` is locked to `v0.7.1` (`785fc3b9b7006ecc8a73322c939d96a4a7154345`).
  Its checked-in `ProtocolVersion` enum supports `2024-11-05`, `2025-03-26`,
  `2025-06-18`, and `2025-11-25`. SVC's current tests negotiate
  `2025-06-18`; no other version is a separately tested SVC compatibility
  promise.
- `bherila/mcp-laravel-bridge` is locked to `v0.1.0`
  (`bc36bb0a5a8b9461143bf48aa9b6fc360963cf82`).
- `POST /api/v1/mcp` is Streamable HTTP. `DELETE /api/v1/mcp` is available for
  session termination and `OPTIONS /api/v1/mcp` supports configured browser
  origins. There is no stdio transport, SSE endpoint, resource subscription,
  sampling, roots, or elicitation endpoint configured by SVC.
- `AgentMcpController` runs the bridge's `StreamableHttpResponder` with DNS
  rebinding and protocol-version middleware. Maximum request size is
  `AGENT_API_MCP_MAX_BODY_BYTES` (262144 by default). Responses are
  `private, no-store`.
- Sessions use the Laravel cache via `Psr16SessionStore`, expire after
  `AGENT_API_MCP_SESSION_TTL_SECONDS` (1800 seconds by default), and are
  namespaced by a SHA-256 digest of the bearer credential. A session cannot be
  found from a request using a different bearer credential.

Production deploys the same routes from `main` to web1. The deployment keeps
OAuth signing keys server-side in `storage/app/private/oauth`; it does not
seed tenant data.

## Authentication, tenant selection, and authorization

MCP uses the `api` Passport guard and requires the `mcp:use` token scope at
the route. It is not authenticated by the browser session or a query-string
credential. The current provider resolves `AgentPrincipal`, an OAuth-only
view of the local `users` table; its memberships and project roles remain SVC
data. OAuth Authorization Code with S256 PKCE is the documented client flow.

The current route also has Laravel's `throttle:60,1` limiter. Browser requests
with an `Origin` must exactly match `AGENT_API_MCP_ALLOWED_ORIGINS`; native
clients without an Origin are permitted. CORS exposes only MCP session and
protocol headers.

Tools presently take `workspace_id` arguments. It is only a selector: an
immutable `McpRequestContext` resolves it through the authenticated
principal's active workspace or portal memberships before materializing a
workspace. `AgentAccess`, `ProjectAccess`, `PortalAccess`,
`AgentTimeEntryQuery`, and `WorkspacePolicy`, `ClientCompanyPolicy`, and
`ClientProjectPolicy` supply the applicable local membership, role, portal,
and object checks. OAuth scopes are a ceiling, not a replacement for those
checks.

Current token scopes are `identity:read`, `projects:read`, `tasks:read`,
`tasks:write`, `time:read`, `time:write`, `time:approve`, `billing:read`,
`billing:write`, `billing:deliver`, and `mcp:use`. Discovery filters tools by
the scopes declared for their corresponding Agent API operation. The MCP
principal resolver rereads the persisted Passport token on each request and
rejects expired, revoked, wrong-subject, wrong-client, or wrong-audience
credentials before discovery or execution. Read execution repeats scope
checks before tenant and object lookup. `AGENT_API_WRITES_ENABLED` defaults to
false; the independent `AGENT_API_TIME_ENTRY_WRITES_ENABLED` defaults to
true and is an emergency cutoff for the three time-entry write tools.

## Public capability inventory

With all read scopes, discovery exposes these read-only tools, in this order:

`context.get`, `operations.summary`, `projects.list`, `projects.get`,
`tasks.list`, `tasks.get`, `time_entries.list`, `invoices.list`, and
`invoices.get`, `agreements.list`, `agreements.get`,
`billing_schedules.list`, `billing_schedules.get`, and `capacity_ledger.get`.

Agreement tools require `billing:read` and an SVC workspace-manager role.
They return only the existing directory's allowlisted, derived agreement DTO;
project-scoped users and client portal users receive the same non-existence
response as for an inaccessible agreement.

`time_entries.log`, `time_entries.update`, and `time_entries.delete` appear
only while the time-entry write flag is enabled and the token has the needed
scope. The broader write flag additionally enables `time_entries.approve`,
`tasks.create`, `tasks.update`, `invoices.create_draft`,
`invoices.update_draft`, `invoices.discard_draft`, `invoices.issue`,
`invoices.send`, and `invoices.void`.

The tool catalog is `AgentMcpToolCatalog`; `AgentMcpInputSchemaFactory` and
`AgentMcpOutputSchemaFactory` derive public schemas from the checked-in
OpenAPI response catalog. Inputs are validated before dispatch, output is
validated after dispatch, and failures are mapped to safe MCP errors. Read
tools and the REST controller both use `AgentReadService`, the single
tenant-scoped query/presentation boundary; MCP does not invoke controllers or
internal HTTP routes, and it does not expose Eloquent models.

`svc://context` is a bounded JSON resource equivalent to `context.get`; it is
advertised and readable only with `identity:read`. There are no resource
templates today. Prompts are conditional: `log-time-across-projects` is
advertised only when its required context/project/time tools are discoverable;
`prepare-invoice-safely` appears only with the full authorized invoice-draft
workflow. Prompts provide guidance only and do not bypass tool authorization.

Cursor pagination is available on project, task, time-entry, and invoice
lists, with a maximum page size of 100. The server's discovery pagination
limit is also 100. Current schemas and output field allowlists are in
`public/openapi/svc-agent-v1.json` and are protected by
`AgentMcpContractTest`.

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
and global/per-capability configuration kill switches. The baseline does not
yet provide a standalone MCP-client process smoke test, registry-backed
resources/prompts, per-capability audit/metrics/rate limits, or account-bound
cursor envelopes.

## #187 disposition and related work

Issue #187 had no comments or explicit acceptance decision when this baseline
was written. The product owner subsequently approved every read-only proposal
subject to the existing privacy boundary; the consequential maintenance
workflow remains unresolved:

| Proposal | Current disposition |
| --- | --- |
| Agreement list/get with derived terms | Implemented; manager-only `agreements.list` / `agreements.get` |
| Signed monthly capacity ledger | Implemented; manager-only bounded `capacity_ledger.get` over `InvoiceLedgerBuilder` |
| Duplicate-time diagnostics | Accepted; absent from the baseline MCP |
| Read-only `svc:billing:audit-*` operations | Implemented as aggregate-only, manager-scoped `billing.audit_unplaceable_invoices`, `billing.audit_undated_collectible_invoices`, and `billing.audit_missing_billed_overage` over the canonical billing auditors |
| Billing-schedule visibility | Implemented; manager-only `billing_schedules.list` / `billing_schedules.get` |
| Imported-duplicate maintenance preview/execute | Unresolved and consequential; no implementation without explicit approval |

[#172](https://github.com/bherila/svc/pull/172) is the merged appearance
selector work and has no MCP contract dependency. [#175](https://github.com/bherila/svc/pull/175) tightened null-tolerant billing-cycle attribution and
refusal behavior. Any future ledger or audit capability must preserve those
application-service semantics rather than reconstruct them in MCP.
