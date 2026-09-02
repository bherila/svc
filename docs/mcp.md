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
credential: a global, MCP-path-only guard rejects `access_token`, `token`,
`authorization`, and `bearer` query parameters before route authentication
with a no-store 400 response. The current provider resolves `AgentPrincipal`,
an OAuth-only view of the local `users` table; its memberships and project
roles remain SVC data. OAuth Authorization Code with S256 PKCE is the
documented client flow.

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
their declared scopes and omits manager-only capabilities when the principal
has no workspace where the existing `AgentAccess::isWorkspaceManager` policy
can succeed. The MCP
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
`billing_schedules.list`, `billing_schedules.get`, `capacity_ledger.get`,
`billing.audit_unplaceable_invoices`,
`billing.audit_undated_collectible_invoices`, and
`billing.audit_missing_billed_overage`.

Agreement tools require `billing:read` and an SVC workspace-manager role.
They return only the existing directory's allowlisted, derived agreement DTO;
project-scoped users and client portal users receive the same non-existence
response as for an inaccessible agreement.

`time_entries.log`, `time_entries.update`, and `time_entries.delete` appear
only while the time-entry write flag is enabled and the token has the needed
scope. They and `tasks.create` / `tasks.update` use tenant-scoped application
actions directly. The broader write flag also retains legacy compatibility
registrations for `time_entries.approve`, `invoices.create_draft`,
`invoices.update_draft`, `invoices.discard_draft`, `invoices.issue`,
`invoices.send`, and `invoices.void`; those capabilities still enter the
versioned Agent API through `InternalAgentApiTransport`. They are disabled by
default and are not a PR 6/7 production-ready write path: approval, invoice,
and externally consequential workflows require their own application-action
migration plus the approved confirmation design before general availability.

The tool catalog is `AgentMcpToolCatalog`; `AgentMcpInputSchemaFactory` and
`AgentMcpOutputSchemaFactory` derive public schemas from the checked-in
OpenAPI response catalog. Inputs are validated before dispatch, output is
validated after dispatch, and failures are mapped to safe MCP errors. Read
tools and the REST controller both use `AgentReadService`, the single
tenant-scoped query/presentation boundary. Read tools and the direct
task/draft-time write actions do not invoke controllers or internal HTTP
routes, and no MCP capability exposes Eloquent models. The disabled legacy
approval/invoice write registrations above are the explicit exception pending
their migration; they must not be used as a pattern for new MCP work.

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

### #187 read capability matrix

This matrix covers the additive read surface proposed by #187. Every entry
uses a workspace-scoped application read service; the MCP handler only
resolves authenticated context, validates its DTO, and maps the result.

