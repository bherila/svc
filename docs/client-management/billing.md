# Client Management - Billing & Invoicing System

This is the billing hub: the prior-period model, cadence/cycle fields, rollover, the minimum-availability rule, line items, balance fields, recurring items, and agreement transitions. Cadence dating & regeneration, milestone billing, and payments live in focused docs.

## Related billing topics

- **[Cadence billing & regeneration](cadence-billing.md)** — invoice period (`period_*` vs `cycle_*`), the one-cycle offset, numbering, regeneration rules (including the legacy `period == cycle` caveat and the migration command), and interim overage invoices.
- **[Milestone billing](milestone-billing.md)** — flat-fee deliverable billing via `milestone_price`.
- **[Payments](payments.md)** — payment methods, validation, status transitions, and the payments UI.

## See also

- **[Deferred billing](deferred-billing.md)** — per-entry flag that lets admins complete work now and bill for it only when retainer capacity exists. Deferred entries are never split and are force-billed at the hourly rate on the termination invoice.
- **[Overpayment credits](overpayment-credits.md)** — any overpaid amount carries forward as a credit applied automatically to the next draft invoice(s); credits never expire.
- **[Stripe billing](stripe-billing.md)** — online invoice payments for issued invoices up to the configured cap, saved payment methods, and webhook-driven payment state.

## Overview
The billing and invoicing system handles automatic invoice generation with prior-period billing, retainer-based pricing, rollover hours, recurring fixed-fee items, reimbursable expense tracking, and agreement billing cadences. Agreements can bill on monthly, quarterly, semiannual, or annual cadence cycles. Cadence-period invoices reconcile the prior work cycle while billing the next retainer cycle in advance; non-monthly agreements may optionally generate interim overage invoices at completed month boundaries inside a work cycle.

## Core Concepts

### Prior-Period Billing Model
When a monthly invoice is generated for month M (e.g., February 2024):
- **Work Period (M-1)**: The invoice `period_start` and `period_end` represent the month work was performed (e.g., Jan 1 - Jan 31).
- **Time entries from month M-1** are included and generally dated as the last day of M-1. These are covered by the available pool (retainer + rollover).
- **Retainer fee for month M** is included and dated as the first day of M.
- **Reimbursable expenses** up to the invoice generation date are included with their original dates.

This model ensures work is billed after completion, while the retainer fee provides availability for the upcoming month.

For non-monthly agreements, the same monthly ledger remains the source of truth for rollover and overage calculations. The cadence-period invoice summarizes the prior work cycle and bills the next retainer cycle (`cycle_start` through `cycle_end`) in advance, with the retainer fee and included hours scaled by the number of covered months, including any first-cycle proration.

### Billing Cadence and Cycle Fields
Agreement cadence is stored on `client_agreements.billing_cadence`:
- **`monthly`**: One invoice per calendar month.
- **`quarterly`**: One invoice per three-month cycle, anchored to the agreement active date.
- **`semi_annual`**: One invoice per six-month cycle, anchored to the agreement active date.
- **`annual`**: One invoice per twelve-month cycle, anchored to the agreement active date.

Invoices include cadence metadata:
- **`invoice_kind = cadence_period`**: The primary monthly, quarterly, semiannual, or annual cycle invoice.
- **`invoice_kind = interim_overage`**: A non-monthly overage invoice emitted before the full cycle invoice.
- **`invoice_kind = terminal`**: Reserved for terminal/final invoice handling.
- **`cycle_start` / `cycle_end`**: The retainer cycle billed in advance on cadence-period invoices. Interim overage invoices keep a narrower monthly `period_start` / `period_end` while pointing at the full work cycle they belong to.

First-cycle behavior is controlled by `first_cycle_proration`:
- **`prorate_hours`**: Retainer hours and fee are prorated to the covered fraction of the cycle.
- **`full_period`**: The first cycle bills the full retainer even if it starts mid-cycle.
- **`align_next_cycle`**: The initial partial period is treated as an alignment stub, then full cadence cycles begin at the next boundary.

### Rollover Hours
Unused retainer hours can roll over to future months (configurable via `rollover_months` in agreements). The calculation uses a chronological balance pool:
- **`rollover_months = 0`**: No rollover. Unused hours are lost at the end of
  the month they were earned.
