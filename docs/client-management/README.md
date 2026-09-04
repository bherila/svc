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
| Subcontractor billing modes | [overview.md](overview.md#subcontractors) | Implemented at the time-entry snapshot boundary — `flat_hourly` bills separately, `retainer` draws on the agreement pool, and `direct` is tracked but never invoiced. Existing cost-bearing rows are migrated to `flat_hourly`; the source importer carries all three modes and refuses incomplete or unknown terms |
| Invoice line types beyond time and manual | [billing.md](billing.md) | Implemented — see `App\Support\Billing\InvoiceLineType` |
| Activity timeline | [overview.md](overview.md) | Implemented — imported history and native agreement, invoice, payment, Stripe, and saved-payment-method events share one tenant-scoped timeline |

### What the replay treats as a money difference

The bar is that money is exact. That is now judged per line rather than per
invoice: a line whose price, quantity or tax moved is a money difference even
where the invoice total lands in the same place, because a charge the client did
not have is not the same money differently arranged. What stays reported rather
than blocking is the same money attributed differently - a changed service date,
project, or recurring item with every amount identical.

A line's *type* is on the reporting side too. A charge reclassified from one
category to another with every amount identical is the same money under a
different name, and reclassification between the capacity-dependent types is
one of the things this port changes on purpose.

Hours are the documented exception and still never gate: the source stored
fractional hours and this schema derives them from whole minutes.

Charges are paired in two passes: on everything about where a charge is filed,
then - for whatever that could not pair - on what the charge is. Where several
identically worded charges have all moved and carry different prices, pairing
cannot say which became which, and the replay refuses to certify rather than
assume. That costs a report on a narrow case and never passes a repricing.

The four deliberate corrections can waive a divergence in *which* lines a period
carries - a line added, removed, or reclassified. They never waive a difference
in what an existing line costs: a charge keeps its identity across a repricing
and loses it when it is reclassified, added or removed, so prices are compared
per identity and only where the same charge appears on both sides - a correction is a claim about composition, not about price, and the
per-line notes are type-prefixed, so without that rule a repriced line would be
excused by the very correction that explains why its type moved at all.

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

Before any of those billing paths use a time entry, SVC also verifies that the
entry's project belongs to the same workspace and client company named by the
entry. Those keys are independent in this schema. An inconsistent chain stops
billing with a reconciliation error: including it can overbill another project,
while silently filtering it can underbill the client. The read-only time sheet
withholds its capacity strip under the same condition.

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
it cannot, it must fail. The ledger backfill was held to the same standard from
the other direction — it wrote, so reporting was the default and `--apply` was
the flag, and the whole repair was one transaction, because the checks that
decide whether to trust the source can only be answered after the entire source
has been walked. It has since been retired with the importer it read through;
the standard it was held to is the part worth keeping.

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
  Where recorded invoice history begins before its agreement's stored start,
  the replay uses the historical service period as its comparison identity,
  clock, and retainer-ledger opening basis. That aligns the predecessor's
  period-equals-cycle label with the current engine's next-cycle label without
  changing the agreement date. A monetary or structural difference passes only
  when a narrow rule proves the current contract requires it, and there are
  five such rules:

  - whole-minute arithmetic, where the source priced decimal hours;
  - a recurring item's exact opening incidence;
  - a complete configured cadence that predecessor history omitted altogether;
  - the capacity a replay-only opening month sold, consumed once in service
    period order and never beyond its configured rollover window;
  - a same-rate move of billed overage into retainer capacity, accepted only
    as a minute-for-minute transfer at identical currency, rate, tax, scope
    and claims.

  The last two exist because seeding the ledger one period earlier grants the
  opening month capacity that history recorded but did not carry forward.
  An isolated extra cycle and a later missing recurring incidence still fail.
  The whole command is always-rollback, and tested to be.
- `svc:billing:rehearse-generation` answers the operational question directly —
  would running generation change anything a client has already been charged
  for? It fingerprints every column and every line of every settled invoice
  before and after. On production data it watched 25 and found none altered.
  Always-rollback, and tested to be.
- `svc:billing:backfill-ledger` **wrote** — that was its purpose, and it has been
  retired. It repaired columns an earlier import dropped, and refused unless the
  source still agreed with what was imported, column by column, with every
  difference accepted by name. Reporting was the default and `--apply` was the
  flag, so a run meant as a look could not become a write. The whole repair was
  one transaction: the checks that decide whether to trust the source can only be
  answered after the entire source has been walked, and an earlier version
  committed the tables it had finished before returning failure — leaving a
  ledger half repaired from a source it had just decided not to trust.

An earlier version of this section said all three were always-rollback. That was
wrong about the third, and it is the kind of wrong that matters: the sentence was
the reason to believe the command was safe to point at production.

## What is not finished

Tracked on the epic (#14) and the issues named here. The engine is implemented
and green on both database engines; what is listed below is either work not
started, or a defect found by review and not yet fixed.

Every defect this table carried in its earlier versions is now closed: the
correction range that could resell a sold cycle (#79), the draft interim invoice
that stranded its work (#80), the milestone claims that were not reconstructed
(#82), the opening balances a fresh import dropped (#83), the `mysql` driver
pointed at a MariaDB server (#78), the operator time-entry screen (#74), the
storage-only activity timeline (#77, #107), the unrepresented subcontractor
billing modes (#76), and the replay against migrated production data itself
(#73). That is worth stating rather than quietly deleting the rows, because the
same table said for several revisions running that two of those findings moved
money and should not be outstanding when this bills a real client.

The two rows that move money now are #134 and #135, and both were found by the
null-semantics audit rather than by review of a change.

| Remaining | Why it is open | Tracked |
| --- | --- | --- |
| Opening rollover seed never fires | `InvoiceLedgerBuilder` reads `initial_rollover_hours`, which is neither a column nor an accessor, so the read is always null and the seed month is never built. Every agreement migrated mid-life opens at zero carried capacity, understating available hours and overstating overage. The replay path is unaffected — it reads the minutes column through its own DTO. | #134 |
| Unguarded nulls in billing math | Four of them. The consequential one drops a charged invoice with no service period out of the billed-overage sum, so its overage can be charged a second time; the others parse a null into "now" or raise where a fallback was intended. | #135 |
| Client expenses | No table. The source had no rows, so nothing was migrated and nothing is lost — the generator hook sits beside the milestone one if it returns. Scope is now recorded on the issue: reimbursable pass-through at cost, receipt attachments, an approval workflow, and recurring expenses whose every occurrence is approved after it recurs and before it can be invoiced. | #75 |
| Time-entry write paths disagree | `store()` routes through a collaborator that performs no authorization of its own, while update, destroy and approve all go through the service that owns the policy. Resolved in favour of project-level parity; not yet implemented. | #101 |
| Load-bearing NULLs on billing columns | The registry pins each nullable billing column to the behaviour its null selects. 28 of 66 columns are cited against tests; 38 remain audited-but-uncited, having no null branch to cite. | #115 |
| Lock-order registry | Pessimistic lock acquisition order is neither documented nor asserted. Unblocked now that the composite tenant keys have landed. | #117 |
| Replay correction mutants | The diff gate now covers changed PHP throughout `app/`, reports no-op results explicitly, and gates covered-code MSI. The known replay DTO survivors remain the next focused test-quality slice. | #132 |
| The rest of the operator and portal surface | Agreement detail, project detail, invoice detail, the all-invoices view, inviting people, and the portal's four manage pages. Client list and detail are in flight. Unlike everything else in this table, most of this is not yet tracked as issues. | #14 |

### What the replay says now

Three passes at this, and the first two were measuring the harness rather than
the engine. That is worth saying plainly, because the numbers in each version of
this document were stated with more confidence than they had earned.

**The harness was manufacturing its own findings.** It blanked invoices for
regeneration and left the payment rows behind, so the credit ledger read every
settled invoice in history as overpaid by its full amount. Eight divergences
were its own doing.

**The classifier was waiving what it most needed to report.** `retainer` sat in
the capacity-dependent allowlist, so ten whole-invoice disappearances were
explained away on the strength of `rollover_months > 0`. No capacity correction
can move a contracted fee.

**And the import was reading rows the source had deleted.** This was the big
one, and it hid behind the other two. Of 78 invoices, 49 were soft-deleted in
the source; of 822 invoice lines, 764; of 455 time entries, 184. All of it
arrived as live data.

| | first run | harness fixed | import fixed |
| --- | ---: | ---: | ---: |
| reproduce exactly | 4 / 42 | 11 / 42 | **19 / 24** |
| same total, lines arranged differently | 8 | 13 | **0** |
| explained by a deliberate correction | 10 | 0 | 0 |
| unexplained | 15 | 13 | **5** |
| distinct reasons the generator refused | — | 5 | **1** |

The one remaining refusal is `zero_activity_non_retainer`, which is a deliberate
skip rather than a failure. Of the five unexplained, four are legacy-period
pairings whose cycle total moves, and one is a recurring-item increase.

#### A conclusion this document got wrong

An earlier version of this section said:

> **14 of the 78 historical invoices have a stored total that does not equal the
> sum of their lines**, ten of them settled. Those cannot be asked of the engine
> as if they were a target.

That was wrong, and it was wrong in the most expensive direction: it attributed
a defect in this code to the data it was reading, which is the one conclusion
that stops you looking. An independent review reached it too, from the same
exported artifacts, which is worth remembering the next time two sources agree.

Counting only the lines the source still has, the number of invoices whose total
disagrees with their own lines is **zero**. The source is consistent. The import
was summing deleted lines into the totals.

The same root cause accounted for the rest of it: the ten periods holding up to
seven invoices each were all deleted drafts, and every refusal the generator
gave was against a deleted row occupying the period. Two whole investigations -
one into duplicate invoices, one into a half-open period convention - dissolved
when the deleted rows stopped being imported.

#### What is left

- **Four legacy-period pairings** whose cycle total moves. These are the
  one-cycle offset: the predecessor billed `period == cycle`, this engine sells
  the month ahead, and pairing whole invoices by cycle aligns the retainer while
  misaligning the work.
- **One recurring-item increase** of a single unit.
- **Attribution is still by opportunity, not causation.** There are four
  corrections and sixteen combinations of them. A divergence is explained when
  disabling one correction moves the result back to history, with a trace naming
  the lot that expired or the entries whose allocation changed - not when a
  feature happens to be enabled. Until that exists, read "explained" as "not yet
  shown to be a regression".

Interim overage invoices deserve a specific caveat: they are implemented and
tested, and production has never produced one (75 cadence-period invoices, 3
ad-hoc, 0 interim). The tests are the only exercise this path has ever had.

#### How it was closed

#73 was decided in favour of a **replay-only history**: the replay seeds its
basis one period before the recorded agreement start, and the agreements' own
start dates are left exactly as the source wrote them. Rewriting those starts to
make the comparison agree was considered and rejected — the comparison bends to
history, and the record of what was sold does not bend at all. The seed lives
only in the replay's basis, never in the agreement, and the tests assert that
after a replay the agreement's `starts_on` is unchanged.

Two residues survive that decision as a separate, named investigation rather
than as part of it: three invoices only the engine produces, and a small
one-directional total divergence. Neither blocks the decision. Both have to be
explained, or shown to be regressions, before the replay is promoted from a gate
into a test.

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
- **[Tenant foreign keys](tenant-foreign-keys.md)** — the composite `(workspace_id, parent_id)` keys that make a cross-tenant reference unstorable, what every new tenant-owned table has to do, and which columns are exempt and why.
- **[Lock order and check-then-act](concurrency.md)** — the one order every pessimistic lock is taken in, derived from recorded transactions and enforced by a conformance test and a static rule; the guards that read a condition and then write, with the lock or constraint that makes each sound; and what a green lock-order run does not prove.


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
- Testing: PHPUnit covers backend behavior; Vitest and Testing Library cover
  behavior under `resources/js` through `pnpm test`. Any control that starts an
  Inertia mutation stays disabled until `onFinish`, and its component test must
  prove a second activation cannot dispatch another request. `composer
  ci:check` runs both suites.