| Capability | Operator/UI workflow and backing service | Scope and policy | Bounds and privacy contract | Flag and coverage |
| --- | --- | --- | --- | --- |
| `agreements.list`, `agreements.get` | Client-directory agreement view; `AgentAgreementReadService` and the shared `AgreementReadPresenter` | `billing:read`; `AgentAccess::isWorkspaceManager`; workspace-scoped agreement query | Status filter, 1–100 page, query-bound cursor; allowlisted stored and derived terms only; inaccessible objects are not found | `mcp.read.agreements`; MCP contract, parity, and tenant-isolation tests |
| `billing_schedules.list`, `billing_schedules.get` | Billing schedule view; `AgentBillingScheduleReadService` and `BillingScheduleReadPresenter` | `billing:read`; manager; workspace-scoped schedule query | Active filter, 1–100 page, query-bound cursor; only agreement ID, cadence, next-run date, and active state | `mcp.read.billing_schedules`; MCP contract, parity, and tenant-isolation tests |
| `capacity_ledger.get` | Time-sheet capacity display and billing ledger; `AgentCapacityLedgerReadService` over `InvoiceLedgerBuilder` | `billing:read`; manager; workspace-scoped agreement query | 1–60 trailing months; signed, allowlisted computed ledger rows; inaccessible agreement is not found | `mcp.read.capacity_ledger`; ledger, schema, and cross-workspace tests |
| `billing.audit_unplaceable_invoices` | `svc:billing:audit-unplaceable-invoices`; `AgentBillingAuditReadService` over `UnplaceableInvoiceAuditor` | `billing:read`; manager; workspace-scoped audit | No record identifiers or raw amounts beyond aggregate, per-workspace totals; no pagination | `mcp.read.billing.audit_unplaceable_invoices`; aggregate/redaction and authorization tests |
| `billing.audit_undated_collectible_invoices` | `svc:billing:audit-undated-collectible-invoices`; `AgentBillingAuditReadService` over `UndatedCollectibleInvoiceAuditor` | `billing:read`; manager; workspace-scoped audit | Aggregate counts and bounded per-currency integer balances only; no record identifiers | `mcp.read.billing.audit_undated_collectible_invoices`; aggregate/redaction and authorization tests |
| `billing.audit_missing_billed_overage` | `svc:billing:audit-missing-billed-overage`; `AgentBillingAuditReadService` over `MissingBilledOverageAuditor` | `billing:read`; manager; workspace-scoped audit | Aggregate counts only; no record identifiers or raw models | `mcp.read.billing.audit_missing_billed_overage`; aggregate/redaction and authorization tests |
| Duplicate-time diagnostics | No checked-in workflow/service establishes the grouping key | Unresolved | Not implemented: matching semantics must be explicitly defined before time-entry data is grouped or disclosed | No flag or release cohort until a canonical application query exists |

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
the client through handshake, discovery, and `context.get`. Tool execution is
also centrally throttled by reviewed `mcp-read` (120/minute) and `mcp-write`
(20/minute) buckets, keyed to the authenticated credential and capability;
the route's `throttle:60,1` remains the broad outer limit. The baseline does
not yet provide per-capability metrics or resource templates. Every tool call
also emits the metadata-only `mcp.capability.executed` audit event for every
tool call, resource read, and prompt retrieval (including hidden or unknown
direct tool attempts): request ID, capability, bucket, audit classification,
outcome, duration, subject public ID, and one-way
credential/client fingerprints. Arguments, results, headers, and raw tokens
are excluded by contract.
Incident containment, OAuth-connection revocation, recovery, and rollback are
documented in [the MCP operational runbook](mcp-operations.md).

## #187 disposition and related work

Issue #187 had no comments or explicit acceptance decision when this baseline
was written. The product owner subsequently approved every read-only proposal
subject to the existing privacy boundary; the consequential maintenance
workflow remains unresolved:

| Proposal | Current disposition |
| --- | --- |
| Agreement list/get with derived terms | Implemented; manager-only `agreements.list` / `agreements.get` |
| Signed monthly capacity ledger | Implemented; manager-only bounded `capacity_ledger.get` over `InvoiceLedgerBuilder` |
| Duplicate-time diagnostics | Unresolved: no checked-in duplicate-time diagnostic query or approved matching semantics exists to reuse. It will not be inferred from raw time rows. |
| Read-only `svc:billing:audit-*` operations | Implemented as aggregate-only, manager-scoped `billing.audit_unplaceable_invoices`, `billing.audit_undated_collectible_invoices`, and `billing.audit_missing_billed_overage` over the canonical billing auditors |
| Billing-schedule visibility | Implemented; manager-only `billing_schedules.list` / `billing_schedules.get` |
| Imported-duplicate maintenance preview/execute | Unresolved and consequential; no implementation without explicit approval |

[#172](https://github.com/bherila/svc/pull/172) is the merged appearance
selector work and has no MCP contract dependency. [#175](https://github.com/bherila/svc/pull/175) tightened null-tolerant billing-cycle attribution and
refusal behavior. Any future ledger or audit capability must preserve those
application-service semantics rather than reconstruct them in MCP.
