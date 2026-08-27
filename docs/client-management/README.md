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

Five review passes over the port found 62 defects that its tests did not. They
were not 62 unrelated mistakes. Almost all were one of five shapes, and each
shape now has something structural holding it shut rather than a fix at the site
where it was noticed.

The first three are about the code. The last two are about the things built to
check the code, which turned out to fail in their own characteristic ways — and
those are worse, because a broken check does not merely miss a defect, it
certifies its absence.

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

**A check that measures its own interference.** The replay blanks invoices so
the generator can rebuild them, and left the payment rows behind. But
`OverpaymentCreditService` derives credit from payment rows measured against
`total_amount`, so every settled invoice in history read as overpaid by its full
amount and every regenerated invoice drew on a credit pool that had never
existed. Eight of the divergences the harness reported were its own. It had also
been clearing ad-hoc invoices — which it never regenerates and never compares —
releasing their claims to the cadence generator, which then billed the same work
again and reported the second charge as a finding.

A harness that mutates state to observe it has to be asked what else reads that
state. Both fixes were subtractive: stop touching what is not being compared.

**A check that reports success for a case it never examined.**
`svc:billing:rehearse-generation` caught exceptions to decide whether generation
was safe, but `generateAllInvoicesForAgreement()` catches each period's throwable
and returns it as a skip carrying an `error` — so every period of every company
could fail and the command would still print that generation is safe to run. The
same shape, twice more: the restore verifier walked the source and skipped
anything it could not map, so a restore missing rows produced no drift and read
as verified; and the rehearsal called an empty workspace safe.

The rule these share: a check must distinguish *passed* from *did not run*. Where
it cannot, it must fail. `svc:billing:backfill-ledger` is held to the same
standard from the other direction — it writes, so reporting is the default and
`--apply` is the flag, and the whole repair is one transaction, because the
checks that decide whether to trust the source can only be answered after the
entire source has been walked.

### The engine gap underneath all of it

The suite ran on SQLite while production runs MariaDB, and SQLite accepts what
MariaDB refuses. That hid two production defects outright — a `h:mm` string
written into a decimal column, and a negative balance written into an unsigned
one — and both surfaced only when the suite was pointed at the real engine. It
hid a third in reverse: a date comparison that was correct on MySQL and wrong on
SQLite, which passed against real data and failed the moment a test ran it on the
other engine. The `mariadb` CI job exists because of this and deploy waits on it.

### Rehearsing against real data

Three commands run against production data. Two of them cannot write at all;
the third writes only when told to, and only if every check passes.

- `svc:billing:replay` regenerates history and classifies every divergence.
  Always-rollback, and tested to be.
- `svc:billing:rehearse-generation` answers the operational question directly —
  would running generation change anything a client has already been charged
  for? It fingerprints every column and every line of every settled invoice
  before and after. On production data it watched 25 and found none altered.
  Always-rollback, and tested to be.
- `svc:billing:backfill-ledger` **writes** — that is its purpose. It repairs
  columns an earlier import dropped, and refuses unless the source still agrees
  with what was imported, column by column, with every difference accepted by
  name. Reporting is the default and `--apply` is the flag, so a run meant as a
  look cannot become a write. The whole repair is one transaction: the checks
  that decide whether to trust the source can only be answered after the entire
  source has been walked, and it used to commit the tables it had finished
  before returning failure — leaving a ledger half repaired from a source it had
  just decided not to trust.

An earlier version of this section said all three were always-rollback. That was
wrong about the third, and it is the kind of wrong that matters: the sentence was
the reason to believe the command was safe to point at production.

## What is not finished

