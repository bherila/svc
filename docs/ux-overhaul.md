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

## 7. Who approves whose time

### The problem

Approving a time entry today does two jobs at once. It fixes the entry's
billing rate (`TimeEntryMutationService` stamps `billing_rate_amount` on
approval), and it is the gate a draft invoice reads (`status = 'approved'`).
So the consultant has to "approve" their own hours to bill them, and the
record then claims an approval that nobody gave. The person whose approval
actually matters, the client, never appears in it.

Approval belongs to whoever **pays** for the time. Review belongs to whoever
the payer asks to vouch for it.

### Worked example: vetvet

- **Herila (the fCTO)** has an agreement with vetvet: a monthly retainer with
  carry-forward. Vetvet pays Herila, so **vetvet approves Herila's hours**.
- **Other Eng** is a separate business with **its own agreement with vetvet**,
  at its own rate. Herila introduced them. Vetvet pays Other Eng directly, and
  before it does, it asks Herila to check Other Eng's hours. So **Herila
  reviews and vetvet approves**.
- Herila and Other Eng work in **one project**: they share tasks, hand work to
  each other, and comment in the same threads.
- Every person signs in through the same identity provider (configured per
  deployment: `id.bherila.net` here), so each person is one `User` across every
  workspace.

| Time | Stored in | Reviewed by | Approved by | Billed by |
| --- | --- | --- | --- | --- |
| Herila's own | Herila's workspace | (none) | vetvet, in the portal or by paying | Herila's invoice |
| A sub Herila pays and re-bills (`FlatHourly`, `Retainer`) | Herila's workspace | (none) | Herila, then vetvet through Herila's invoice | Herila's invoice |
| Other Eng's | Other Eng's workspace | Herila, through a reviewer grant | vetvet, in the portal | Other Eng, outside SVC or in its own SVC workspace |

### 7a. Workspaces own money, and shared projects hold the collaboration

A workspace remains the tenant for **books**: agreements, rates, time,
invoices, payments, expenses. Those never cross workspaces.

A **shared project** is the collaboration space: tasks, comments, files.

- It has exactly one **host workspace** (whoever created it, Herila here) and
  any number of **participant workspaces**, each added by an explicit grant
  that the host can revoke. Hosting can be handed to another participant later.
- New table `client_project_participations` (`client_project_id`, host
  `workspace_id`, `participant_workspace_id`, `granted_by_user_id`,
  `granted_at`, `revoked_at`). Task, comment and file reads on a project resolve
  "the host workspace, or an active participation", through one repository
  method, never ad hoc. Every new path gets isolation tests: a non-participant
  workspace, and a revoked participant, both get 404.
- Participants' people can be assigned tasks and can comment. A task assigned
  across workspaces shows the assignee's workspace name next to their name.
- Each participant workspace has its own local `ClientProject` for its own
  books, linked to the shared project by `shared_project_id`. **Time is logged
  against the local project**, so it falls under that workspace's agreement and
  rates. A time entry can name a shared task, but the host can never read the
  entry's rate or amount.
- Every client is still a `ClientCompany` in each workspace that bills it.
  "vetvet" exists once in Herila's workspace and once in Other Eng's. Nothing
  links the two rows. The person is the link (7c).

The UI keeps the word **workspace**. It means "your business". A shared project
is where several workspaces meet.

### 7b. Lifecycle of an hour

    Draft ─▶ (Submitted) ─▶ (Reviewed | Flagged) ─▶ Invoiced ─▶ Approved
                                                      └──────▶ Disputed

- **Draft.** Editable by its author.
- **Submitted.** Only for time that needs review or approval by someone other
  than its author before it can be billed: a sub Herila re-bills, or Other
  Eng's time. The author submits a period (week or month).
- **Reviewed or Flagged.** Only where a reviewer grant exists. The reviewer
  marks a submitted period as reviewed, or flags single entries with a note. A
  flagged entry goes back to its author.
- **Invoiced.** Herila's own hours never wait for anyone. A draft invoice pulls
  in all unbilled consultant time in its service period, and issuing it fixes
  the rate. This moves the rate stamp from approval to issue for consultant
  time only. The rate is still resolved by `AgreementBillingRateResolver`.
  Nobody self-approves. Other Eng's time is never invoiced here unless Other
  Eng turns on invoicing in its own workspace.
- **Approved.** The client approves, in either of two ways:
  - **explicitly**, in the portal, by period or entry (stored: `client_approved_by_user_id`, `client_approved_at`);
  - **implicitly**, when the invoice carrying the entry is paid in full. This
    is computed from invoice status, not stored, so a refund or reopen undoes it
    by itself.

  An explicit approval is never undone by a refund. It records what a person
  said.
