# Deferred Billing

## What it does

Admins can flag any billable time entry as **deferred**. A deferred entry is completed work that should **not** be billed on the usual next invoice — it waits on the shelf until there is free retainer capacity in a future period.

Unlike regular entries, deferred entries:

- **Are never split.** If only 5h of retainer capacity remains and a deferred entry is 7h, it stays unbilled — it is *not* split into 5h+2h.
- **Never trigger catch-up billing.** The Minimum Availability Rule (see [billing.md](billing.md#minimum-availability-rule-catch-up-billing)) is computed ignoring deferred entries; they cannot push the agreement into debt.
- **Never expire on their own.** A deferred entry may sit unbilled for many months if capacity doesn't open up. It carries forward indefinitely.
- **Are force-billed on agreement termination.** When an agreement is terminated, the final invoice includes every outstanding deferred entry billed at the **hourly rate**. This guarantees the client is never left with unbilled work after the relationship ends.

## Data model

A single boolean on `client_time_entries`:

| column | type | default | notes |
| --- | --- | --- | --- |
| `is_deferred_billing` | `BOOLEAN` | `false` | Indexed. Only meaningful when `is_billable = true`. |

The flag is set only by admins (the portal API validates this). Clients cannot self-defer work.

## Allocation logic

`App\Services\ClientManagement\DeferredBillingAllocator` runs after the normal time-entry splitter, at invoice generation time:

1. Load all unbilled (`client_invoice_line_id IS NULL`), billable, `is_deferred_billing = true` entries with `date_worked <= period_end`.
2. Compute `remainingCapacity = (priorMonthRetainerCapacity − priorAllocated) + (currentMonthRetainerCapacity − currentAllocated)`.
3. Sort candidates by `date_worked ASC, id ASC` (deterministic FIFO).
4. Greedily include any candidate whose `hours <= remainingCapacity`, subtracting from remaining capacity each time.
5. Skip candidates that don't fit. They stay unlinked and remain available to the next invoice.

Included entries are attached to a single `prior_month_retainer` invoice line titled *"Deferred work items applied to retainer (X:XX)"*. Skipped entries are exposed in the invoice detail payload as a "deferred to future invoice" note so admins can see what is pending.

## Termination path

When generating a post-termination invoice (`isRetainerMonthPostTermination = true`), the allocator switches modes: it selects **all** outstanding deferred entries (no capacity filter) and attaches them to a single `additional_hours` line priced at `agreement.hourly_rate`. This termination-only deferred-billing path does **not** increment `hours_billed_at_rate` — that counter tracks the regular catch-up/overage pool used by the cumulative balance snapshot, which is a separate concept. The dollar amount is captured entirely by the line's `line_total`.

## Regeneration

Draft invoices auto-regenerate whenever a time entry in their period changes (see [cadence-billing.md](cadence-billing.md#draft-invoice-regeneration)). The regeneration flow already:

1. Deletes system-generated line items.
2. Unlinks attached time entries.
3. Re-runs invoice generation, which re-invokes the deferred allocator.

No special handling is needed. A deferred entry that fit on last night's draft may be bumped to next month if someone adds a non-deferred entry that consumes the capacity. Conversely, a skipped deferred entry from last night can show up on the redrawn draft if capacity opens up. All of this happens automatically.

Only **draft** invoices are redrawn. Bulk cadence generation skips any retainer cycle that already has an issued, paid, or **void** invoice (matched on `cycle_start` / `cycle_end`), so a voided cycle is never regenerated — voiding a cadence invoice waives it. See [cadence-billing.md](cadence-billing.md#regenerating-cadence-invoices).

## UI

- **New Time Entry / Edit Time Entry modal** (admin only): a "Defer billing" checkbox appears under "Billable". It is disabled and cleared when "Billable" is off.
- **Time Records page**: entries with `is_deferred_billing = true` render a small amber **"Deferable"** badge alongside the billing status badge (admin-only).
- **Invoice detail page** (line items): each time entry sub-row with `is_deferred_billing = true` shows a small amber **"Deferred"** badge (admin-only).
- **Invoice detail page** (deferred-pending section): after the main line-item table, an amber panel lists any outstanding deferred entries that did not fit this cycle's capacity, so admins can see what is pending for a future invoice.

## Invariants & tests

- Issued/Paid/Void invoices are never modified after the fact, even if new deferred entries are created in their period. A voided cadence cycle is additionally never regenerated as a fresh invoice — voiding waives it.
- Deferred entries are never split (hard invariant; covered by `DeferredBillingAllocatorTest::test_never_splits`).
- Termination invoices include every outstanding deferred entry (`test_termination_force_bills_all_deferred`).

See `tests/Feature/ClientManagement/DeferredBillingAllocatorTest.php`.
