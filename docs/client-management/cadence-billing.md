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
