# Codex handoff 2: MCP progressive discovery and accounting reads

Repository: `bherila/svc`. Read `AGENTS.md` first and follow it exactly:

- workspace-scoped queries with isolation tests;
- exhaustive `match` over billing enums with no `default` arm;
- synthetic fixtures only (reserved domains `.test` or `example.com`);
- no queue worker in production;
- pnpm only;
- focused local checks; hosted CI runs the full suites.

The overall spec is `docs/ux-overhaul.md` on branch
`claude/relaxed-einstein-qmprci` (PR #612). Read sections 11 and 13.

**Running in parallel, don't edit:**

- `resources/js/pages/**`, `resources/js/layouts/**` and `app/Services/Navigation/**`;
- `app/Services/Revenue/**` and the new `revenue.*` operations (the revenue lane);
- payment-connection code: `StripeGateway`, Stripe and Square services, `workspace_payment_connections`;
- `InvoiceDocumentService` and `resources/views/invoices/show.blade.php`;
- `TimeEntryMutationService`.

**Shared generated files:** the revenue lane also adds operations to
`AgentOperationCatalog`. Before opening each PR, update from `origin/main`,
resolve conflicts, and regenerate OpenAPI, the TS API types, the scope
inventory and any MCP visibility snapshots with the repository's own
generators. Never hand-edit generated files.

Each task is its own branch off `origin/main` and its own PR. Do not merge.

---

## Task 4: MCP progressive tool discovery (spec section 11)

Branch: `codex/mcp-progressive-discovery`

Today `tools/list` returns every tool the principal may call, filtered by
scope, role and feature flag, and paged at 100. Section 11 of `docs/mcp.md`
describes this. A fully scoped manager sees about 190 tools at once. The
server advertises no list-change notifications.

1. **Core set at initialize:** `context.get`, `search`, `toolsets.list`,
   `toolsets.enable` and `toolsets.disable`, plus the tools the
   `log-time-across-projects` prompt needs.
2. **Toolsets.** Derive them from `AgentOperationCatalog` scope groups, one
   source of truth: projects, tasks, collaboration, files, time, clients,
   proposals, billing, payments, expenses, finance, square, and `revenue` once
   the revenue lane lands. Don't hard-code that last one; derive it. A new
   scope group must become a toolset with no extra code, and a test should
   prove it.
3. **`toolsets.list`** returns name, description and a tool count. It counts
   only tools that this principal's scope-, role- and flag-filtered
   discovery already allows.
4. **`toolsets.enable` and `toolsets.disable`** change the session's enabled
   set. Sessions are already cached per credential digest; see
   `Psr16SessionStore` in `docs/mcp.md`. Both send
   `notifications/tools/list_changed`, and initialize now advertises
   `tools.listChanged: true`.
   - Enabling grants nothing. It only reveals tools that discovery would
     already list.
   - Calling a tool that isn't in an enabled toolset returns the existing
     "not found / not available" error. Every call is authorized again, as
     today.
5. **Default and compatibility (decided by the owner):** progressive
   discovery is the default for EVERY client, existing ones included. A
   client that can't handle `list_changed` opts into the full list at connect
   time: an initialize option, or a setting on the OAuth client record,
   whichever fits the existing patterns better. Document which you chose.
   Tool names, schemas and REST parity don't change.
6. **Prompts** stay conditional, as today. A prompt is advertised only when
   its tools are discoverable. Decide whether its required toolsets are
   enabled automatically when the prompt is fetched, and document the
   decision.
7. **Tests:**
   - the core set plus every toolset equals today's full list, for each
     scope preset;
   - enable and disable round-trip and send `list_changed`;
   - an unauthorized toolset can't be enabled, and its count is 0 or it is
     absent;
   - cross-credential sessions are isolated;
   - the existing `AgentMcpContractTest` and the discovery and partition tests
     are updated, not weakened;
   - the MCP SDK streamable-HTTP smoke test still passes.
8. Update `docs/mcp.md`, and the MCP setup page copy only if it currently
   promises a full list.

## Task 5: accounting reads for bookkeeping and tax software (spec section 13, second half)

Branch: `codex/accounting-reads`

All of these go under the existing `finance:read` scope. Managers only.
Every one is workspace-scoped, isolation-tested, and both REST and MCP via
`AgentOperationCatalog`.

1. **Invoice lines with tax broken out:** per issued invoice line, give net
   amount, tax amount, tax rate and taxable flag, line type (through
   `statusLabel` / the line-type labels, never the raw enum on display
   fields), service period, and client and project public IDs.
   - Corrections, credits and voids arrive as new reversing records with a
     reference to what they reverse, never as edits to issued records.
   - Read `InvoiceCorrectionService` and the correction docs, and follow the
     existing semantics.
2. **Cash-basis income:** payments, refunds and refund reversals by
   received or effective date, with processor fees where they're already
   recorded. Reuse the finance services (`finance.invoice_payments.list`,
   payouts, processor movements) and do not duplicate them. Add only what's
   missing for a one-call "income received in period" view.
3. **Yearly income export:** a calendar-year export in CSV and JSON with
   invoiced totals by month (tax separate), cash received by month, refunds,
   fees, and expenses passed through. The REST endpoint streams CSV. MCP
   returns JSON. It is computed in the request, with no queued job, and
   bounded by the workspace's invoices for that year.
4. **Incremental sync:** every list above takes a stable ID ordering and an
   `updated_since` cursor (an opaque cursor carrying a timestamp and ID
   tiebreaker, consistent with existing cursor pagination, maximum page
   size 100).
   - Test that a correction made after a sync appears on the next sync as
     a new reversing record.
5. Regenerate OpenAPI and the TS types. Update `docs/finance-api.md` and
   `docs/mcp.md`.
6. **Tests:**
   - figures reconcile with the existing finance reads for one fixture;
   - CSV and JSON exports agree;
   - workspace isolation;
   - a `finance:read` token without manager rights is refused.

---

When each task is done, open a PR against `main` (there's no PR template;
match the prose style of recent PRs). Then report back: the PR link, the
local checks you ran with their results, and every decision the spec didn't
settle.