- **`rollover_months = N`**: Unused hours survive for N months *after* the month
  they were earned. `1` keeps January's remainder spendable through February and
  expires it at the end of it; `2` carries it through March.

> This section previously said `1` meant hours were usable only in the month
> they were earned, which is off by one against `RolloverCalculator` and against
> `RolloverExpiryTest`, where February spends January's remainder at `1`. The
> code is the contract and the documentation was wrong. It mattered more than an
> off-by-one usually does: every rollover-bearing agreement in the migrated data
> carries exactly `1`, so the two readings disagreed about all of them, and no
> rollover divergence in the replay could be adjudicated while both statements
> stood.

Expiry is by elapsed calendar months, not by walking stored balances. The
predecessor aged rollover by stepping through months that held a non-zero
balance, so a month which used its entire retainer left nothing to step through
and became invisible, and older hours stayed spendable past their window. A
balance is reported as expired in the first month it falls outside the window
and dropped at the end of that month, so it is not reported as expiring again.
- **Negative Balance**: If hours worked exceed available pool, the difference is carried forward as a negative balance rather than billed immediately, UNLESS the Minimum Availability Rule is triggered.

For non-monthly agreements, rollover is still calculated month-by-month. The cadence-period invoice summarizes the cycle, but it does not replace the monthly ledger used by `RolloverCalculator`.

### Minimum Availability Rule (Catch-up Billing)
To ensure the client always has capacity for new work, the system enforces a minimum availability of **1 hour** at the start of a billing period.

**Logic:**
1. Calculate Net Availability: `(Retainer Hours for Month M) - (Negative Balance Carried from M-1)`
2. If Net Availability < 1 hour:
   - Calculate deficit: `1 - Net Availability`
   - Generate an invoice line item (`additional_hours`) for this catch-up amount.
   - Bill at the hourly rate.
   - Reduce the carried-forward negative balance by the catch-up amount (effectively "paying off" the debt).
   - Set the starting "Unused Hours" balance to 1 hour.

**Example:**
- Jan: Retainer 2h, Worked 10h. Result: -8h balance carried to Feb.
- Feb: Retainer 2h.
  - Net Availability = 2h - 8h = -6h.
  - Rule: Must have 1h available.
  - Catch-up needed: 1h - (-6h) = 7h.
  - Invoice triggers 7h billing @ hourly rate.
  - Final Feb Status: Starts with 1h available.

### Hourly Rate Determination
While most hours are covered by the pool at $0 additional cost, any manual line items or eventual overage billing uses the hourly rate from the **active agreement for the invoice month (M)**.

## Invoice Line Items
Generated invoices contain the following line item types (in order):

1. **Prior-Month Work** (`prior_month_retainer`): Time entries from M-1. In the "give and take" model, these are generally included at $0, dated last day of M-1. Shows total hours in the description and links to time entries with their original dates. Quantity is blank/empty (hours are documented in the description).
   - Split Logic: If prior month work exceeds what was covered by M-1 retainer and M retainer, it is split into multiple line items (Covered by M-1, Covered by M, Carried Forward).

2. **Retainer Fee** (`retainer`): Retainer fee for the invoice cycle. Monthly agreements bill one monthly fee; non-monthly agreements scale the fee by the covered months/proration. Quantity is "1".

3. **Catch-up / Additional Hours** (`additional_hours`): Used for "Catch-up Billing" (Minimum Availability Rule) or manual overage. Billed at hourly rate.

4. **Balance Update** (`credit`): Informational $0 line showing rollover hours used or negative balance carried forward.

5. **Expenses** (`expense`): Reimbursable expenses incurred up to the invoice date. Each expense line uses its original expense date. Quantity is "1".

