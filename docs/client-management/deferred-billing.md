# Deferred Billing

## Capacity disposition

`DeferredWorkDisposition` classifies deferred work from its allocation line:

| Disposition | Allocation | Capacity accounting |
| --- | --- | --- |
| Waiting | No allocation in the entry's workspace | No usage |
| Retainer applied | Existing retainer allocation | Deferred draw against free capacity |
| Billed on termination | `additional_hours` | Existing ordinary-work accounting |
| Settled outside retainer | `deferred_buydown` | No usage and no catch-up/overage credit |

The capacity query and loaded-entry classification use the same disposition.
Allocation ownership requires the line, pivot and invoice to belong to the
entry's workspace. A settlement has no capacity date: its hourly price pays for
the work independently of every retainer pool.

`deferred_buydown` is reserved for the forthcoming buy-down writer (#366).
Manual invoice, agent and billing-template inputs refuse it. It is excluded
from the cadence generator's replaceable line types, so a cadence rebuild
cannot release its reservation. The first slice established the accounting
rule using synthetic test allocations and the second reports the backlog (see
[Deferred backlog](#deferred-backlog)); settlement creation, its void/delete
lifecycle and portal visibility are subsequent slices of #366.

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

Deferred work draws only on capacity the month's ordinary work left free. When an invoice applied more than that (imported history did), the excess neither becomes debt nor is written off: `RolloverCalculator` carries it forward as a quantity (`MonthSummary::recarriedDeferredHours`), because its entries stay linked to the invoice that applied them and are never linked again. A later invoice settles it first from the reconciled month's free capacity with a $0 "Carried deferred work applied to retainer" line that links no time, or, after termination, bills it at the hourly rate with "Carried deferred work billed on agreement termination" (`CarriedDeferredLine`). The two lines are recognised by their own line types, `carried_deferred_applied` and `carried_deferred_billed`, and nothing else; the wording is display text, and neither type is accepted on a manual line (`InvoiceLineType::systemOnlyValues()`, which also refuses `credit`, `expense` and the generator's retainer and overage types). `rehearse-generation --show` includes it in the carried-forward total. Pinned by `RecarriedDeferredLedgerTest` and `DeferredBacklogScenarioTest`.

## Termination path

When generating a post-termination invoice (`isRetainerMonthPostTermination = true`), the allocator switches modes and selects all outstanding deferred entries that the agreement may invoice, without a capacity filter. Consultant and `retainer`-mode hours attach to an `additional_hours` line priced at `agreement.hourly_rate`; `flat_hourly` hours keep their snapshotted rate on separate `subcontractor` lines; `direct` hours remain tracked and unbilled because the subcontractor invoices the client. A project-scoped agreement collects only its project. This termination-only deferred-billing path does **not** increment `hours_billed_at_rate` — that counter tracks the regular catch-up/overage pool used by the cumulative balance snapshot, which is a separate concept. The dollar amount is captured entirely by the generated lines' totals.

## Deferred backlog

The backlog is what an agreement still has on the shelf: the outstanding
deferred work and what the termination invoice would charge for it today. It
is a read model and never changes anything.

| Piece | Role |
| --- | --- |
| `DeferredBacklogReader` | Workspace-scoped load for one or many agreements in a fixed number of queries: the project-chain check, the outstanding set, and (when a trend is asked for) the history with `withCapacityPlacement()`. |
| `DeferredBacklog` | Database-free calculation over what was loaded: hours, entry count, oldest day worked, the agreement-rate and flat-hourly split, the amount, and the trend. |
| `DeferredTerminationPricing` | The termination price, shared by `InvoiceLineComposer::addDeferredTerminationLine()` and the backlog. Flat-hourly grouping is `FlatHourlyPricing`, shared with the period's subcontractor lines. |

**The same set as termination.** The outstanding entries use the termination
collection's own predicates: `awaitingDeferredBilling()` (billable, deferred,
linked to no line at all), `billableForInvoicing()`, the work date on or before
the day read, and the agreement's project scope
(`ClientTimeEntry::isWithinAgreementScope()`, the in-memory form of
`forAgreementScope()`). The project chain is checked as invoice generation
checks it before billing anything (`assertCompanyProjectChainsAgree()`): over
every time entry of the client, whatever its status or kind, and over any
entry of another workspace claiming the client, before any agreement narrows
the set. Where generation would refuse, the backlog is withheld (`issue:
project_chain_mismatch`, every figure null) instead of throwing, so a screen
that shows it still renders. Amounts are added with
`MoneyService::sum()`, so a total past the integer range is unpriced rather
than an error.

**Where a segment ends.** Agreements for the same project scope hand over to
each other as invoice generation walks them
(`AgreementSelector::successorAgreementForGeneration()`). An agreement reads
its backlog, and its re-carried balance, through the earliest of today, its own
end (where its capacity ledger stops) and the day before a successor starts
(`through` says which day), so a successor's later work is never quoted at the
outgoing agreement's rate. There is no lower bound, because the allocator and
the termination collection apply none: a successor quotes older unlinked work
its own invoices could still collect. Until the outgoing final invoice links
such work, both agreements show it, as either may bill it.

**The re-carried balance.** Deferred work an invoice applied beyond free
capacity stays linked to that invoice, but the ledger carries the excess
forward as a quantity (`MonthSummary::recarriedDeferredHours`), and
termination bills it on its own `carried_deferred_billed` line. The backlog
adds the ledger's closing balance as `carried_hours`, included in `hours` and
priced by `DeferredTerminationPricing::carriedTotal()`, the function that line
is priced by. Only an agreement's own ledger states it: `capacity_ledger.get`,
the agreement page and the CLI read it, and `operations.summary` reports
`carried_hours: null` rather than building a ledger per agreement.

**The same price as termination.** Consultant and `retainer`-mode minutes are
pooled and priced once at `agreement.hourly_rate`; `flat_hourly` minutes keep
the snapshotted rate, one group per person, project, rate and currency;
`direct` work is excluded (it never reaches the set). Where the invoice would
refuse to price, for example an agreement with no hourly rate, or a flat-hourly
snapshot in another currency, the hours stand and the amount is null
(`issue: unpriced`, with the refusal as `issue_detail`). Both rules are
pinned by `DeferredBacklogParityTest`, which compares the backlog with
`collectForTermination()` and with the lines `addDeferredTerminationLine()`
writes for a fixture with mixed modes, two project scopes and rows of another
workspace.

**Trend.** Nothing dated after the day read counts, neither work nor an
allocation, as the capacity ledger counts neither. For each requested month (`Y-m`): `hours_added` is deferred work
done that month; `hours_applied` is deferred work a retainer pool of that month
absorbed, booked on `capacityDate()` exactly as the ledger books it; and
`hours_billed` is deferred work that left the shelf at an hourly price (on
termination, as flat-hourly work, or by a future buy-down), booked on
`allocatedOn()`. `net_hours_per_month` averages added minus applied minus
billed over the months read. `capacity_ledger.get` reads the trend over the
same months as its `months` rows; the admin agreement page reads the last three
calendar months.

Where it is shown:

- `capacity_ledger.get` (MCP) and `GET /api/v1/workspaces/{workspace}/agreements/{agreement}/capacity-ledger`: a `deferred_backlog` object next to `months`.
- `operations.summary`: for managers with `billing:read`, `deferred_backlog` lists the agreements with work on the shelf.
- The admin agreement page: one line, for managers only.
- `php artisan svc:billing:deferred-backlog {workspace} {agreement}`: the same presented object, for operators.

All four call the same reader and presenter. The client portal does not show
the backlog; that is the per-agreement visibility flag of a later slice.

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
