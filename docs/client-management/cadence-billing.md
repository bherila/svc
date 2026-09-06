# Cadence Billing & Invoice Regeneration

How cadence-period invoices are dated, numbered, regenerated, and how interim overage invoices fit in. Part of the [Billing & Invoicing System](billing.md); see [Core Concepts](billing.md#core-concepts) for the prior-period model, cadence/cycle fields, rollover, and the minimum-availability rule that this page builds on.

## Invoice Period
Cadence-period invoices use a prior-period model for every billing cadence:

- **`period_start` / `period_end`** describe the work/reconciliation period being billed.
- **`cycle_start` / `cycle_end`** describe the retainer period being billed in advance.

Monthly agreements are the one-month version of this model: January work (`period_*`) appears on the February invoice, which bills February's retainer (`cycle_*`). Non-monthly agreements use the same one-cycle offset: work in a January-March quarterly cycle appears on the April-June invoice.

The first retainer period is advance-only. Its `period_*` columns point at the prior cycle before the agreement starts, while the retainer line bills the first active cycle. Termination invoices reconcile the final worked period without billing a retainer after the termination date.

The retainer fee line (dated at `cycle_start`) does **not** expand the invoice period. This prevents overlapping period errors when generating subsequent work invoices.

The invoice **number** (`PREFIX-YYYYMM-NNN`) follows a single rule regardless of cadence length: it is keyed to the **first month of the retainer period billed in advance** — i.e. `period_end + 1 month`. A monthly retainer for June is issued June 1 after reconciling May work → `...-202606-...`; a quarterly invoice reconciling January-March work and billing April-June is numbered `...-202604-...`.

For interim overage invoices, `period_start` / `period_end` describe the completed monthly slice being billed, while `cycle_start` / `cycle_end` identify the parent non-monthly work cycle that will be reconciled by the next cadence-period invoice.

### May these columns be null on a live invoice?

Asked and answered by #224, because three duplicate-invoice guards read them
through SQL equality and SQL compares a null to a value as UNKNOWN — so a null
does not merely mean "unknown", it silently removes the row from the guard that
exists to find it.

**`service_period_start` / `service_period_end` — no, not on a live invoice of a
period-based kind.** A cadence-period or interim-overage invoice *is* a claim
about a span; one that states no span cannot be told apart from any other, and
the guards that place it are entitled to assume it.

**What the evidence covers, and what it did not.** For the **end** boundary,
production carries none: `billing_audit_unplaceable_invoices` reports
`without_a_service_period: 0` of 29 invoices, and `undated: 0` collectible.

That number says nothing about the **start** boundary, and an earlier draft of
this section cited it as though it did. `UnplaceableInvoiceAuditor` counted
`whereNull('service_period_end')` and only that, so a live invoice stating an
end and no start was legal in the schema, invisible to `generateDue()`'s
start-date comparison, and invisible to the audit that was supposed to rule it
out — the same null-in-a-predicate class this whole section is about, reproduced
in the instrument used to measure it. The audit now reports
`without_a_service_period_start` and `unplaceable_by_a_period_guard` beside it.

So the claim here is deliberately split: **the end boundary is evidenced clean;
the start boundary is now measurable and has not yet been measured against
production**, because the count ships with this change and reaches the audit
only on deploy. Run `svc:billing:audit-unplaceable-invoices` — or the MCP tool —
afterwards. A non-zero `unplaceable_by_a_period_guard` is a repair, not a guard
change, and must happen before the issue-time invariant in #251 is safe to
turn on.

That second count mirrors the guard rather than tidying it, in three ways that
each looked like a simplification and each hid a real exposure:

- **Both boundaries.** The guards compare a period at both ends, so a row
  stating a start and no end is exactly as invisible as one stating neither.
  Counting only the start printed an all-clear that was false for half the
  population it claimed to cover.
- **Kind narrows only unlinked rows.** `cycleGuardExclusions()` keeps an interim
  or ad-hoc invoice from blocking a cadence one, but `generateDue()` applies
  that only where the invoice names no schedule — a row naming a schedule is
  that schedule's whatever kind it carries. Excluding those by kind hid the
  malformed combination the audit exists to surface.
- **No status filter at all**, unlike every other funnel in that audit.
  `generateDue()` has none either — a voided invoice blocks its period, because
  the replacement would collide with
  `billing_schedule_service_period_unique` — so a *voided* invoice missing a
  boundary defeats the guard exactly as a live one does, and the schedule bills
  a deliberately waived period again with nothing to reject the write. Scoping
  the count to live statuses reported that row as no exposure at all.

The guard does not merely count these rows, either. A schedule that finds an
invoice of its own — or its agreement's — with no complete period refuses the
run and names it, because no date comparison can establish whether it covers the
period being billed and the unique index will not reject the duplicate.

An incomplete period is read as **unbounded in the missing direction**, and it
is fetched by the *same* query and classified by the *same* ownership rules as a
complete one. An earlier revision used a second query for periodless rows, and
the join between the two was a hole in exactly the shape everything here exists
to close: the second query asked a weaker ownership question, so a row whose
schedule link dangled fell through it. It also asked about *every* periodless
row rather than the ones that could reach this period, which halted schedules
over an invoice ending years before the period being billed. Both are gone; one
query, one classification.

The narrowings that keep this from becoming a trap are the ones that apply to
every candidate, not special cases for a missing boundary:

- a row naming *neither* owner and carrying *no* period at all is no evidence of
  anything, and clears. It is reported for repair rather than allowed to stop
  every schedule the client has.
- a **known void** that does not cover exactly this period clears *before*
  ownership is resolved at all. `updateDraft()` accepts nothing but a draft, so
  a voided invoice cannot be given a period or re-keyed in the application, and
  a refusal on one would halt the schedule on every run with a database edit as
  the only way out — while `assertNoOverlappingInvoice()` tells operators to
  void the existing invoice first. Reaching this *before* the lineage refusals
  is what makes that advice true: an earlier revision refused a voided row whose
  lineage dangled and never arrived at the branch that would have cleared it.

**Status is read where it changes the answer, and never guessed.** The column is
a varchar. `InvoiceStatus::isSettledValue()` and `hasChargedValue()` both answer
*yes* to a value they do not recognise, and this follows the same policy: an
unrecognised status **refuses**, because a status this code cannot read is one it
cannot show has charged nobody. The tempting shorthand — "not in `live()`,
therefore harmless" — puts every unknown value on the same path as `void`, which
is the one reading that is unsafe in both directions at once.

The audit still counts every status, because it reports what a guard *reads*
rather than what refuses: a voided invoice with a complete period blocks its
period, and the same row missing a boundary does not, so the period the void was
meant to waive is billed again. That is worth repairing whether or not it halts
a run. It is a **repair ceiling**, deliberately, and it is not the number to gate
a deployment on — see [Sizing the halts before deploying
them](#sizing-the-halts-before-deploying-them).

An **ad-hoc** invoice is the exception and stays nullable: it bills a thing, not
a span, and no period guard asks about it.

**`client_billing_schedule_id` — yes, and it always will be.** `ClientInvoicingService`
creates cadence invoices without ever setting it, and an operator's ad-hoc
invoice has no schedule to name. So this column can never be the thing a
duplicate guard keys on. `BillingScheduleService::generateDue()` used to do
exactly that, and an unlinked invoice for the period was therefore invisible to
it: the schedule concluded the period was unbilled and raised — and *issued* — a
second invoice. The unique index did not help, because a unique index does not
constrain a null.

The guard now matches the tenant and the **overlapping** period first and reads
ownership only to decide **whose** invoice it is. That reading lives in
`BillingPeriodCollisionResolver`, because three successive reviews each found a
real defect inside the single nested `where` clause it used to be, and each fix
added a nesting level to SQL nobody could test branch by branch.

- names *this* schedule → this period is already billed. Block, whatever kind
  the invoice carries: a row this schedule produced is this schedule's.
- names *no* schedule → unclaimed, subject to the two narrowings below. Block.
  This is the fail-closed half, and the whole point.
- names a *different*, resolvable schedule → that is another schedule's period.
  Do not block; a company can hold one schedule per agreement, and blocking here
  would silently stop one of them billing.

The third case is why the fix is not simply "drop the schedule clause": that
would trade a double-charge for lost revenue, and nothing else in the suite
would have noticed.

**Overlap, not equality.** Both boundaries used to be compared for equality, so
an invoice covering July *and* August did not stop August being billed — the
dates were not equal, the row fell out of the query, and the second invoice's
`(schedule, start, end)` tuple was distinct enough for the unique index to
accept it. `assertNoOverlappingInvoice()` has always used inclusive overlap;
this now does too. A **live** invoice that overlaps the period without matching
it is refused rather than treated either way: billing would charge the shared
days twice, skipping would leave the rest of the period unbilled.

Void is the one status that changes this answer, and it changes it in both
directions. A voided invoice covering **exactly** this period still blocks it:
voiding a cadence invoice is the documented way to waive its own cycle, and
regenerating it would write the same
`(client_billing_schedule_id, service_period_start, service_period_end)` and
collide with the unique index anyway. A voided invoice that overlaps *without*
matching does **not** block — it charged nobody, it cannot be re-keyed once
void, and `assertNoOverlappingInvoice()`'s own error tells operators to void the
existing invoice first, which has to remain true. The waiver argument does not
stretch to cover it: waiving is about not reselling *the same* cycle, and
neither a differently-dated span nor a row with no period is that.

**A non-null id is a claim, not proof.** Both lineage columns are unconstrained
integers, so an invoice can name a schedule or agreement that has been deleted
or belongs to another tenant. Reading an unresolvable id as "someone else's"
puts the row back in the one branch that does not block, which reproduces the
original defect exactly. Every non-null id is therefore resolved against the
invoice's *own* workspace and client — the same narrowing
`UnplaceableInvoiceAuditor` already applies to the agreement column — and a row
whose lineage dangles, points at another client, or names a schedule and an
agreement that do not belong together is refused rather than skipped.

**"Unclaimed" is narrower than "null".** A null link is not by itself a claim on
this period, and two further conditions keep the fail-closed arm from becoming
an over-block:

- **Kind.** `InvoiceKind::cycleGuardExclusions()` already says an interim or
  ad-hoc invoice must not block a cadence one, and
  `ClientInvoicingService::assertNoOverlappingInvoice()` honours it. Neither
  kind carries a schedule either, so reading every null as unclaimed quietly
  reversed that decision on the schedule path: an operator's ad-hoc invoice
  sharing the dates was returned as though it were the cadence invoice, and
  `next_run_on` advanced past a period nothing had billed. Both guards read the
  same list, so they cannot drift apart. It applies to *unlinked* rows only —
  an ad-hoc invoice naming this schedule is still this schedule's.
- **Agreement.** A company can hold several, each billing its own periods, and
  `ClientInvoicingService` creates cadence invoices with an agreement and no
  schedule — so an unlinked invoice for these dates may belong to a different
  agreement entirely.

Both over-blocks lose revenue silently, which is the failure mode this guard is
least able to notice, so each has its own test.

**And a row naming no agreement at all is refused, not guessed.** It matches
every schedule the company has, and at most one of them can be the one it
covers, so "unclaimed therefore blocking" makes a single invoice suppress
several: each schedule returns it, each advances its own `next_run_on`, and at
least one agreement goes unbilled for a period nothing charged. Neither silent
answer is available — billing anyway double-charges, skipping loses a period —
so the rule is kept only where it is unambiguous.

The ambiguity test is about **agreements, not schedules**, and an earlier
revision of this fix got that wrong. A cadence invoice does not need a schedule
to exist at all: `ClientInvoicingService` creates them with an agreement and no
schedule, and [`AgreementSelector`](billing.md) treats a client's billing
history as a sequence of agreement segments that can be paused, terminated or
expired. So a client can hold exactly one *active schedule* and several
agreements that have produced invoices, and asking "is there a rival schedule"
declared that unambiguous — the single schedule adopted a row that was never its
own and advanced past its own unbilled period. Asking whether a rival is
currently *due* would be worse still: `next_run_on` is a mutable cursor, and a
schedule that already produced the row has by definition advanced past it.

So:

- the client has **no other agreement and no other active schedule** → nowhere
  else the row could belong, so it blocks. This is #219's case and the ordinary
  one.
- **anything else could own it** → `generateDue()` throws, naming the invoice
  and its period. The transaction rolls back, `next_run_on` does not move, and
  nothing is created, so the run can simply be repeated once someone attributes
  the row.

The refusal is deliberately narrow, per #144's lesson that a refusal on a null
must be checked against the paths that write it. Nothing in this application
writes this shape: `generateDue()` and `ClientInvoicingService` both set the
agreement, and `createDraft()` without one stamps `ad_hoc`, which the kind
condition has already excluded — it coalesces an absent *or explicitly null*
kind, so even asking for a null kind there does not produce one. Only a
migrated or hand-repaired row reaches it.

**A refusal aborts the whole run, not just its period.** The throw is inside the
`DB::transaction` wrapping every period the schedule is due for, so a refusal on
the third discards invoices already created for the first two. That is
deliberate: `createDraft()` and `issue()` each mutate invoices, activities and
time entries, and a half-applied run leaves `next_run_on` pointing into the
middle of a batch with some periods billed. All-or-nothing is recoverable by
re-running; half-applied is not. Classifying every period up front, before
creating anything, would avoid the wasted work — #252.

**A pending draft neither bills the period nor advances past it.** An invoice
covering *exactly* the period being billed reports it as already billed — unless
it is a **draft**, which has charged nobody. That case stops the run and names
the draft.

An earlier revision reported a draft as already billed, and it is worth being
precise about why that was wrong, because the argument for it was reasonable.
Regenerating an exact match would collide with
`billing_schedule_service_period_unique`, so the schedule genuinely cannot just
raise another invoice. But advancing `next_run_on` past it meant the period was
recorded as done when no money had been asked for — and nothing brought it back:
`InvoiceLifecycleService::discardDraft()` turns the draft into a **void** invoice
that keeps its period, so even rewinding the cursor met an exact void and
honoured it as a deliberate waiver. The period was silently never billed.

The unique-index argument does not even apply to the row that actually shows up
here. The committed drafts in this system are the *other* generator's:
`ClientInvoicingService` creates cadence invoices with an agreement and **no
schedule**, and they sit as drafts while somebody reviews them. Their
`client_billing_schedule_id` is null, so the index never constrained them. A
schedule's own draft is not this shape — `generateDue()` creates and issues in
one transaction, so a failure rolls it back and a success commits it issued.

So the run stops, and the remedy is the thing the operator was going to do
anyway: **issue** the draft and the next run advances without billing again;
**void** it and the waiver is honoured deliberately rather than by accident.
Pinned by `BillingWorkflowTest::test_a_pending_draft_for_the_period_neither_bills_it_nor_advances_the_schedule`
and its two companions. #251 tracks the issue-time invariant that would narrow
the surrounding exposure further.

**A refusal is always safe, but it is not always cheap.** Nothing is billed
twice and no period is skipped — that part is unconditional. Whether the run can
simply be repeated depends on the row it names: a draft can be discarded and an
issued invoice voided, but `InvoiceLifecycleService::void()` throws once
`paid_amount > 0`, and `updateDraft()` rewrites no period or lineage column at
any status. A refusal naming a **paid** invoice therefore halts that schedule on
every run until a financial correction is made outside this code path. That is
still the right answer — the alternatives are charging the client twice or
silently skipping a period nobody billed — but the messages say what would
actually clear each case rather than offering a repair the application refuses
to perform.

### Sizing the halts before deploying them

Because a halt can be a standing one, the blast radius has to be measurable
before the guards reach production rather than discovered one failed cron run at
a time.

```
php artisan svc:billing:preflight-schedule-generation                    # text
php artisan svc:billing:preflight-schedule-generation --format=json      # for a pipeline
php artisan svc:billing:preflight-schedule-generation --through=2026-12-31
```

It walks every **active** schedule's due periods — from `next_run_on` through
the given date, cut by the same `BillingPeriod` arithmetic `generateDue()` uses
— and runs the same resolver on each, stopping where the run would stop. It
reports how many schedules would halt, how many on a refusal versus a pending
draft, and which reason fired. It exits non-zero when it finds any, so a
deployment can gate on it, and prints counts only, so a run against real client
billing records is safe to paste into a public issue.

**It runs the real classifier over the real periods, and that is the whole
design.** The first version of this did not: it classified *rows* with SQL that
re-derived the resolver's decisions, and it was wrong in both directions — it
cleared rows that halt production and flagged rows no schedule would reject. The
cause was structural rather than a handful of bugs. Half the resolver's rules
are about a `(schedule, period, invoice)` triple, and "does this invoice cover
the period exactly" has no answer until you name the period. A row-at-a-time
query cannot answer a question that has a period in it, so:

- an exact void with dangling lineage **refuses** while the same row one day
  narrower **clears**, and a row-level check saw one row and one answer;
- a partial overlap could not be counted at all, which the old green line had to
  admit in its own text;
- a sole-owner schedule implicitly claims a row naming neither owner, so an
  unknown status or a missing boundary on one refuses — the row-level version
  called it clear;
- an invoice naming a real agreement no schedule bills is *someone else's*, not
  contested — the row-level version failed the gate on ordinary billing history;
- an inactive schedule halts on nothing, because `generateDue()` returns before
  consulting the resolver at all.

Every one of those is now decided by the code that will decide it for real.
`ScheduleGenerationPreflightTest` asserts the prediction and the run agree in
**both** directions for every fixture — a gate that only ever proves "flagged →
halted" can still be uselessly green, which is exactly how the row-level version
shipped its bug.

What it still does not promise: it takes no locks and writes nothing, so it
describes the database as it is now rather than guaranteeing a future run — an
invoice can be issued, voided or re-dated in between. And a schedule with
nothing due is reported as *not due* rather than as clean, because no period of
it was examined.

`UnplaceableInvoiceAuditor` does not answer this question and must not be read
as though it does. "Worth investigating" and "would stop a schedule generating"
have different answers — a voided row is counted there and often cleared by the
resolver — and one number cannot be both without being wrong as whichever one it
is not.

## Regenerating Cadence Invoices

Bulk generation (the admin **Generate Invoices** action / `generateAllInvoices`) walks every retainer period for an agreement and is safe to re-run: re-running refreshes drafts without disturbing a cycle that already has an invoice.

A retainer period is recognized as already invoiced when a `cadence_period` invoice exists whose **retainer cycle** matches — keyed on `cycle_start` / `cycle_end`, not on the work period. This matters because invoices created before the prior-period model stored the billed cycle directly in `period_start` / `period_end` ("period == cycle"); matching on the cycle columns recognizes both the current and the legacy convention, so a legacy invoice is never duplicated under the new period layout.

The existing invoice's status decides the outcome:

- **Issued / Paid** — skipped. The client has already been billed or has paid; the engine never duplicates the charge.
- **Void** — skipped. A voided cadence cycle is treated as deliberately waived and is **not** regenerated. To waive a retainer, **void** the invoice rather than deleting it — a deleted (soft-deleted) draft leaves no row the guard can see and would be regenerated on the next run. Note that `void()` also unlinks the invoice's time entries (returns them to the unbilled pool), so voiding waives the *retainer charge and the cycle's invoice*, not necessarily the underlying work — released entries can still be billed by a termination, ad-hoc, or manual invoice.
- **Draft** — refreshed in place to reflect the current time entries, expenses, recurring items, and milestone tasks.

> **Legacy `period == cycle` caveat.** A legacy invoice whose `period_start`/`period_end` still equal the billed cycle P also matches the *work-cycle* lookup used when generating the **following** cycle P+1 (P+1 reconciles work performed during P). As a result the legacy row suppresses generation of P+1's cadence-period invoice, and any billable or overage work performed during cycle P is **not** billed, until the legacy invoice is re-keyed to the prior-period layout (`period_*` = the prior work cycle, `cycle_*` = the billed cycle). This is harmless only when cycle P has no unbilled billable time. Re-key legacy rows before relying on regeneration to bill in-cycle work:
>
> ```
> php artisan client-management:migrate-legacy-cadence-invoices            # dry-run preview
> php artisan client-management:migrate-legacy-cadence-invoices --apply     # write changes
> ```
>
> The command re-keys issued/paid legacy rows to the prior-period layout and soft-deletes void legacy rows (marking any orphaned billable entries non-billable); it is idempotent and accepts `--company=` / `--agreement=` scoping.

## Interim Overage Invoices

Non-monthly agreements can set `bill_overage_interim = true`. When enabled, the invoicing service can emit `interim_overage` draft invoices at completed month boundaries inside the current non-monthly work cycle.

Interim invoices:
- Apply only to non-monthly agreements.
- Use the parent cadence cycle in `cycle_start` / `cycle_end`.
- Use a monthly slice in `period_start` / `period_end`.
- Bill only immediate overage hours that have not already been billed by earlier interim invoices in the same cycle.
- Are not generated after the cadence-period invoice for that cycle has been issued or paid.

The full cadence-period invoice subtracts any interim-billed overage hours for the same cycle so the client is not double-billed.

Admins can explicitly generate a completed month slice through the idempotent interim endpoint:

```
POST /api/client/mgmt/companies/{company}/invoices/generate-interim/{yyyymm}
```

## Automatic Draft Invoice Regeneration
Draft invoices are automatically regenerated when time entries change:
- **On Create**: When a new time entry is added for a date covered by a draft invoice, that invoice is regenerated.
- **On Update**: When a time entry on a draft invoice is modified, the entry is unlinked and the invoice is regenerated.
- **On Delete**: When a time entry on a draft invoice is deleted, the entry is unlinked and the invoice is regenerated.

This ensures draft/upcoming invoices always reflect the current state of time entries.

## Draft Invoice Regeneration
When regenerating a draft invoice (e.g., when new time entries are added):
- All system-generated line items are deleted (retainer, prior_month_retainer, prior_month_billable, additional_hours, credit, expense, milestone, recurring_item, reconciliation)
- All linked time entries, expenses, and milestone tasks are unlinked (their `client_invoice_line_id` set to null)
- New line items are generated with updated calculations
- Manual adjustments (line_type = 'adjustment') are preserved