- **Disputed.** The client disputes an entry with a note, or the invoice is
  `disputed`. A disputed entry on an issued invoice goes through the existing
  correction flow. It never edits the issued document.

Approval and invoicing are independent: Herila bills on schedule, and vetvet
approves whenever it gets to it. Mappings over `InvoiceStatus` and the new
states are exhaustive `match` expressions. A person can never review or approve
their own entry. The server refuses it whatever their role.

### 7c. Vetvet's "Hours to approve"

One queue in the portal for each client contact. It is built from **that
user's** portal memberships across every workspace, so it covers both Herila's
and Other Eng's hours without any link between the two vetvet records.

    Hours to approve, October 2026

    ▸ Herila (fCTO)   38.5 h   invoiced #1042            [Approve period]
    ▸ Other Eng       22.0 h   reviewed by Ben, Nov 2 ✓  [Approve period]

    Each row expands to its entries. Any entry can be disputed with a note.

- Grouped by workspace (the vendor), then by period. Each group is read
  through that workspace's own scoped repository. It's one bounded query per
  workspace the user belongs to, never per entry.
- The approval permission is a new portal capability, **Approve time**,
  granted per contact. Not every contact who can see invoices can approve
  hours.
- Rates and amounts for Other Eng never appear here unless Other Eng invoices
  through SVC. Vetvet approves hours.

### 7d. Herila's reviewer view

- Other Eng's workspace grants Herila a **reviewer grant** on one shared
  project: `time_review_grants` (`workspace_id` of the granter,
  `client_project_id`, `reviewer_user_id`, `granted_by_user_id`, `revoked_at`).
  It allows reading Other Eng's submitted hours for that project (date,
  minutes, description, task) and writing review marks. It never shows rates,
  agreements or invoices.
- These show up in Herila's action queue as "Other Eng — 22.0 h to review".
- Herila doesn't need a membership in Other Eng's workspace. The grant is the
  whole permission, it is revocable by Other Eng, and every read and write
  through it is audited.

### 7e. Other Eng's workspace

A real workspace, but starting light: time on shared projects, submit and
review states, and an **approved timesheet** download (PDF and CSV) for a
period, which shows who reviewed and who approved each entry. Agreements and
invoicing in SVC are off until they turn them on. They see "My work" (section
5) as their home.

### Migration

- Existing self-approved consultant entries keep `approved` in the database,
  and the interface relabels them by context. Nothing is rewritten in place.
- Approving your own consultant time returns 409 with a message pointing to the
  draft invoice. MCP `time_entries.log` stops accepting `approve: true` for an
  entry the actor authored, and `docs/mcp-product-contract.md` changes with it.
- `scopeApproved()` and the retainer ledgers keep reading `approved` and
  `invoiced`, so rollover history is not rebuilt. Client approval is a separate
  column, not a status.
- If Other Eng already logs hours in Herila's workspace as `Direct` time, a
  one-off, reviewed move takes those hours into Other Eng's new workspace. It
  is run per participant, by explicit command, never automatically.

## 8. In-app help

Help is written once, in the repository, and appears where the question comes
up. Mockups: the "In-app help patterns" board on the design canvas.

1. **Term tips.** A `?` next to any word a new user may not know. It opens on
   click or focus, so it works on touch and with a keyboard, never only on
   hover. The text comes from one glossary.
2. **Status with its next step.** `statusLabel` returns a label and a next-step
   line ("Reviewed: waiting on Acme to approve"). A badge is never shown
   without one.
3. **Consequence before action.** Before anything that can't be undone, a
   dialog lists what will happen, sent by the server with the action's URL,
   and the button names the action.
4. **Empty states that teach**: what would appear here, why it would, and the
   one step that makes it happen.
5. **Help panel** (`?` or ⌘/) beside the page, showing that page's questions
   first, with search across everything. The same articles also make up a
   full guide at `/help`.
6. **First-run checklist** for each role: operator, client contact and partner.
   A step is checked off because the thing it asks for actually exists.

Articles live in `docs/help/*.md`. Front matter names the audience
(operator, client, partner), the routes the article belongs to, and the
glossary terms it defines. Articles are built into the frontend, so help
changes in the same commit as the feature it explains. Tests fail when:

- a route has no article;
- a status has no next-step line;
- a glossary term is defined twice;
- an article links to a heading that doesn't exist.