6. **Milestone Tasks** (`milestone`): Completed billable tasks with a non-zero `milestone_price`. Each task generates a separate line item dated with its completion date. See [Milestone Billing](#milestone-billing) below.

7. **Recurring Items** (`recurring_item`): Fixed-fee agreement charges generated from `client_agreement_recurring_items`. Each incidence links back to the recurring item that produced it.

8. **Subcontractor** (`subcontractor`): One line per flat-hourly subcontractor (grouped by subcontractor, project, and snapshot rate) for the period, billed at the contractor's own rate. These hours are independent of the retainer pool. See [Subcontractor billing](#subcontractor-billing) below.

### Subcontractor billing

Subcontractors assigned to a project (see [Subcontractors](overview.md#subcontractors)) are billed per their `client_subcontractors.billing_mode`, snapshotted onto each time entry at log time:

- **`flat_hourly`** — excluded from the retainer ledger; billed on its own `subcontractor` line at the per-contractor rate (additive, never consumes retainer hours).
- **`retainer`** — flows through the normal retainer allocation exactly like consultant hours (same rate, carry-forward/back overage).
- **`direct`** — excluded from all invoicing (the subcontractor bills the client directly) but still tracked and visible to client users.

Only **approved** entries are billed; pending/rejected subcontractor self-logs are excluded everywhere the invoicing pipeline reads entries (`retainerBillable` / `flatHourlySubcontractor` scopes on `ClientTimeEntry`).

## Invoice Period

Moved to **[Cadence billing & regeneration › Invoice Period](cadence-billing.md#invoice-period)**.

### Regenerating Cadence Invoices

Moved to **[Cadence billing & regeneration › Regenerating Cadence Invoices](cadence-billing.md#regenerating-cadence-invoices)** (status-based skip rules, the legacy `period == cycle` caveat, and the `client-management:migrate-legacy-cadence-invoices` command).

## Invoice Balance Fields
The invoice tracks several balance fields that reflect the state at different points in time:

### Server serialization & portal hydration
- The server now exposes a **single canonical serializer** for detailed invoices (`ClientInvoice::toDetailedArray()`), used by the admin API and the portal Blade hydration.
- Blade-embedded JSON omits explicit `null` values (keys may be absent on the client). The client treats missing keys as `undefined` and performs tolerant validation + normalization.
- Monetary totals and payment amounts may be emitted as numbers by the server; the client accepts numeric/string unions and normalizes them to the expected string formats for runtime validation and display.
- Hydration includes `hours_billed_at_rate` ("Catch-up Hours Billed") and `unused_hours_balance` (remaining retainer pool) so the portal's Hourly Summary can render these tiles immediately (0:00 is shown when values are zero).


### Work Period Balances (End of Month M-1)
These fields reflect the state **after** processing all work performed in the work period (M-1) but **before** the retainer for Month M is applied. These are primarily used for historical reporting and test validation.

- **`unused_hours_balance`**: Unused hours remaining from the Month M-1 pool after processing all work in that month.
- **`negative_hours_balance`**: Negative hours (debt) carried forward from the Month M-1 work period.
- **`rollover_hours_used`**: Hours from previous months' rollover that were used during the work period (M-1).
- **`hours_billed_at_rate`**: Additional hours billed at the hourly rate (catch-up billing or manual overages).

### Starting Balances (Start of Month M)
These fields reflect the "Starting Pool" for the upcoming month (M), providing the client with a clear picture of their available capacity after accounting for the current invoice's retainer and catch-up billing.

- **`starting_unused_hours`**: Net availability at the start of Month M. This includes the retainer for Month M, minus any remaining debt from M-1, plus any buffer added by the Minimum Availability Rule (catch-up).
- **`starting_negative_hours`**: Any remaining negative hours (debt) that could not be cleared by the retainer for Month M or by catch-up billing.

These balances are calculated to ensure the client has a predictable starting point for the next billing cycle.

## Billing Validation & Automation

### Time Entry Validation
To maintain the integrity of financial records, the system enforces the following rules in the Client Portal:
- **Block Edits/Deletes on Issued Invoices**: Users cannot edit or delete time entries linked to invoices with **Issued** or **Paid** status.
- **Allow Edits/Deletes on Draft Invoices**: Time entries linked to **Draft** (upcoming) invoices CAN be edited or deleted. The entry is automatically unlinked and the draft invoice is regenerated.
- **Block New Entries in Issued Periods**: Users cannot create new time entries for a date within an **Issued** or **Paid** invoice period.

### Automatic Draft Invoice Regeneration

Moved to **[Cadence billing & regeneration › Automatic Draft Invoice Regeneration](cadence-billing.md#automatic-draft-invoice-regeneration)** (drafts auto-regenerate on time-entry create/update/delete).

## Draft Invoice Regeneration

Moved to **[Cadence billing & regeneration › Draft Invoice Regeneration](cadence-billing.md#draft-invoice-regeneration)** (line-item rebuild rules; preserved manual adjustments).

## Recurring Items

Recurring items are fixed-fee charges attached to an agreement. Admins manage them through the agreement workspace and the API at:

```
GET    /api/client/mgmt/companies/{company}/agreements/{agreement}/recurring-items
POST   /api/client/mgmt/companies/{company}/agreements/{agreement}/recurring-items
PUT    /api/client/mgmt/companies/{company}/agreements/{agreement}/recurring-items/{recurringItem}
DELETE /api/client/mgmt/companies/{company}/agreements/{agreement}/recurring-items/{recurringItem}
```

Each item stores a description, amount, charge cadence, start/end dates, optional anchor month/day, taxable flag, summarized flag, and notes. Supported charge cadences are `monthly`, `quarterly`, `semi_annual`, `annual`, and `one_time`.

`RecurringItemBiller` computes incidences that fall inside the invoice's retainer cycle. For example, a monthly item on a quarterly invoice produces three invoice lines, one per month in the retainer cycle. Quarterly, semiannual, and annual items use their anchor month/day; one-time items bill once on `start_date`.

## Interim Overage Invoices

Moved to **[Cadence billing & regeneration › Interim Overage Invoices](cadence-billing.md#interim-overage-invoices)**.

## Agreement Transitions

Agreement cadence changes use `AgreementTransitionService` instead of mutating the existing agreement in place. A transition:
- Sets the outgoing agreement's `termination_date` to the day before the effective date.
- Creates a successor agreement with the new terms.
- Optionally carries forward positive rollover hours into `initial_rollover_hours`.
- Handles active recurring items by cloning, migrating, dropping, or skipping them.
- Records an `agreement.transitioned` row in `client_company_activity`.

Transition endpoints:

```
POST /api/client/mgmt/companies/{company}/agreements/{agreement}/transition/preview
POST /api/client/mgmt/companies/{company}/agreements/{agreement}/transition
```

### Outgoing Agreement Catch-up After Transition

When an agreement has been terminated and a successor agreement exists for the same company **and the same project scope**, monthly catch-up generation for the outgoing agreement is bounded to the month immediately before the successor's `active_date`. Two retainers for different projects run concurrently and never succeed one another; company-wide agreements succeed only company-wide agreements. Gap-month work between the termination date and the successor's first work period is still billed by the outgoing agreement; work on or after the successor's first work period is billed by the successor. A lone terminated agreement with no same-scope successor preserves the legacy unbounded post-termination catch-up.

## Milestone Billing

Moved to **[Milestone billing](milestone-billing.md)** — flat-fee deliverable billing via `milestone_price`, the task generation workflow, carry-forward, and the milestone badge.

## Time Entry Detail Display
The invoice page includes a "Show Detail" toggle switch in the top-right corner (default: ON). When enabled, it displays the underlying time entry descriptions for each line item as an indented bullet list, showing the description, hours, and original date_worked for each entry.

## Time Entry Badge Display
On the **Time Records** page, each billable entry linked to an invoice shows a badge:
- **Upcoming** (blue): Entry is on a **draft** invoice — clickable link to the draft invoice
- **Invoiced** (green): Entry is on an **issued** or **paid** invoice — clickable link to the issued invoice
- **BILLABLE** / **NON-BILLABLE**: Entry is not yet linked to any invoice
- **Deferable** (amber, admin-only): Entry is flagged `is_deferred_billing = true` — shown alongside the billing status badge

Entries on draft invoices remain fully editable (edit button visible, row clickable). Only entries on issued/paid invoices are locked.

## Page Title
The invoice page title includes the invoice number for easy identification (e.g., "Invoice ABC-202402-001 - Company Name").

## Payment Handling

Moved to **[Payments](payments.md)** — payment methods, validation, invoice status transitions, the partially-paid badge, and the payments UI/workflow.
