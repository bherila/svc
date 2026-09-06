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
start-date equality, and invisible to the audit that was supposed to rule it
out — the same null-in-a-predicate class this whole section is about, reproduced
in the instrument used to measure it. The audit now reports
`without_a_service_period_start` and `live_without_a_service_period_start`
beside it, the second narrowed to the statuses and kinds a period guard
actually reads.

So the claim here is deliberately split: **the end boundary is evidenced clean;
the start boundary is now measurable and has not yet been measured against
production**, because the count ships with this change and reaches the audit
only on deploy. Run `svc:billing:audit-unplaceable-invoices` — or the MCP tool —
afterwards. A non-zero `of_a_kind_read_by_a_period_guard` is a repair, not a
guard change, and gets its own issue.

That second count carries **no status filter**, unlike every other funnel in
that audit. `generateDue()` has none either — a voided invoice blocks its
period, because the replacement would collide with
`billing_schedule_service_period_unique` — so a *voided* invoice missing its
start defeats the guard exactly as a live one does, and the schedule bills a
deliberately waived period again with nothing to reject the write. Scoping the
count to live statuses reported that row as no exposure at all.

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

The guard now matches the tenant and the period first and reads the link only to
decide **whose** invoice it is:

- names *this* schedule → this period is already billed. Block.
- names *no* schedule → unclaimed, and it still covers the period. Block. This
  is the fail-closed half, and the whole point.
- names a *different* schedule → that is another schedule's period. Do not
  block; a company can hold one schedule per agreement, and blocking here would
  silently stop one of them billing.

The third case is why the fix is not simply "drop the schedule clause": that
would trade a double-charge for lost revenue, and nothing else in the suite
would have noticed.

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
  same list, so they cannot drift apart.
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
so the rule is kept only where it is unambiguous:

- the company has **one** active schedule → nowhere else the row could belong,
  so it blocks. This is #219's case and the ordinary one.
- the company has **another** active schedule → `generateDue()` throws, naming
  the invoice and its period. The transaction rolls back, `next_run_on` does
  not move, and nothing is created, so the run can simply be repeated once
  someone attributes the row.

The refusal is deliberately narrow, per #144's lesson that a refusal on a null
must be checked against the paths that write it. Nothing in this application
writes this shape: `generateDue()` and `ClientInvoicingService` both set the
agreement, and `createDraft()` without one stamps `ad_hoc`, which the kind
condition has already excluded — it coalesces an absent *or explicitly null*
kind, so even asking for a null kind there does not produce one. Only a
migrated or hand-repaired row reaches it, and only while a second schedule is
live.

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