The glossary is also the vocabulary for emails, PDFs and MCP descriptions.

## 9. Conventions from mockup review

Decided on the design canvas (2026-11-06):

- **Dark mode is the design baseline.** Light mode stays supported through
  the existing theme tokens.
- **Time.** Every time field accepts `2:30`, `2.5`, `2h30m` and `150m`, and
  shows what it understood under the field ("= 2.5 h (150 min)"). Storage
  stays whole minutes. Display defaults to decimal hours. A per-user setting,
  "Show time as 2.5 h / 2:30", applies to every screen, timesheet and PDF that
  person views. Invoices always use decimal. One shared parser and one shared
  formatter, with a test that fails if a duration is printed without them.
- **Buttons.** A page has at most one filled button, and only when one thing
  needs doing now. Every other action is outlined, the same width when they
  are stacked, and right-aligned with the row's main action.
- **Status and progress.** No background colours for status in lists. Use an
  icon, or a flow of steps joined by arrows with a pointer on the current
  step. Reserve coloured badges for one status per table row, as in the
  client overview's invoice table, which is the reference table.
- **Pickers.** Project and task pickers are search-as-you-type comboboxes.
  The task picker offers "Create task" inline when nothing matches; the new
  task is created on the selected project and assigned to its creator.
- **Proforma invoice.** "View proforma invoice" on the client overview, and
  "Next invoice · estimate" in the portal, both render the next cadence
  invoice from the agreement and the time logged so far. They are built on
  the existing period preview (`invoices.preview_period`). A proforma has no
  number, is stamped "Proforma · not an invoice", can't be paid, and is never
  stored as an invoice. It is grouped into **Time** (retainer, work within it,
  additional hours), **Recurring services** (agreement recurring items such as
  web hosting, each on its own cadence; an item not charged this period is
  shown as "next charged …" with no amount) and **Expenses** (approved ones
  included, with receipts; unapproved ones listed but left out). Tax is
  computed per taxable line, as on the issued invoice.
- **Everything billable, not just hours.** Every estimate, projection and
  chart covers time, recurring services and expenses:
  - the client overview's "Next invoice" card;
  - the portal's estimate;
  - the dashboard's recurring band (retainers and recurring items);
  - the Overage & other band (additional hours, milestones, adjustments);
  - the expenses band, which has its own toggle.

  Monthly recurring and the 12-month projection include recurring items at
  their own cadence. Projected overage covers additional hours only; expenses
  are never projected.

## Delivery slices (stacked commits, one PR)

0. **Help scaffolding** (section 8): the `docs/help` loader, glossary,
   `statusLabel` next-step lines, the help panel, and the coverage tests. Each
   later slice adds its own articles.
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
8. **Approval without self-approval** (7b): consultant time pulls straight
   onto drafts, and client approval works in the portal and through payment.
9. **Shared projects** (7a): participations, cross-workspace task assignment
   and comments, local linked projects.
10. **Reviewer grants and the cross-workspace approval queue** (7c, 7d).
11. **Light workspace and approved timesheet** (7e).

## Settled answers

- **Fiscal year**: YTD runs from January 1.
- **Tax**: revenue figures exclude tax collected.
- **Expenses**: pass-through expense lines are shown in "Overage and other",
  with a switch on the dashboard and on the client overview to exclude them.
- **Signed but not yet started agreements** count as contracted, but only from
  their start date. Nothing is projected before `starts_on`.
- **Currency**: a workspace is single-currency.
- **Subcontractor identity** is decided by project membership and the time's
  billing mode, not by workspace role. A contractor with their own agreement
  with the client is their own workspace (section 7).
- **Identity**: one shared identity provider for every user, so the same person
  is the same `User` in every workspace and in the portal.

## Open questions

None. Answered on 2026-11-06:

1. **Onboarding is by invitation.** The host invites a contractor by email to
   a shared project. Accepting the invitation (through the shared identity
   provider) creates their light workspace, with the invitee as owner, and
   joins it to the shared project as a participant in the same transaction.
   An invitation is single-use, expires, and can be revoked before it is
   accepted. A person who already owns a workspace picks which of their
   workspaces joins.
2. **Client approval periods are calendar months** for every vendor,
   whatever the agreement's billing cadence. Submitting, reviewing and
   approving all work in calendar-month periods.
3. **Handover is chosen when the host leaves.** Leaving a shared project as
   host requires naming the new host, either a participant workspace or a
   client-owned workspace created for the purpose. The project can't be left
   without a host. Until a handover happens, nothing about hosting changes.