Tracked on the epic (#14) and the issues named here. The engine is implemented
and green on both database engines; what is listed below is either work not
started, or a defect found by review and not yet fixed. Three of the latter
(#79, #80, #82) change what a client would be charged.

#71 and #72 are both on `main`. Five review findings were merged with them
rather than silently, and two of those move money: a monthly correction range
can resell a cycle an earlier invoice already sold (#79), and a draft interim
invoice claims work the cadence invoice then cannot see (#80). Neither should
be outstanding when this bills a real client.

| Remaining | Why it is open | Tracked |
| --- | --- | --- |
| Replay against production data | **Ran again on the fixed harness. 11 of 42 reproduce exactly, 13 do not, and the reason is no longer capacity arithmetic.** See below. | #73 |
| Operator UI for time entries | Logging and approval exist on the agent API and the CLI; there is no screen. Everything downstream of a time entry has one. | #74 |
| Client expenses | No table. The source had no rows, so nothing was migrated and nothing is lost — the generator hook sits beside the milestone one if it returns. | #75 |
| Subcontractor `retainer` and `direct` modes | Only flat-hourly has a representation here. No source rows use any mode, so this is a gap in the model rather than in the data. Flat-hourly work is excluded from retainer draw and billed as its own line, and a cost in another currency is refused rather than billed unconverted. | #76 |
| Activity timeline | Rows are written; nothing reads them. | #77 |
| Correction range can resell a sold cycle | A disjoint monthly correction derives the same `cycle_start` as the invoice that already sold that retainer, and the service-period overlap guard does not see it. Bills the retainer and its recurring items twice. | #79 |
| Draft interim invoices strand their work | A missing interim invoice is created as a draft and immediately claims its entries; the cadence selector skips claimed entries and the reconciliation skips drafts, so the work is billed by neither. | #80 |
| Replay compares line totals per type | Two lines of the same type moving by opposite amounts report no difference, and the snapshotted unit and tax amounts are discarded. The command's cent-level claim is overstated until this is a multiset comparison of individual lines. | #81 |
| Milestone claims are not reconstructed | The migration adding `client_invoice_line_id` leaves it null for every existing task, so a database with issued milestone lines will have those deliverables charged again. Blocks enabling generation against imported data. | #82 |
| Fresh imports drop opening balances | `starting_unused_hours` and `starting_negative_hours` are repaired by the backfill command but absent from `ExternalImportService`'s invoice mapping, so a new onboarding stores nulls. | #83 |
| Laravel `mariadb` driver | Production sets `DB_CONNECTION=mysql` against a MariaDB 10.6 server. The drivers differ on defaults, `uuid` and JSON handling. Nothing has been attributed to it; CI matches production deliberately, so a switch has to happen in both places at once. | #78 |

### What the replay says now

The numbers the previous version of this document carried were produced by a
harness that was manufacturing its own findings, and by a classifier that was
waiving the thing it most needed to report. Both are fixed, and the run has been
repeated against the migrated production data. Of 42 comparable invoices:

| | before | now |
| --- | ---: | ---: |
| money identical | 4 | **7** |
| identical once the legacy period convention is allowed for | — | **4** |
| same total, lines arranged differently | 8 | 13 |
| differs, explained by a deliberate correction | 10 | **0** |
| differs, unexplained | 15 | 13 |
| not generated / generated with no counterpart | 2 / 3 | 2 / 3 |

Three things are worth reading out of that.

**Exact reproduction went from 4 to 11.** Most of the difference was the harness
crediting regenerated invoices against overpayments it had invented. That was
never the engine.

**Nothing is explained by a deliberate correction any more, and that is the
honest answer.** The old predicate asked whether a correction was switched on,
not whether it had done anything, and `retainer` sat in its allowlist — so ten
whole-invoice disappearances were waived on the strength of `rollover_months > 0`.
The predicates are narrower now and explain none of the 42. Attribution by
opportunity was worth less than nothing: it was hiding exactly the rows that
needed reading.

**11 of the 13 unexplained divergences move the retainer line, and 12 of 13
change the line count.** That is not the shape of capacity arithmetic being
slightly off. It is the shape of an invoice being generated with a different set
of lines, or not generated at all — identity, period pairing and generation
skips, not rollover. The next investigation should start there, and #73 carries
the detail.

One finding is about the source rather than the port: **14 of the 78 historical
invoices have a stored total that does not equal the sum of their lines**, ten of
them settled. Those cannot be asked of the engine as if they were a target. They
need classifying as bad history, and where a settled invoice's header and lines
disagree, the answer is whatever the client was actually sent.

Two things still block adjudicating the rest:

- **`rollover_months` is contradictory.** `billing.md` says `1` means hours are
  usable only in the month earned; `RolloverCalculator` and its tests let
  February spend January's hours at `1`. Both rollover-bearing agreements in the
  production data use exactly that value, so no rollover divergence can be
  settled until the contract is decided one way or the other.
- **Attribution should be counterfactual, not predicate-based.** There are four
  corrections and therefore sixteen combinations. A divergence is explained when
  disabling one correction moves the result back to history, with a trace naming
  the lot that expired or the entries whose allocation changed — not when a
  feature happens to be enabled.

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
