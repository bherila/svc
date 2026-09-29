# Deferred Billing

## What it does

Admins can flag any billable time entry as **deferred**. A deferred entry is completed work that should **not** be billed on the usual next invoice — it waits on the shelf until there is free retainer capacity in a future period: capacity the month being reconciled did not use for its own work.

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
2. Compute `remainingCapacity = max(0, priorMonthRetainerCapacity − priorAllocated)`: what the month being reconciled has left after its own work. The retainer the invoice sells in advance is **not** offered. It belongs to next month's work, and lending it to the backlog booked the backlog as this month's overage, which the ledger carries into next month as debt — so next month's work spilled into the month after, and the minimum-availability rule billed catch-up hours the backlog had caused. Pinned by `DeferredBacklogAbsorptionTest`.
3. Sort candidates by `date_worked ASC, id ASC` (deterministic FIFO).
4. Greedily include any candidate whose `hours <= remainingCapacity`, subtracting from remaining capacity each time.
5. Skip candidates that don't fit. They stay unlinked and remain available to the next invoice.

Included entries are attached to a single `prior_month_retainer` invoice line titled *"Deferred work items applied to retainer (X:XX)"*. Skipped entries are exposed in the invoice detail payload as a "deferred to future invoice" note so admins can see what is pending.

## Where applied deferred work is booked

The capacity ledger (`InvoiceLedgerBuilder`, and the monthly generator's own `monthlyBalances()`) books an applied deferred entry on the date of the pool that absorbed it — the "Deferred work items applied to retainer" line's `line_date`, else its invoice's `service_period_end`, never earlier than the day worked — through `ClientTimeEntry::capacityDate()`. Ordinary work, and deferred work nothing has applied yet, are unaffected: the first is booked on the day worked, the second not at all.

Booking it in the month it was *worked* disagreed with the invoice that applied it. Where that old month's unused capacity had already expired, the restatement spent the expired capacity instead, and the pool the invoice really used was handed out a second time to the next month's work; where the old month had no room, the ledger showed a debt from that month forward that no invoice ever stated. Totals only agreed when nothing expired in between. Pinned by `DeferredCapacityPlacementTest`.

## Deferred work applied beyond free capacity

Deferred work draws only on capacity the month's ordinary work left free. When an invoice applied more than that (imported history did), the excess neither becomes debt nor is written off: `RolloverCalculator` carries it forward as a quantity (`MonthSummary::recarriedDeferredHours`), because its entries stay linked to the invoice that applied them and are never linked again. A later invoice settles it first from the reconciled month's free capacity with a $0 "Carried deferred work applied to retainer" line that links no time, or, after termination, bills it at the hourly rate with "Carried deferred work billed on agreement termination" (`CarriedDeferredLine`). The two lines are recognised by their own line types, `carried_deferred_applied` and `carried_deferred_billed`, and nothing else; the wording is display text, and neither type is accepted on a manual line (`InvoiceLineType::systemOnlyValues()`, which also refuses `credit` and `expense`). `rehearse-generation --show` includes it in the carried-forward total. Pinned by `RecarriedDeferredLedgerTest` and `DeferredBacklogScenarioTest`.

## Termination path

When generating a post-termination invoice (`isRetainerMonthPostTermination = true`), the allocator switches modes and selects all outstanding deferred entries that the agreement may invoice, without a capacity filter. Consultant and `retainer`-mode hours attach to an `additional_hours` line priced at `agreement.hourly_rate`; `flat_hourly` hours keep their snapshotted rate on separate `subcontractor` lines; `direct` hours remain tracked and unbilled because the subcontractor invoices the client. A project-scoped agreement collects only its project. This termination-only deferred-billing path does **not** increment `hours_billed_at_rate` — that counter tracks the regular catch-up/overage pool used by the cumulative balance snapshot, which is a separate concept. The dollar amount is captured entirely by the generated lines' totals.

## Regeneration

Draft invoices auto-regenerate whenever a time entry in their period changes (see [cadence-billing.md](cadence-billing.md#draft-invoice-regeneration)). The regeneration flow already:

1. Deletes system-generated line items.
2. Unlinks attached time entries.
3. Re-runs invoice generation, which re-invokes the deferred allocator.

Both cadence generators release the draft's system lines **before** measuring any balance, through one step (`ClientInvoicingService::releaseDraftForRebuild()`); the non-monthly path also discards a ledger the bulk walk measured before reaching the draft, because the ledger counts an applied deferred entry in the month that absorbed it: measured first, a rebuild that no longer has room for the draft's deferred work recorded a debt for work it was about to drop (`DeferredBacklogAbsorptionTest::test_a_rebuilt_draft_measures_its_balances_without_the_deferred_work_it_releases`, and `DraftRebuildMeasuresAfterReleaseTest` for both cadences). Otherwise no special handling is needed. A deferred entry that fit on last night's draft may be bumped to next month if someone adds a non-deferred entry that consumes the capacity. Conversely, a skipped deferred entry from last night can show up on the redrawn draft if capacity opens up. All of this happens automatically.

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
