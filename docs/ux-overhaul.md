# UX overhaul

Status: draft for review. Decisions below came from an interview with the
product owner on 2026-11-06; the open questions at the end are still to be
answered. Each delivery slice is one stacked commit in a single reviewable PR
and follows [the interface guide](ui.md).

## Who this serves

| Population | Door today | What they come to do |
| --- | --- | --- |
| Owner / admin | Workspace member (`StaffRole::Owner`, `Admin`) | See the health of the business, act on what is due, manage clients and agreements |
| Subcontractor | Workspace member reaching clients through project memberships | Work assigned tasks and log time. Never sees billing or rates |
| Client contact | Portal membership on one client company | See what they owe and pay it, and see how their retainer is being used |

The navigation factory already hands each population finished URLs. The
overhaul keeps that contract: the server decides which modules exist for a
viewer and sends `null` for those that do not, and the browser renders what it
is given.

## 1. Navigation: workspace tier and client tier

Today every operator tab is scoped to a client, so anything that spans clients
(revenue, all overdue invoices, all unapproved time) has nowhere to live and
ends up packed into Operations.

New model, still one navbar row:

    ⏻ Workspace   Dashboard  Clients  Invoices  Time  Finance        ⌘K ☾ ⚙
    ⏻ Workspace › Acme ▾   Overview  Agreements  Invoices  Time  Tasks  Files   ⌘K ☾ ⚙

- **Workspace tier** (no client selected): Dashboard, Clients, Invoices
  (all clients), Time (all clients, approval queue), Finance (payments,
  payouts, reconciliation, Square).
- **Client tier** (a client picked from the roster or the switcher): Overview,
  Agreements, Invoices, Time, Tasks, Files. Settings moves to the overflow
  menu on the client's name.
- The switcher gains an "All clients" entry that returns to the workspace tier.
  A breadcrumb-style `Workspace › Client` replaces the bare switcher so the way
  back up is visible.
- The tab set is chosen server-side per role. A subcontractor gets the
  "My work" tier (section 5) and no Dashboard, Invoices or Finance.
- Operations is retired. Its pieces move to the Dashboard action queue
  (drafts, overdue, unapproved time, proposals awaiting signature) and to
  Finance (reconciliation). History, MCP setup and task labels move under
  the settings menu.

The rule from ui.md still holds: the row never wraps. The extra workspace tabs
collapse into the section menu first.

## 2. Admin dashboard (workspace tier landing page)

Two parts, stacked: the money overview on top and the action queue below it.

### 2a. KPI strip

Four tiles, each a number with one line of context:

| Tile | Value | Context line |
| --- | --- | --- |
| YTD | Revenue Jan 1 to today | vs. same period last year |
| TTM | Trailing 12 full months plus month to date | vs. the prior TTM |
| Next 12 months | Contracted recurring revenue | "+ $X projected overage" when overage is on |
| Open A/R | Outstanding balance | Overdue amount and count |

A **basis toggle** (Invoiced or Collected) sits above the strip and drives
the tiles and the chart. Invoiced is the default.

- *Invoiced*: issued invoices (`issued`, `partially_paid`, `paid`,
  `disputed`; never `draft` or `void`), placed by service month (the month of
  `service_period_end`, as `BilledOverageLedger` already does), with the invoice
  date as the fallback for invoices without a period. Corrections and credit
  lines net in the month they apply to.
- *Collected*: payments net of refunds, placed by received date. The
  projection stays on the invoiced basis, because a forecast of collections
  would need a payment-lag model we do not have. The chart labels it that way
  rather than mixing bases silently.

### 2b. Revenue chart

One chart, 24 months wide: the past 12 months, the current month, and the next
11.

- Past months: stacked bars split into **Recurring** (retainer and
  recurring-item lines) and **Overage and other** (additional hours, prior-month
  billable, expenses, milestones, subcontractor, adjustments). The split is a
  classification over `InvoiceLineType`, written as an exhaustive `match` with
  no `default` arm, so a new line type fails static analysis until someone
  decides which band it belongs to.
- Future months: the **contracted** bar (solid outline, lighter fill), plus an
  optional **projected overage** band stacked on top (hatched) when the
  "Include overage projection" toggle is on. Off by default.
- A dashed vertical rule marks today. The current month shows invoiced so far
  plus what remains contracted.
- Hovering or focusing a month opens a tooltip with the per-client breakdown
  (top 5 and "others"). Clicking opens the invoices list filtered to that month.
- Below the chart, an **"At risk in the next 12 months"** list names each
  agreement ending inside the window, with its end date and the monthly amount
  that drops off. The projection does not assume a renewal.

Built with Recharts, lazy-loaded on this page only. Colours come from theme
tokens and are checked in both themes. Every bar's value is also present in an
accessible data table (visually hidden, toggled by a "Show as table" link).

### 2c. Action queue

A single list sorted by urgency. Each row is one thing to do, with a primary
button that does it or goes straight to it:

- Overdue invoices (oldest first), with a "Send reminder" button
- Drafts ready to issue, and cadence periods with no draft yet
- Unapproved time, grouped by client
- Proposals awaiting signature
- Reconciliation items needing review
- Billing audit findings (stale schedules, missing billed overage, unplaceable
  invoices). These reuse the existing auditors.

