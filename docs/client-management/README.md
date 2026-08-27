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

SVC now implements most of it. The billing engine was ported from that
implementation rather than rewritten, so these rules describe running code — but
check the table below before assuming any particular one is live, and note the
places where SVC deliberately diverges.

| Capability | Rules documented in | SVC status |
| --- | --- | --- |
| Client companies, projects, tasks | [overview.md](overview.md) | Implemented — portal access can be narrowed to named projects, and that narrowing holds on the portal page, the read API, attachments, proposals and agreements alike |
| Agreements and recurring items | [billing.md](billing.md) | Implemented — but `billing_cadence` defaults to `one_time` at the database level and one-time agreements generate no cycle invoices, so an agreement created without an explicit cadence bills nothing |
| Time entries (log, approve, allocate) | [overview.md](overview.md) | Write/approve exist on the agent API; no operator UI |
| Invoice lifecycle (draft → issued → paid → void) | [billing.md](billing.md) | Implemented |
| Invoice from selected time | [overview.md](overview.md#time-entry-splitting--allocation) | Implemented; reachable from the web UI |
| Payments and balances | [payments.md](payments.md) | Implemented |
| Stripe payment intents and webhooks | [stripe-billing.md](stripe-billing.md) | Implemented |
| Invoice email delivery | [billing.md](billing.md) | Implemented |
| Recurring billing schedules | [cadence-billing.md](cadence-billing.md) | Implemented |
| Retainer draw-down | [billing.md](billing.md) | Implemented |
| Rollover of unused retainer hours | [billing.md](billing.md) | Implemented — ages by elapsed calendar months, which the predecessor did not |
| Cadence cycles and the one-cycle offset | [cadence-billing.md](cadence-billing.md) | Implemented |
| Interim overage invoices | [cadence-billing.md](cadence-billing.md) | Implemented — never exercised in production, and the tests are its only exercise |
| Deferred billing allocation | [deferred-billing.md](deferred-billing.md) | Implemented |
| Milestone billing | [milestone-billing.md](milestone-billing.md) | Implemented — the billing line is a column on the task, not a pivot, since a deliverable cannot be split |
| Overpayment credits | [overpayment-credits.md](overpayment-credits.md) | Implemented — one currency only, and re-checked when an invoice is issued. An overpaid invoice owes nothing rather than a negative balance; the column is unsigned and would refuse the write |
| Client expenses | [overview.md](overview.md#client-expenses) | **Not implemented** — no table, and the source had no rows. The generator hook sits beside the milestone one if it returns |
| Subcontractor billing modes | [overview.md](overview.md#subcontractors) | **Partial** — `subcontractor_cost_amount` is the flat-hourly signal and is billed as its own line and excluded from retainer draw; the `retainer` and `direct` modes have no representation. No source rows use any of it |
| Invoice line types beyond time and manual | [billing.md](billing.md) | Implemented — see `App\Support\Billing\InvoiceLineType` |
| Activity timeline | [overview.md](overview.md) | Storage only |

### Generation never touches a settled invoice

The four corrections below change what a period costs. That is intended for work
not yet billed and unacceptable for work already billed: an issued or paid
invoice is a statement the client has seen and usually settled against.

`svc:billing:rehearse-generation --workspace=<id>` runs a real generation inside
a rolled-back transaction and compares every column and every line of every
settled invoice before and after. Against production data it watched 25 settled
invoices, found none altered, and reported the 4 invoices a real run would
create. `SettledInvoicesUntouchedTest` holds the same property for every settled
status, including void.

### Where SVC deliberately differs

Four behaviours were corrected rather than reproduced, because the
implementation these documents were written against had them wrong. Each moves
money, so they are named here rather than left to be rediscovered:

- **Rollover expiry** ages by elapsed calendar months. The original counted
  stored non-zero balances, so a month that used its whole retainer was
  invisible to the ageing and older hours stayed spendable past their window.
- **Deferred time** does not draw on the retainer pool until the allocator
  actually bills it. Counting it up front consumed capacity nothing had taken
  and produced catch-up charges to restore it.
- **A project-scoped agreement** counts only its own project's work. The
  original pooled the whole company, so two project agreements each counted the
  other's hours.
- **A recurring item's start-date fallback** applies only in the item's own first
  month. The original applied it whenever a cycle opened mid-month, re-billing an
  anchor the previous cycle had already charged.

Also absent by choice: `first_cycle_proration` governs how the opening cycle is
*priced*, not where its boundaries fall — the active-date anchor wins on
boundaries, as the original's own tests assert.

Code paths named in these documents describe the implementation the rules were
written against. SVC's own layout is `app/Services/Billing/`,
`app/Services/Engagement/`, `app/Models/`, and `resources/js/pages/`; the
concepts map across, the namespaces do not.

## How this code fails, and what has been done about it

Two review passes over the port found 22 defects that its tests did not. They
were not 22 unrelated mistakes. Almost all were one of three shapes, and each
shape now has something structural holding it shut rather than a fix at the site
where it was noticed.

**A rule stated in one place and restated in another.** "Which work draws on the
retainer" was written out at four call sites and correct at three. The project
narrowing reached the ledger builder and not the monthly path — four more
queries, found on the fourth pass over the same question. The balance owed, the
invoice totals, a month's retainer hours: each derived independently by two or
three callers, and wrong in at least one.

The fix in every case was one definition and a test that fails if a caller
restates it. `ClientTimeEntry::scopeRetainerBillable()`,
`scopeForAgreementScope()`, `ClientInvoice::balanceOwed()`,
`ClientInvoice::totalsFromLines()`, `RetainerCalculator::retainerHoursForMonth()`,
`App\Support\Billing\InvoiceStatus`. Four guard tests scan the billing sources
and fail when a rule is written by hand again. **Adding a call site that asks one
of these questions and answers it locally is the single most likely way to
reintroduce a money defect here.**

**A list that goes stale.** The predecessor's invoice status column had four
values; this schema has five. Every exhaustive four-value list ported from that
world was silently wrong, and wrong in the permissive direction — an invoice
omitted from a guard. The same shape appeared as a denylist: the lines excluded
from an invoice's service period named retainer and credit, so recurring items,
dated in the month ahead by design, pushed the period forward and the overlap
guard then refused to generate that month *for good*, swallowing the refusal.
A client silently stopped being invoiced.

Prefer an allowlist wherever the consequence of a missing entry is that
something is billed, skipped, or hidden. A new line type that is absent from
`InvoiceLineType::definingTheWorkPeriod()` makes an invoice's period too narrow,
which is visible; under the denylist it made the period too wide, which stopped
billing and said nothing.

**A default chosen where the concept does not apply.** `billing_cadence` defaults
to `one_time`, `BillingCadence` has no such case, and the model answered
"monthly" for anything it did not recognise — so an arrangement bought once was
billed a retainer every month, on the default path. `InvoiceStatus::fromStored()`
had the same shape: unknown collapsed to draft, which is the least privileged
state to *read* and the most permissive to *write*, so three safety questions
answered "go ahead" for a status nobody understood.

When a fallback answers a question about permission or money, the safe default is
the restrictive one. `isSettledValue()` and `hasChargedValue()` treat an
unrecognised status as untouchable.

### The engine gap underneath all of it

The suite ran on SQLite while production runs MariaDB, and SQLite accepts what
MariaDB refuses. That hid two production defects outright — a `h:mm` string
written into a decimal column, and a negative balance written into an unsigned
one — and both surfaced only when the suite was pointed at the real engine. It
hid a third in reverse: a date comparison that was correct on MySQL and wrong on
SQLite, which passed against real data and failed the moment a test ran it on the
other engine. The `mariadb` CI job exists because of this and deploy waits on it.

### Rehearsing against real data

Three commands run against production data and none of them writes. Each is
always-rollback, and each is tested to be:

- `svc:billing:replay` regenerates history and classifies every divergence.
- `svc:billing:rehearse-generation` answers the operational question directly —
  would running generation change anything a client has already been charged
  for? It fingerprints every column and every line of every settled invoice
  before and after. On production data it watched 25 and found none altered.
- `svc:billing:backfill-ledger` repairs columns an earlier import dropped, and
  refuses unless the source still agrees with what was imported, column by
  column, with every difference accepted by name.

## What is not finished

Tracked on the epic (#14) and the issues named here. Nothing below blocks the
billing engine itself, which is implemented and green on both engines.

One thing to know before merging: **PR #71 is the base of #72 and is frozen.**
Seven findings raised against #71 were fixed on #72's branch rather than its
own, so #71 in isolation still contains them - a portal that lists unapproved
time, an agreement validator that throws an unrenderable exception, invoice
lines exposing internal ids, and the `one_time` cadence billing monthly. Merge
#71 and #72 together, or land the fixes on #71 first.

| Remaining | Why it is open | Tracked |
| --- | --- | --- |
| Replay against production data | **Ran, and does not yet reproduce.** Of 42 comparable invoices: 4 exact, 8 the same total through differently arranged lines, 10 explained by a deliberate correction, 15 unexplained, 5 structural. Only the unexplained count fails a run — demanding an exact match asks the engine to reproduce bugs it was fixed not to have. Fixing four genuine mis-billing defects did not move these counts at all, which is itself unexplained. | #73 |
| Operator UI for time entries | Logging and approval exist on the agent API and the CLI; there is no screen. Everything downstream of a time entry has one. | #74 |
| Client expenses | No table. The source had no rows, so nothing was migrated and nothing is lost — the generator hook sits beside the milestone one if it returns. | #75 |
| Subcontractor `retainer` and `direct` modes | Only flat-hourly has a representation here. No source rows use any mode, so this is a gap in the model rather than in the data. Flat-hourly work is excluded from retainer draw and billed as its own line, and a cost in another currency is refused rather than billed unconverted. | #76 |
| Activity timeline | Rows are written; nothing reads them. | #77 |
| Laravel `mariadb` driver | Production sets `DB_CONNECTION=mysql` against a MariaDB 10.6 server. The drivers differ on defaults, `uuid` and JSON handling. Nothing has been attributed to it; CI matches production deliberately, so a switch has to happen in both places at once. | #78 |

Interim overage invoices deserve a specific caveat: they are implemented and
tested, and production has never produced one (75 cadence-period invoices, 3
ad-hoc, 0 interim). The tests are the only exercise this path has ever had.

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
