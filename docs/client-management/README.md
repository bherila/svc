# Client Management and Invoicing

Domain reference for client companies, agreements, time tracking, expenses,
milestones, and invoicing — the business rules that govern how work becomes an
invoice line and how an invoice reaches a paid balance.

## How to read these documents

These pages are the **domain specification**, not a description of the current
SVC codebase. They were written against a mature implementation of this domain
and carry rules — retainer draw-down order, rollover expiry, catch-up
thresholds, credit application order, regeneration safety — that are expensive
to re-derive and easy to get subtly wrong.

SVC implements a subset. Treat every rule here as the intended behaviour and
check the table below before assuming the code already does it.

| Capability | Rules documented in | SVC status |
| --- | --- | --- |
| Client companies, projects, tasks | [overview.md](overview.md) | Implemented |
| Agreements and recurring items | [billing.md](billing.md) | Schema + lifecycle only |
| Time entries (log, approve, allocate) | [overview.md](overview.md) | Write/approve exist on the agent API; no operator UI |
| Invoice lifecycle (draft → issued → paid → void) | [billing.md](billing.md) | Implemented |
| Invoice from selected time | [overview.md](overview.md#time-entry-splitting--allocation) | Service exists; not reachable from the web UI |
| Payments and balances | [payments.md](payments.md) | Implemented |
| Stripe payment intents and webhooks | [stripe-billing.md](stripe-billing.md) | Implemented |
| Invoice email delivery | [billing.md](billing.md) | Implemented |
| Recurring billing schedules | [cadence-billing.md](cadence-billing.md) | Fixed-template schedules only |
| Retainer draw-down | [billing.md](billing.md) | **Not implemented** — `retainer_amount` / `retainer_minutes` are stored and displayed but no billing code reads them |
| Rollover of unused retainer hours | [billing.md](billing.md) | **Not implemented** — `rollover_policy` is stored but never read |
| Cadence cycles and the one-cycle offset | [cadence-billing.md](cadence-billing.md) | **Not implemented** |
| Interim overage invoices | [cadence-billing.md](cadence-billing.md) | **Not implemented** |
| Deferred billing allocation | [deferred-billing.md](deferred-billing.md) | **Partial** — `is_deferred` is stored and blocks invoicing; no allocator |
| Milestone billing | [milestone-billing.md](milestone-billing.md) | **Not implemented** |
| Overpayment credits | [overpayment-credits.md](overpayment-credits.md) | **Not implemented** |
| Client expenses | [overview.md](overview.md#client-expenses) | **Not implemented** |
| Subcontractor billing modes | [overview.md](overview.md#subcontractors) | **Partial** — per-entry cost is stored; no billing modes |
| Invoice line types beyond time and manual | [billing.md](billing.md) | **Not implemented** — `type` is a free-form string with no enum |
| Activity timeline | [overview.md](overview.md) | Storage only |

Code paths named in these documents describe the implementation the rules were
written against. SVC's own layout is `app/Services/Billing/`,
`app/Services/Engagement/`, `app/Models/`, and `resources/js/pages/`; the
concepts map across, the namespaces do not.

---

## Quick links

- **[Overview](overview.md)** — architecture, schema, models, controllers, routes, and workflows.
- **[Setup](setup.md)** — one-time bootstrap: migrations to run, how to mark the first admin, how to test the feature end-to-end.
- **[Billing](billing.md)** — billing hub: prior-period model, cadence/cycle fields, rollover, minimum-availability (catch-up) rule, line items, balance fields, recurring items, agreement transitions.
- **[Cadence billing & regeneration](cadence-billing.md)** — invoice period (`period_*` vs `cycle_*`), one-cycle offset, numbering, regeneration rules + legacy `period == cycle` migration, interim overage invoices.
- **[Milestone billing](milestone-billing.md)** — flat-fee deliverable billing via `milestone_price`.
- **[Payments](payments.md)** — payment methods, validation, status transitions, and the payments UI.
- **[CLI](cli.md)** — admin Artisan commands for invoice listing, manual payments, and time-entry creation.
- **[Stripe billing](stripe-billing.md)** — online invoice payments, saved payment methods, payment cap, and webhook behavior.
- **[Deferred billing](deferred-billing.md)** — per-entry flag that lets admins complete work now and bill for it only when retainer capacity exists.
- **[Overpayment credits](overpayment-credits.md)** — any overpaid amount carries forward as a credit on the next invoice(s) and never expires.
- **[Subcontractors](overview.md#subcontractors)** — project-scoped subcontractors with scoped portal access, self-logged + admin-approved hours, and flat-hourly / retainer / direct billing modes.


## Where this lives in SVC

**Backend** (`app/`):

- Models: `app/Models/` — `ClientCompany`, `ClientProject`, `ClientTask`,
  `ClientTimeEntry`, `ClientAgreement`, `ClientAgreementRecurringItem`,
  `ClientProposal`, `ClientInvoice`, `ClientInvoiceLine`,
  `ClientInvoicePayment`, `ClientBillingSchedule`
- Billing services: `app/Services/Billing/`
- Engagement services (proposals, agreements, time): `app/Services/Engagement/`
- Controllers: `app/Http/Controllers/Billing/`, `app/Http/Controllers/Engagement/`
- Agent/MCP surface: `app/Http/Controllers/Api/V1/`, `app/Services/Mcp/`

**Frontend** (`resources/js/pages/`): `operations.tsx` (internal) and
`portal.tsx` (client-facing).

**Views**: `resources/views/invoices/` (PDF and email templates only).

**Tests**: `tests/Feature/Billing/`, `tests/Feature/Engagement/`.

Every tenant-owned model is workspace-scoped through
`App\Models\Concerns\BelongsToWorkspace` and exposes a UUID `public_id`; the
documents below predate that convention and refer to bare integer keys.

## High-level flow

1. Admin creates a **client company**, invites users, and signs an **agreement** (retainer, hourly rate, billing cadence, rollover months, catch-up threshold).
2. Team members log **time entries** against company projects/tasks through the portal. Entries may be flagged `is_deferred_billing` to defer billing until capacity exists.
3. Admin configures optional **recurring items** on the agreement for fixed-fee monthly, quarterly, semi-annual, annual, or one-time charges.
4. Admin "Generates Invoices" → **draft** invoices are created for each monthly, quarterly, or annual cadence cycle. Drafts auto-regenerate when time entries change. Issued/Paid/Void invoices are immutable.
5. For non-monthly agreements with `bill_overage_interim = true`, interim overage invoices can be emitted at completed month boundaries inside the current cadence cycle.
6. Payments are recorded against invoices. Overpayments automatically become **credits** applied to the next draft invoice.
7. On **agreement transition**, the outgoing agreement is terminated, a successor agreement is created, rollover can be carried forward, and activity log rows record the change.
8. On **agreement termination**, outstanding deferred entries are force-billed at the hourly rate on the final invoice.

## Conventions

- Tenancy: every query and write is workspace-scoped. See `AGENTS.md`.
- Monetary math: amounts are integer minor units end to end; see
  `app/Services/Billing/MoneyService.php`. The documents below were written
  against decimal-cast columns and quote dollar amounts accordingly.
- Identifiers: external surfaces use `public_id` (UUID), never the integer key.
- Testing: PHPUnit for backend. There is no frontend test runner in the
  repository yet; the component tests these documents refer to were not
  carried across.