An empty queue shows "Nothing needs you" and nothing else.

### Projection model

`RecurringRevenueProjector` (pure, database-free) takes an immutable
`ProjectionInputs` DTO built by one tenant-scoped repository read: active and
signed agreements with their recurring items, retainer terms, cadence, and
start and end dates, plus billed-overage history per agreement. It returns a
`MonthlyProjection` per client per month.

- Retainers: the period fee from `RetainerCalculator`, honouring first-cycle
  proration, cadence and `ends_on`.
- Recurring items: placed in the month they would be charged, using the item's
  `ChargeCadence`, anchor month and day, `effective_on` and `expires_on`. A
  `one_time` item counts only if its charge date falls in the window. The
  `match` over `ChargeCadence` is exhaustive.
- Stops at the agreement's end date or termination. No renewal assumption.
- **Overage projection**: per agreement, the trailing 6-month average of billed
  overage *amount* (from issued invoices, the same population as the Invoiced
  basis), carried forward flat while the agreement is active. Agreements with
  less than 3 months of history project zero and are flagged "not enough
  history" in the tooltip. The window is a constant and could become a setting
  later.

The service runs in the request (there is no queue worker) and is bounded by
the number of agreements, not by invoice rows: history comes from one grouped
aggregate query.

## 3. Client roster (workspace tier → Clients)

A table, one row per client, sortable by any numeric column:

| Client | MRR | YTD | TTM (sparkline) | Open / overdue | Agreement health |
| --- | --- | --- | --- | --- | --- |

- **MRR** is recurring revenue normalized to a month (annual items ÷ 12,
  quarterly ÷ 3, semi-annual ÷ 6; one-time excluded).
- **Agreement health** is a compact meter of retainer hours used against
  included hours this period, with a badge when the agreement ends within 60
  days or the client is over its retainer.
- The client name is the prose column: `max-w-0 whitespace-normal
  wrap-anywhere`. The numbers do not wrap.
- A filter for active, ending soon, over retainer and has overdue invoices.
- Totals row pinned at the bottom.
- On phones each row becomes a card: name, MRR, open balance, and the health
  meter.

## 4. Client overview (client tier landing page)

The same vocabulary as the dashboard, scoped to one client:

- A KPI strip: MRR, YTD, TTM, open balance.
- A 24-month chart for this client, with the same bands and toggles.
- **This period**: retainer hours used against included hours, rollover
  balance, the deferred backlog where the agreement shows it, and an estimate
  of the next invoice.
- Active agreements as cards: terms, cadence, recurring items, and end date.
- Recent invoices and recent time, with "see all" links to the tabs.

## 5. Subcontractor: "My work"

Subcontractors sign in to a single cross-client view and never see the client
tier's billing tabs:

- **This week**: tasks assigned to me across clients, grouped by due date,
  with status changes inline.
- **Quick log**: a time entry form with project pre-selected from my
  memberships, a date, minutes and a description.
- **My time**: what I've logged this week and month, by project, with approval
  state. No rates or amounts anywhere.

The server enforces all of this. The navigation factory returns `null` for
billing modules, and the pages' props carry no rates.

## 6. Client portal

Balance and retainer usage sit side by side at the top. Tasks and time are
below.

- **Left card — Balance**: amount due, overdue flag, a "Pay" button (the
  server-provided payment URL), and the last payment received.
- **Right card — This period**: hours used against included hours, rollover
  available, and **Next invoice (estimate)**: recurring charges for the coming
  cadence period plus overage accrued so far this period. It is labelled
  "estimate", and it lists its parts. No 12-month forecast and no projected
  overage.
- Below: open tasks needing their input first, then recent time (described,
  approved entries only), then invoice history.
- Agreements and proposals are reachable from the cards rather than as
  top-level tabs, so the portal tab set shrinks to Overview, Invoices, Time
  and Tasks.

## Delivery slices (stacked commits, one PR)

1. **Navigation tiers.** Workspace and client tiers, role-specific tab sets,
   the breadcrumb switcher, and Operations removed with its pieces moved.
   Browser check at 390, 820, 1440 and 1920.
2. **Revenue read model.** `ProjectionInputs` repository, the pure projector,
   the actuals aggregator for both bases, and the line-type band
   classification. Unit tests (database-free) and workspace isolation tests.
3. **Dashboard.** KPI strip, Recharts chart, at-risk list, action queue.
4. **Client roster.**
5. **Client overview.**
6. **Subcontractor "My work".**
7. **Portal home.**

## Open questions

1. **Fiscal year.** Does YTD start on January 1, or on a configurable fiscal
   year start for the workspace?
2. **Tax.** Should revenue figures exclude tax collected on taxable recurring
   items? The proposal is to exclude it.
3. **Expenses.** Are pass-through expense lines revenue? The proposal is to
   show them in "Overage and other" with a toggle to exclude them.
4. **Draft agreements.** Should signed but not-yet-active agreements count as
   contracted? The proposal is to show them as a separate "signed, starting
   later" band.
5. **Subcontractor identity.** Is a subcontractor any workspace `Member`, or
   do we need an explicit flag or role? This decides who lands on "My work".
6. **Currency.** Is a workspace always single-currency? The proposal assumes
   yes.
