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

### 7b′. Expenses follow the same approval

Clients approve **expenses as well as hours** (decided 2026-11-06).

- **Your check.** The operator's existing expense approval (`ExpenseStatus`
  approved) stays as the "Ready to bill" check: the receipt is right and the
  expense belongs to this client. It is labelled *Ready to bill*, not
  *Approved*.
- **Client approval** is separate, as for hours. It is stored per expense
  (`client_approved_by_user_id`, `client_approved_at`), or implied by paying
  in full the invoice that carries the expense. A client disputes a single
  expense with a note, and the correction flow handles it.
- **One list.** The portal queue becomes **To approve**, with hours and
  expenses grouped by vendor and month. Each expense shows its receipt
  inline.
- **Proforma.** An expense not yet ready to bill is listed on the proforma but
  left out of its total.

### 7c. Vetvet's "To approve" (hours and expenses)

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
- **Cards.** Every card is the shared `Card` component; no page draws its own
  bordered box (30 hand-rolled `rounded-* border` boxes exist in
  `resources/js/pages` today). On desktop a card has a 12 px radius, 20 px
  padding, and a 16 px semibold title with 12 px below it. On phones it has
  16 px padding and a 15 px title. Stat tiles (key figures) are their own
  component, with 16×18 px padding. Section headings outside cards are 18 px
  (17 px on phones).
- **Buttons or links.**
  - **Buttons:** an action that commits in place is a button: approve, flag,
    dispute, mark reviewed, save, submit, pay.
  - **Links:** an action that goes somewhere is a link, whether to a page
    (view, preview, open, review) or to a dialog that confirms a change.
    Dialog links end in "…" ("Send reminders…", "Record a payment…").
  - **Header bar exception:** actions in a page's header bar are outlined
    buttons even when they navigate.
  - **Lists in cards:** the trailing action of each row is a link.
- **Consistency is tested, not reviewed by eye.**
  - **Lint (ESLint):** pages may not set a border-and-radius box or card
    padding on a raw element. They must use `Card`, `StatTile` or `Button`.
    Likewise, a `Link` may not take `buttonVariants` inside a card.
  - **Browser checks:** a `uiConsistencyRisks(page)` helper sits next to
    `horizontalOverflowRisks` and runs in the same layout checks. It reports
    any `[data-slot=card]` whose radius, padding or title size differs from
    the token; more than one filled button on a page; a bordered `a`
    inside a card body; and an action button without an icon or an
    accessible name.
  - **Accessibility checks** (axe) run in the same pass.
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
- **Text budget.** At most one line of supporting text per card or list row.
  Explanations live in `?` tips (`Tooltip` from `components/ui`, opened on
  click or focus as well as hover) and in the help panel. A banner explaining
  a concept appears only on someone's first visit, can be dismissed, and
  stays dismissed (`<details>` "Why?" disclosures afterwards).
- **Icons.** `lucide-react`, which is already a dependency, at 16 px inline
  and 20 px for list-row leading icons. An icon either sits next to a text
  label (and is `aria-hidden`), or is the only content of a button with an
  `aria-label` and a tooltip. Status uses an icon plus words, never colour
  alone.
  Every action button carries an icon before its label: check for approve and
  mark reviewed; undo for withdraw and undo; flag; dispute; send for submit
  and issue; mail for reminders; download; eye for preview; a card for pay
  and record payment; plus for create and invite.
- **Accessibility (WCAG 2.2 AA):**
  - real `button`, `a` and `label` elements, with a visible `:focus-visible`
    ring;
  - text contrast of 4.5:1 or more in both themes;
  - touch targets of 44 px or more on coarse pointers;
  - comboboxes following the ARIA combobox pattern (Base UI's);
  - `aria-current` on the current step of a flow;
  - charts with a "Show as table" alternative;
  - `aria-live` on values that change, such as the selected month;
  - a keyboard-only and screen-reader pass for each slice. Add
    `@axe-core/playwright` to the browser checks, and make any serious or
    critical violation fail them.
- **Phone first.**
  - **Layout:** 16 px side padding, single-column stacking via
    `flex-wrap`/`grid-cols-1`, and `minmax(min(100%, N), 1fr)` grids so no
    track is wider than the screen.
  - **Navbar:** stays one row. The workspace name truncates, and search and
    help become icon buttons.
  - **Wide tables** scroll inside their own box on phones. The Clients table
    becomes cards below `md`.
  - **Flow steps** wrap.
  - **Phone layout patterns:** the tab strip becomes a section menu
    ("Dashboard ▾"); key figures go 2×2; the chart shows six months back and
    five ahead, with the per-client breakdown one tap away; list rows are
    whole-row links with a chevron (44 px or taller); a page's primary action
    is full width, 48 px tall. The phone boards on the canvas show the
    dashboard and the portal.
  - **Checks:** every slice is checked in a browser at 390, 820, 1440 and
    1920 px.
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

## 10. One invoice document: screen, PDF and proforma

Today the operator invoice page (`resources/js/pages/clients/invoice.tsx`),
the portal invoice page and the PDF (`resources/views/invoices/show.blade.php`
through `InvoiceDocumentService` and Dompdf) are separate implementations of
the same document. They become one:

- **`InvoiceDocument`**: an immutable DTO built by one tenant-scoped read and
  parameterised by audience (`InvoiceLineDetail::OPERATOR` or `CLIENT`). It is
  built from an issued invoice, a draft, or a proforma preview (the
  `invoices.preview_period` path). The screen gets it as JSON; the PDF Blade
  view renders the same DTO.
- **Grouping** maps every `InvoiceLineType` to a section with an exhaustive
  `match` and no default arm:
  - **Time:** retainer, prior-month retainer, work within retainer,
    additional hours, prior-month billable, subcontractor;
  - **Recurring services;**
  - **Expenses;**
  - **Deferred work:** carried deferred applied, carried deferred billed,
    deferred buy-down;
  - **Adjustments and credits:** credit, adjustment, reconciliation,
    milestone.
- **Screen**: the document is shown as the document itself (the PDF's page
  component), with an action rail beside it: payment state, hours approval,
  receipts, activity. Portal: the same document, with Pay and approve or
  dispute hours in its rail.
- **Theme**: the browser preview follows the app theme. In dark mode the
  document is drawn from the dark tokens (raised surface, light ink, muted
  rules), and a "Show as printed" toggle switches it to the paper rendering.
  The PDF itself is always light (white paper, dark ink), so PDF viewers'
  own dark modes and printing invert it correctly. The screen and PDF share
  one document component with a `paper` or `screen` surface, and print CSS
  always forces `paper`.
- **PDF renderer**: generation goes behind an `InvoicePdfRenderer` interface
  (render an `InvoiceDocument` for an audience, and return bytes plus page
  count), chosen by configuration. Dompdf is the only implementation for
  now. A LaTeX engine (Tectonic) or headless Chromium can be added later as
  more implementations, checked by the same golden-fact tests; both are
  deferred. A LaTeX engine would need strict escaping of every interpolated
  value, shell-escape off, a sandbox and a timeout, because invoice text is
  client-supplied.
- **Dompdf constraints for the template**: layout uses tables and
  inline-block only, with no flex or grid. The footer is a fixed-position
  page footer beside the existing page-number script, not a flex
  `margin-top: auto`. There is no rotated watermark: the proforma uses the
  header stamp. Inter is shipped as TTF and registered once, with DejaVu
  Sans as the fallback. Section headings are kept with their first rows
  using `page-break-inside: avoid` on small groups.
- **PDF**: stays on Dompdf, so layout uses tables rather than flex. The cover
  page holds the totals and the pay link. Following pages hold the hours
  statement, still deferred, appendix A (time entries) and appendix B
  (receipts).

### Detail that must survive the rewrite

Nothing about rollover and deferred work is simplified, whatever the mockups
show. Before the old template is deleted, golden tests render every one of
these, from the existing fixtures, in both the old and the new document and
compare the figures:

- **The hours statement is the stored `InvoiceHoursStatement` snapshot**,
  frozen at issue and never recomputed at render, with every section that
  `InvoiceHoursStatementRows` produces today:
  - **Opening pool:** retainer, rollover, deficit and expired hours, and hours
    expired within the period;
  - **Work reconciled on this invoice:** ordinary hours applied to the work
    pool or to the next retainer, billed at rate, subcontractor, deferred
    applied (with entry count), re-carried settled, deferred billed on
    termination;
  - **Catch-up billed at the hourly rate:** catch-up, minimum availability and
    its threshold, interim billing;
  - **Carried forward:** rolled forward, expiring, deficit carried forward,
    deferred backlog (hours and entries), re-carried remaining;
  - **Closing position:** next retainer hours;
  - **Pool position**, for a pool sold on an earlier invoice (`retainerSoldBy`,
    pool remaining, available before);
  - **The correction variants** of each of these sections.
- **"Still deferred"**: the deferred backlog, shown to clients only where the
  agreement's `show_deferred_backlog_to_client` allows.
- **Appendix A**: time entries per line, with the audience-based detail from
  `InvoiceLineDetail` (internal notes never reach the client audience) and
  the generic-label notice.
- **Reissue and correction notices**, and the corrections history.
- **The proforma** builds its statement the way the generator does (the same
  allocation plan and capacity ledger), so the preview and the issued invoice
  can't disagree.

## 11. MCP: progressive discovery

Today `tools/list` returns every tool the principal is authorized for,
filtered by scope, role and feature flag and paged at 100, and the server
does not advertise list-change notifications. A fully scoped manager sees
about 200 tools at once.

Proposal:

- **Core set at initialize**: `context.get`, `search`, `toolsets.list`,
  `toolsets.enable`, plus the time-logging tools the `log-time-across-projects`
  prompt needs.
- **Toolsets** taken from `AgentOperationCatalog` scope groups: projects,
  tasks, time, clients, proposals, billing, payments, expenses, finance,
  square, and the new `revenue` (section 2 projections). `toolsets.list`
  returns each toolset's name, description and tool count, counting only tools
  the principal may call. `toolsets.enable` adds a toolset to the session
  (sessions are already cached per credential) and sends
  `notifications/tools/list_changed`, so the server now advertises
  `tools.listChanged`.
- **Enabling grants nothing**: it only reveals tools that scope-filtered
  discovery already allows. Every call is still authorized again.
- **Compatibility**: clients that don't handle `list_changed` can ask for
  everything at connect time (`toolsets=all`, or a setting on the OAuth
  client). Tool names, schemas and REST parity don't change.
  `AgentMcpContractTest` gains a test that the core set plus every toolset
  equals today's full list.

## 12. Payment connections per workspace

A workspace is the business that gets paid, so payment settings belong to it,
not to the deployment and not to each client. Decided 2026-11-06: no Stripe
Connect and no Square OAuth. Each workspace stores its own keys.

- **Stripe:** an owner or admin pastes a restricted secret key once per
  workspace.
  - SVC validates the key with a read call, creates the workspace's webhook
    endpoint through the Stripe API, and stores its signing secret.
  - A publishable key entered beside it is checked for mode (before any call
    to Stripe) and then against the account: the restricted key creates a
    SetupIntent (`usage=off_session`, no customer, no payment method), the
    publishable key reads it back with its client secret, which works only
    inside one account, and the restricted key cancels it in a `finally`. A
    404 / `resource_missing` is a field error on `publishable_key`; a key
    without SetupIntents Write, an unreachable Stripe or any unexpected
    answer refuses the save, never accepts it silently. The restricted key
    therefore needs SetupIntents: Write when a publishable key is entered.
  - Charges use the workspace's own key.
  - **Webhook health.** SVC checks that the workspace's webhook endpoint is
    subscribed to every event it handles (`StripeWebhookService::REQUIRED_EVENTS`),
    on save, on the Payments page's Check again, and daily
    (`svc:payments:check-stripe-webhooks --apply`); the result is stored on the
    connection and shown on the card, with an owner/admin Fix behind a confirm
    dialog. See `docs/client-management/stripe-billing.md`.
- **Square:** an owner or admin enters an access token, location,
  environment and webhook signature key once per workspace.
- **Secrets** are encrypted at rest, never logged, never sent to the browser
  or API, and shown only as a masked suffix. Replacing or removing a key
  replaces or removes the webhook endpoint, deferred to after commit.
- **Webhooks** arrive at a per-workspace URL carrying an opaque connection
  identifier. They are verified with that workspace's secret. An unknown
  identifier or a bad signature is refused and nothing is applied.
- **Reuse:** a workspace whose owner already connected another workspace can
  reuse that connection without re-entering the key. Same owner only.
- **Adapter:** behind a `PaymentConnection` adapter, so Stripe Connect or
  Square OAuth could be added later as other implementations. Not built now.
- **Migration:** an explicit, dry-run-by-default command copies today's
  environment keys into the configured workspace's connection. The
  environment path keeps working until it has run.
- **Isolation tests:** no workspace can charge into, read, or receive
  webhooks for another's connection. Removing a connection stops charges at
  once.
- **Other businesses:** a partner workspace (section 7e) enters its own keys,
  or none.

## 13. Revenue and accounting data over REST and MCP

The dashboard, REST and MCP read the same immutable DTOs (section 2), so the
three can't disagree.

- **Planning reads** under a new `revenue:read` scope, separate from
  `finance:read`:
  - `revenue.summary`: YTD, last 12 months, next 12 months and unpaid, by
    basis;
  - `revenue.monthly`: per month and per client, with recurring, overage and
    other, expenses and projected bands, and the same basis and toggle
    parameters as the dashboard;
  - `revenue.projection`: per-agreement rows with their recurring items,
    start and end dates, and the overage method.

  Every response carries an `assumptions` block: as of when, basis, overage
  window, minimum history, and a method version. A stored projection can
  then be explained later.
- **Accounting reads** under `finance:read`:
  - invoice lines with tax broken out per line;
  - cash-basis income (payments, refunds and reversals by date);
  - processor fees and payouts (already exposed);
  - a calendar-year income export in CSV and JSON.

  All lists take stable IDs and an `updated_since` cursor, so tax and
  bookkeeping tools sync incrementally. Corrections arrive as new reversing
  records, never as edits to issued ones.
- **No outbound webhooks yet.** No queue worker runs in production, so
  integrations pull on a schedule. Outbound notifications (invoice issued,
  payment received, agreement ending) would later be sent in the request
  after commit.
- **Contract**: `public/openapi/svc-agent-v1.json`, the MCP catalog in the
  `revenue` and `finance` toolsets (section 11), and descriptions drawn from
  the in-app glossary (section 8). Parity tests check that REST, MCP and the
  dashboard return the same figures for the same fixture.

## 14. Security hardening

A conservative pass over transport headers, rate limits, cookies, uploads and
CI. Nothing here changes how sign-in, billing or the payment processors behave.
Every decision is recorded so a later change knows what it is undoing.

### Headers (`App\Http\Middleware\SecurityHeaders`)

- One global middleware, so web pages, the portal, the REST and MCP API, the
  processor webhooks and error pages all carry the same headers. A header a
  controller already set is never overwritten.
- Enforced: `X-Content-Type-Options: nosniff`, `Referrer-Policy:
  strict-origin-when-cross-origin`, `X-Frame-Options: DENY` and a minimal
  `Permissions-Policy` (camera, microphone, geolocation, USB and FLoC off).
  Nothing in SVC is framed by another site, and SVC frames only Stripe's own
  payment frames, so `DENY` has no exception. `frame-ancestors` is not put in the
  CSP: a report-only policy cannot enforce it (and `X-Frame-Options` carries it
  in every mode), so it joins the policy only if that header is ever dropped.
- `Strict-Transport-Security: max-age=31536000` only when the request is HTTPS.
  Decided: no `preload` (a preload listing is close to irreversible) and no
  `includeSubDomains` (other subdomains of the host are not this application's
  to commit). `SVC_HSTS_MAX_AGE=0` turns it off. **Unchanged in this follow-up,
  on purpose:** the header is host-only. `includeSubDomains` on `svc.bherila.net`
  would cover only that host's own descendants (`*.svc.bherila.net`), never
  `bherila.net` or its siblings, so it is the narrow case, but it still forces
  HTTPS on every present and future descendant name; and `preload` / a wider
  `includeSubDomains` on the parent domain are not SVC's to decide. Both wait for
  a hostname inventory (every subdomain, what serves it, and whether each can
  serve valid HTTPS for the whole max-age) that nobody has made yet.
- **CSP has a real mode switch.** `SVC_CSP_MODE` is `off`, `report-only` (the
  default, so deploying this changes no behaviour) or `enforce`; anything else is
  read as `report-only`, so a typo neither disables the policy nor starts blocking
  pages. **Bug fixed:** `SVC_CSP_REPORT_ONLY=false` used to *disable* the policy
  rather than enforce it; the setting is retired. For compatibility it still maps
  when `SVC_CSP_MODE` is unset (`false` -> `off`, as it behaved, anything else ->
  `report-only`); new configuration should use `SVC_CSP_MODE`. Sent on HTML
  responses only; JSON, API and webhook responses carry no page policy.
- **Nonce.** `SecurityHeaders` draws a fresh nonce per request *before* the view
  renders and passes it to `Vite::useCspNonce()`, so every `<script>` Vite emits
  (React Refresh preamble, module entries, dev client) carries it; the inline
  appearance bootstrap in `app.blade.php` is the only other executable script in
  any blade view (the invoice and timesheet documents have `<style>` only) and
  reads the same nonce. `script-src` is `'self'`, the nonce, Stripe and, in
  development, the Vite origin: **no `'unsafe-inline'`**. Tests check the nonce is
  unique per request and matches every executable script tag, with and without
  the dev server.
- **`style-src` keeps `'unsafe-inline'`, deliberately.** Stripe's Payment Element
  and the component library (positioned popovers, Recharts) write `style`
  attributes and `<style>` rules at runtime; a nonce cannot cover attributes.
  Style injection cannot run script, so this is the smaller risk; revisit with
  `style-src-attr` / `style-src-elem` splitting only if reports show it is
  feasible.
- **Stripe.** SVC loads exactly one Stripe component set: Stripe.js from
  `https://js.stripe.com/v3/` (`resources/js/lib/stripe-js.ts`) and mounts the
  **Payment Element** (`elements.create('payment')`) for `confirmPayment` and
  `confirmSetup`. There is no Checkout redirect, no Address or Link Authentication
  element and no Google Maps use. Following Stripe's CSP guidance, `script-src`
  and `frame-src` allow `https://js.stripe.com` and `https://*.js.stripe.com`;
  `frame-src` also `https://hooks.stripe.com` (3-D Secure / redirect-based
  methods); `connect-src` `https://api.stripe.com`; `img-src` `https://*.stripe.com`.
  `maps.googleapis.com` is left out until an Address Element is used. In
  report-only mode, watch the reports through a real payment before enforcing.
- **Violation collector.** `POST /api/csp-reports` (`CspReportController`) is the
  default `report-uri`, and the policy also names `report-to csp-endpoint` with a
  `Reporting-Endpoints` header (modern browsers prefer it). It accepts
  `application/csp-report` and `application/reports+json` only (415 otherwise),
  refuses a body over 8 KiB (413, by `Content-Length` and by measuring the bytes),
  reads at most 10 reports per post, and is limited by the named `csp-reports`
  limiter (120/min per address, tunable with `SVC_RATE_LIMIT_CSP_REPORTS`). It is an API-group route, so there is no session
  and no CSRF check (a browser posts a report with no token). What it keeps is one
  **log line** per report with three fields: the directive name, the blocked
  host (or a keyword such as `inline`/`eval`), and the document path with record
  ids replaced and every character outside `A-Za-z0-9._~/{}-` replaced, capped at
  120. No raw JSON, query string, credentials, sample, referrer or user agent is
  stored, nothing goes to the database, and the response is always empty (204 on
  success, 400 for malformed JSON). `SVC_CSP_REPORT_URI` can point elsewhere (https
  URL or absolute path); an empty value sends no reports.
- **Rolling out enforcement** (a deployment decision, not made here): leave
  `report-only` through a full billing cycle including a card payment and a
  setup-intent save, read `csp.violation` lines, then set `SVC_CSP_MODE=enforce`.
  Rolling back is the same variable.
- Square needs nothing in the policy; see the list above.

### Rate limits

Inventory of unauthenticated and portal routes, and what was done:

| Route(s) | Before | Now |
| --- | --- | --- |
| `/login`, `/oauth/redirect`, `/oauth/callback`, `POST /logout` | none | `sign-in`, 60/min per address |
| every `portal/*` route (including portal file downloads) | none | `portal`, 300/min per person |
| every `portal/*` write (POST, DELETE) | none | plus `portal-writes`, 60/min per person |
| `POST .../invoices/{id}/stripe-payment-intent` | none | `payment-intents`, 30/min per person |
| shared project invitation invite/accept/decline | none | `invitations`, 30/min per person |
| `/oauth/token`, `/oauth/register`, `/oauth/authorize` | package/Passport throttle; register 10 per 60 min | unchanged |
| REST (`api/v1/*`) and MCP (`api/v1/mcp`) | `throttle:60,1` | unchanged |
| `api/webhooks/brevo` | `brevo-webhooks`, 600/min | unchanged |
| `api/webhooks/stripe*`, `api/webhooks/square*` | none | **none, on purpose** |
| `/search`, `/palette*`, MCP credential settings | already throttled | unchanged |
| `POST /application-access`, `/.well-known/*`, `/up`, `/sanctum/csrf-cookie` | none | none (see below) |

- Every ceiling is several times what a person doing ordinary work reaches, and
  is tunable with `SVC_RATE_LIMIT_*` (`config/svc.php` `rate_limits`); a value
  that is not a positive number falls back to the default.
- Authenticated limiters key on the person, not the address, so an office behind
  one address is not one bucket. Sign-in keys on the address because there is no
  person yet. Behind the CDN the address is the real client's, resolved by the
  auth package's trusted-proxy handling.
- Stripe and Square webhooks are signature-verified and retried by the
  processor; a 429 would delay or drop a genuine delivery, so they are never
  throttled by the application. A test pins that.
- There is no public pay link and no magic-link endpoint in this codebase: every
  payment and portal route sits behind `auth`. Portal invitations are applied at
  sign-in, which the `sign-in` limiter covers.
- Left alone: `/application-access` is the identity provider's signed (JWT,
  nonce) call, package-owned, and answers 404 until enabled; wrapping it risks
  dropping provider calls. The `.well-known` documents are static and cheap.

### Cookies

`config/session.php` `secure` was unset, so the cookie was Secure only when the
request itself looked HTTPS. It now defaults to true when `APP_URL` is https and
can still be set explicitly (`SESSION_SECURE_COOKIE=false` for a plain-http
checkout). `http_only` already defaulted to true and `same_site` to `lax`; both
are pinned by tests. The session is stored in the database and is not encrypted
at rest in the cookie (the cookie holds only an id).

### Uploads

Audit of every attachment path (they all end in `AttachmentStorageService`):

- Fine already: 50 MB ceiling checked twice (validator `max:51200` and again
  while streaming into staging); the stored type is sniffed from the bytes
  (`getMimeType()`), never taken from the extension or the client; the object
  key is a UUID path, so a hostile filename never reaches the disk; filenames are
  encrypted at rest; downloads are authorised per audience and served as
  `attachment`.
- Fixed: downloads now send `X-Content-Type-Options: nosniff` and
  `Content-Security-Policy: default-src 'none'; sandbox`; a type a browser would
  run (HTML, XHTML, SVG, XML, JavaScript) is served as `application/octet-stream`
  so a client that ignores `attachment` cannot execute an upload; the served
  filename drops control characters and bidirectional overrides
  (`evil\u202Efdp.exe`) and is capped at 255 characters.
- Not changed, a product decision: there is no type allowlist. Any file type up to
  50 MB is accepted. Restricting it would reject files people attach today.
- Not changed: an SVG receipt is now served as opaque bytes, so it no longer
  previews as an image from the download URL; it still downloads.

### CI

- `composer audit --locked` and `pnpm audit --prod` run in a `dependency-audit`
  job that is **not** a need of `ci`, so a newly published advisory cannot block
  unrelated work. State when added: `pnpm audit --prod` clean; `composer audit`
  reports 13 advisories in seven Symfony 8.1.x components (`http-foundation`,
  `http-kernel`, `routing`, `serializer`, `yaml`, `http-client`, `filesystem`), all
  fixed by 8.1.11. Not bumped here: a lock-file change is its own PR.
- `MCP_BRIDGE_DEPLOY_KEY` and every `webfactory/ssh-agent` step are removed from
  `tests.yml`, `pilot-deploy.yml`, `mcp-visibility-candidate.yml` and
  `cloudflare-ranges.yml`. `bherila/mcp-laravel-bridge` is public and
  `composer.lock` already references it by https. The SSH used to deploy to web1
  is untouched, and `stripe-test-mode.yml` is left to its own PR.
- `clean-install` installs Composer and pnpm dependencies with no secrets,
  runs `php artisan about`, the workflow unit tests and `tsc`, and is a need of
  `ci`: it is the proof that an outside contributor can work on SVC.
  `DependencyAccessWorkflowTest` fails if a bridge key, an ssh-agent step or a
  `secrets.` reference comes back.

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
12. **Invoice document** (section 10): `InvoiceDocument` DTO and grouping, the
    golden tests against the current template, then the new PDF, operator page,
    portal page and proforma.
13. **MCP progressive discovery** (section 11), together with the revenue
    tools from section 2.
14. **Payment connections per workspace** (section 12). Codex handoff, task 2.
15. **Revenue and accounting data over REST and MCP** (section 13), after
    slice 2's DTOs land.

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

## Decisions for unattended work (2026-11-06)

Settled by the product owner before a period away:

- **Merging.** Claude may merge its own PRs, and Codex's, only when every
  hosted check is green on that exact head, the mutation results have been
  inspected (no unexplained escaped mutants), and no review thread is open.
  One at a time, in dependency order, watching the deploy that follows: a
  merge to `main` deploys to production. Data-moving production commands (the
  payment-credential migration, moving Direct time to a partner workspace)
  are never run by Claude; their PRs say how to run them.
- **`time_entries.log approve: true` on your own entry** is accepted and does
  nothing. The response says so, and the docs mark it deprecated. Nothing
  that uses it today breaks.
- **Portal approvers.** Billing contacts get "Approve time and expenses" by
  default; other contacts can be granted it one by one.
- **Staff on the dashboard.** Members who are not owners or admins see only
  their own "Needs you" queue: their time, tasks and reviews. Revenue figures
  are for owners and admins only. The server enforces this.
- **Payments:** per-workspace Stripe and Square keys. No Stripe Connect and
  no Square OAuth (section 12).
- **MCP default.** Every client gets the core set plus toolsets. A client
  that can't handle `list_changed` opts into the full list.
- **Pace.** At most two lanes at a time.
- **Open questions** are decided conservatively (fail closed, smallest
  change), noted in the PR, and listed below for review.

### Decisions made while away

(Appended as they happen: date, PR, question, decision, why.)

- **2026-11-08, command palette v2 (kinds, commands, recents, shortcuts, portal).**
  - **Kinds.** The palette also finds expenses, agreements, proposals, files,
    time entries, contacts and portal users. Each asks the page it opens:
    expenses follow the client Expenses tab (a reachable client and a project
    the viewer holds), agreements `BillingRecordAccess::constrainAgreements`,
    files `AttachmentReader`, time `AgentTimeEntryQuery` (the time sheet's own
    query), proposals the operator proposal page and the portal's conditions.
    Contacts and portal users are managers only. The agent API's `search` tool
    does not ask for the new kinds, so its contract is unchanged.
  - **Portal viewers are searched as clients.** A workspace where someone holds
    only a client company membership is searched with the portal's own
    conditions (client-visible, not a draft, approved time matched on the
    client-safe description only, no expenses, no contacts). Someone who is both
    staff and a client in one workspace gets the staff view of it.
  - **Filenames are matched in PHP.** `original_filename` is encrypted at rest,
    so SQL cannot LIKE it and a plaintext copy would undo the encryption. A
    search looks through the newest 300 files the viewer may read and matches
    the term against those; a file older than that is reached by its record.
  - **Commands are built from the navigation factory**, so they cannot drift
    from the navbar. "Go to Revenue" is offered only to owners and admins (the
    Dashboard it sits on is open to every member). "My work" is offered to any
    member, including managers, though the navbar hides it for them. "Settings"
    is the current client's settings (managers); no workspace settings screen
    exists. With several workspaces and no workspace in the page, only
    "Switch workspace" is offered. A portal viewer with several clients and no
    client in the page gets no client-specific commands.
  - **Recents** are a kind and a public id, per person per workspace, newest
    eight kept. Titles and links are resolved again through the authorized
    queries on every read, and an id the viewer could not open is never stored.
    Only the palette records them (opening a record elsewhere does not), and
    only clients, projects, invoices, tasks, expenses, agreements, proposals and
    files.
  - **Shortcuts.** Cmd/Ctrl+K toggles from anywhere except a rich-text editor
    (a chord types nothing). `/` and `?` never fire while typing in an input,
    textarea, select, contenteditable or ARIA text widget, with a held
    modifier, during IME composition, or while another dialog is open.
  - **Harness.** `node scripts/check-layout.mjs --palette` reads the palette as
    an owner, an owner on a client, a scoped member and the client contact. The
    router answers `/search` and `/palette` as whoever the page in the Referer
    belongs to; recents are seeded because the harness refuses the POST.

- **2026-11-07, #624 item 9: Appendix B (receipts).**
  - **Who is told of a receipt:** operator audience, workspace managers only
    (a scoped member who may read the invoice gets none, because they cannot
    open expense receipts). Client audience: only a receipt flagged visible to
    the client AND only if `AttachmentReader::portalReadsParentType('expense')`
    holds. It does not today, so client copies carry no Appendix B and no
    receipt link; if that policy opens, the appendix follows with no other
    change.
  - **"Vendor":** expenses have no vendor column, so the appendix lists date,
    description (the merchant text), amount and receipts. No schema was added.
  - **Amount:** the expense's own amount, which is what the line bills (no
    markup exists).
  - **Images:** JPEG and PNG only, by what the bytes are, not the stored type;
    at most 1.5 MiB each, 6 megapixels each, 24 per document and 6 MiB in
    total, drawn at most 600 by 520 CSS px and never enlarged. Everything else
    is listed by name with a sentence (not drawn, too large, could not be
    drawn). No PDF merge dependency was added.
  - **Reading files:** through `AttachmentStorageService::boundedContents()`,
    which checks state, size and digest and never throws.
  - **Where receipts are drawn:** the operator screen (links only, no bytes)
    and the PDF download routes. Emailed, stored administrator copies and
    reminder PDFs ask for none, so no image bytes are stored in a table or
    mailed. Proforma asks for none.
  - **Drafts and voids:** same as Appendix A (printed when expenses are
    claimed by the invoice's lines).
  - **Screen:** the rail card and appendix link only server-sent addresses
    (`null` where the viewer may not open the file). At 390 px the appendix
    table scrolls inside its own container, as Appendix A does.
  - **Harness:** the `--invoice-document` seed now sets the connection's own
    publishable key; the portal "Pay by card" assertion failed without it on
    a clean `origin/main`.
- **2026-11-07, client-shared receipts (follows #624 item 9).** The owner
  decided that a client sees a receipt only if it is marked shared with the
  client, on the portal invoice and in the PDF they download. This supersedes
  "client copies carry no Appendix B" above.
  - **The one flag:** `client_attachments.is_visible_to_client`, the flag every
    other shared file uses. No second flag.
  - **Who may read:** `AttachmentReader` serves a portal viewer an expense
    receipt only when the file is shared AND the expense is billed on a line of
    an invoice that viewer can already see (`PortalInvoiceQuery`: issued,
    partially paid, paid or disputed; client visible; same company; never a
    draft or void) AND, for a project-scoped contact, the expense's own project
    is one they hold. An expense with no project is refused to a project-scoped
    contact (conservative: its attribution cannot be established). A workspace
    manager previewing the portal gets the same shared-and-billed receipts, no
    more. Everything else is 404. One correlated query, no query per receipt.
  - **Existing receipts:** until the privacy latch is set every upload defaulted
    to "visible", a value nobody chose and nothing read for an expense. Reading
    it would publish them, so migration `2026_11_09_100000_make_expense_receipts_private`
    makes every stored expense receipt private, and `ClientAttachment` now
    creates an expense receipt private whatever the latch says. A manager shares
    the ones to show. (Not reversible: the old value was never a decision.)
  - **Appendix B for a client:** only the shared receipts, only for expenses that
    have one, with a portal link per file; an unshared receipt, or an expense
    with only unshared receipts, is omitted with no "n hidden" and no "No receipt
    on file". The intro sentence is the client's own. The portal route is
    `portal.files.receipt.download` (`/portal/{company}/expenses/{expense}/receipts/{file}`);
    there is no portal listing page for receipts.
  - **Emailed, stored and reminder PDFs:** unchanged, no Appendix B. One copy is
    mailed to every recipient with no named viewer, and a project-scoped contact
    must not be mailed another project's receipt. Listing shared receipts by name
    with "available in the portal" was considered and left out; the help article
    says to open the invoice in the portal.
  - **Who sets the flag:** the existing "Visible to client" control on the
    expense receipts page, now offered for expense receipts to a current owner or
    admin, through the existing `attachments.visibility.update` operation (its
    description now names expense receipts). It keeps that operation's
    rollout switch (`SVC_ATTACHMENT_SHARING_WRITES_ENABLED`) and privacy latch:
    with them off the page offers no control and every receipt stays private.
    Staff who are not owners or admins, other workspaces and clients are
    refused. The page says when a shared receipt is not yet reachable because
    its expense is not on an invoice the client can see.
  - **Activity:** sharing changes on other files are not recorded as client
    activity (only the idempotency receipt of the mutation), so none is added
    for receipts either.
  - **Changed expectations:** `AttachmentReaderTest` (expense uploads are
    private whatever the latch), `AttachmentSharingActionTest` (an owner may now
    share a receipt), `InvoiceReceiptsTest` and the receipts golden and screen
    tests (clients now receive shared receipts). `AttachmentSharingPolicyTest`
    is unchanged.

- **2026-11-06, #614: does "Still deferred" follow the agreement setting?**
  Decided by the owner: yes. Client copies show the itemised list only when
  `show_deferred_backlog_to_client` is on, read as it stands now. With no
  agreement, nothing is shown. Operator copies always show it.
- **2026-11-06, #617: revenue lane calls.**
  - **Placement:** retainer and recurring-item actuals sit in the month of
    their own `line_date` (the cycle they pay for), not the invoice's service
    month. Everything else uses the service month, falling back to the issue
    date.
  - **No double counting:** a charge already billed in advance shows as an
    actual in its own month and is not projected again.
  - **Agreements projected:** active, paused, terminated and expired, the
    same set generation bills, each stopping at `ends_on`, else
    `terminated_at`. One-time agreements are never projected.
  - **Overage average:** only `additional_hours` and `prior_month_billable`
    feed it. Termination billing and buy-down settlements are one-offs and
    are left out.
  - **Credits** net in the "Overage & other" band of their month. Unknown
    stored line types count as "Overage & other" rather than being dropped.
  - **Collected basis:** a payment is split across its invoice's pre-tax
    bands. Refunds net against their payment.
  - **Open A/R** includes tax, since it is a balance owed rather than revenue.
  - **Toggle defaults:** projected overage is off and expenses are on, as on
    the dashboard. A figure that's switched off is `null`.
  - **`revenue:read` scope:** it joins the read-only preset, and
    `finance:read` does not imply it.
  - **Agreement health** builds one capacity ledger per agreement in force, so
    its cost grows with the number of agreements. Accepted for now, because
    agreements per workspace are few and the existing ledger is the source of
    truth. Revisit if a workspace reaches dozens.
- **2026-11-06, #618: payment connection calls.**
  - **Reuse** is a second row that holds no key and reads credentials from its
    source. Both workspaces must have the same owner, and the person setting
    it up must be owner or admin of both. Revoking the source revokes every
    workspace that reuses it, in the same transaction.
  - **Environment keys** keep serving a workspace only until that workspace
    has ever had a connection, or the migration command has run.
  - **Stripe webhook endpoints** are created by SVC through the API. **Square
    endpoints** are registered by the owner at the URL SVC shows, after which
    they enter the signature key.
  - **No API exposure:** payment keys are saved through browser-only routes,
    never REST or MCP.
  - **Payments page:** there was no workspace settings page, so it has its own
    page at `/workspaces/{workspace}/payments`. It will move into settings
    when one exists.
- **2026-11-06, #619: Operations is not retired yet.** The Operations page
  still hosts live tools with no other home:
  - proposal draft and send;
  - agreement activation;
  - billing schedule creation and generating due invoices;
  - the per-project manual time form;
  - the client attachment panels.

  So it stays reachable unchanged from the workspace menu as "Operations
  (classic tools)", inside the new navbar. Its tests are kept. The Dashboard
  becomes the landing page and links into it. Operations is retired in a
  later step, once the client-overview and invoice work has re-homed each
  tool.
- **2026-11-06, #619: other navigation calls.**
  - **Lint:** the repo lints with oxlint, so the UI rules are a local oxlint
    plugin.
  - **Agreements tab:** it is an anchor on the client overview until an
    agreements list exists.
  - **Cross-client Invoices, Time and Finance pages:** owners and admins
    only.
  - **Members' workspace navigation:** Dashboard and Clients.
- **2026-11-06, slice 12: invoice document calls.**
  - **Golden facts:** the existing #614 golden tests pass unchanged against the
    new template, and a parity test renders invoices through a frozen copy of
    the old template (`tests/Support/Billing/Legacy`) and the new document and
    compares every printed fact. Delete the copy and that test one release
    after the new document ships.
  - **Not printed today, so not lost:** the old template had no payment
    instructions and no void notice. The document adds a pay-online line (a
    portal URL, only for a collectible invoice the client can see) and a void
    notice with its date.
  - **Grouping:** a stored line type that is not an `InvoiceLineType` case
    prints under "Adjustments and credits" rather than vanishing. Lines are
    grouped by section, so their order is by section and then sort order.
  - **Proforma:** built from the period preview inside its rolled-back
    transaction, for the client's audience, with no number, for the current
    cadence period of each active recurring agreement. An already-invoiced
    period, or a billing rule that cannot price it, is said in one sentence
    instead of a document. A proforma draws no Appendix B (it asks for no
    receipts, and a client is told of none).
  - **Portal rail:** approve or dispute hours is not offered, because
    client approval (slice 8) has not landed.
  - **Stripe:** the browser confirms the payment with Stripe.js, loaded when
    the client asks to pay. Card payment is offered only when the deployment's
    publishable key (`services.stripe.publishable_key`) is known to belong to
    the account that creates the intent: the workspace has no connection and
    the environment may serve it, or its connection is, or borrows, the
    environment one. A workspace on a key of its own gets no card option
    (Square's page still shows). A per-connection publishable key is the
    follow-up that lifts this.
- **2026-11-06, #624 item 5: engagement tools re-homed.**
  - **Where:** the client tier's Agreements tab is now a page of its own
    (`clients.agreements`), replacing the `#agreements` anchor on the Overview.
    It holds proposal draft and send, agreement activation, billing schedule
    create and generate-due. Draft invoice moved to the client's Invoices tab.
    Record payment and Issue already existed on the invoice page.
  - **Same behaviour:** every control posts to the same agent API route with the
    same confirmation; the server sends the finished URL, or `null`.
  - **Permissions:** the lists follow the client's reach (a member sees only a
    client whose project they belong to; Operations showed every client to every
    member); the controls are for owners and admins as before.
  - **Left on Operations:** the per-project time form, the per-record file
    panels, and the invoice list with its Issue and payment forms, which
    duplicate the invoice page and are retired with the page. Each moved tool
    is replaced there by a link.
- **2026-11-06, #624 items 5 and 11: Operations retired; Agreements forms
  labelled.**
  - **Retired:** the Operations page, its controller and route handler, its
    "Operations (classic tools)" menu entry and `operations_href` in the
    navigation payload. `/workspaces/{workspace}/operations` stays as a
    redirect to the Dashboard (named `workspaces.operations`, listed in
    `BROWSER_ONLY` and the help-coverage exemptions like `clients.manage`), so a
    bookmark lands somewhere sensible.
  - **Where each tool went:**
    - *Per-project time form:* it was only a "Log time" link to the time sheet.
      That link now sits on the project page (`log_time_href`, sent by the
      server, `null` unless the viewer may log time on that project, so the
      sheet's 404 is never offered) and was already on Client Home. The last 25
      entries per project that Operations listed are not repeated: the Time tab
      has the entries, the project page has the totals.
    - *File panels:* the proposal, agreement and invoice pages already carried
      the same panel with the same upload, delete and sharing capabilities. The
      Operations copies were duplicates and went.
    - *Issue and Record payment:* the invoice page has both, with the same
      routes, versions, idempotency and recovery. The Operations copies went;
      their frontend tests moved onto the invoice page's tests.
  - **Not on the list but live, so moved:** the per-client Activity feed (latest
    100 events, with the invoice correction link) was only on Operations. It is
    now the last card of Client Home's overview. Decision: that overview is for
    owners and admins, so the feed is too. Operations showed every client's
    feed to every workspace member whatever projects they held; narrowing it is
    the same fix #624 item 5 already made for the lists, and a member's feed
    carried payment and correction detail. The correction link is still only
    sent to owners and admins.
  - **API links:** `web_url` on a workspace is now the Dashboard, on a project
    the project's own page, and on a time entry the Dashboard. The old value
    named Operations and ignored the `?project=` and `?time_entry=` it carried.
    A time entry has not loaded its client, so naming the time sheet would cost
    a query per row; this is the cheap honest answer until it does.
  - **Tests:** nothing was deleted before its assertion had a new home. The
    isolation, mixed-tenant, record-scoped file, per-row-query and real-column
    assertions are in `RehomedOperationsToolsTest` and `RevenuePagesTest`, the
    file-authority and pagination ones in the attachment tests, and the Issue
    and payment-refusal ones in `invoice.test.tsx`. No query-count bound was
    raised.
  - **Item 11:** every field in the proposal, billing-schedule and
    invoice-draft forms has a visible label (`FormField`, ids from `useId`, so
    a schedule form repeated under each agreement never shares one), no
    placeholder stands in for a label, and the schedule cadence is the shared
    `Select` with an `items` list instead of a native `<select>`. Each
    schedule form is named after its agreement. The agreement page's deferred
    buy-down target is still a native select; it is a different page and is
    left for its own change.
- **2026-11-06, #617: mutation gate.** The mutation job can show success
  while the score is under its 82% threshold. Before every merge, the actual
  counts in the log are read.

- **2026-11-06, slices 6 and 7: My work and the portal home.**
  - **Who gets "My work":** every workspace member who is not an owner or admin,
    as the first workspace tab. The Dashboard and Clients tabs #619 gave members
    stay; removing them is a navigation change nobody asked for. Owners and
    admins get null.
  - **Task status inline:** only where the viewer owns or manages the task's
    project, because `tasks.update` requires that. Letting an assignee change
    their own task's status is a permission change, deferred. Others get the
    status as words and the task page.
  - **Quick log:** a project select (with labels), not yet the search-as-you-type
    combobox of section 9; posts to `time_entries.log` as billable, internal.
  - **"Next invoice (estimate)":** the first of the next three cadence cycles
    with something unsold: retainer, recurring items on their own dates, and
    hours past the retainer so far at the agreement's rate. Charges a charged
    invoice already sold are skipped. Excludes tax and expenses, and says so;
    portal expenses are still an undecided disclosure. An unstated rate leaves
    the overage as hours, priced when billed.
  - **This period** reads one agreement: the most recently started active,
    disclosed one in force and inside the viewer's project scope.
  - **Hours available** is the period's included hours plus rollover at its start;
    overage is the ledger's negative balance at the period's latest row.
  - **Pay** is the first-due invoice's hosted (Square) payment page, else absent;
    View invoice is always offered. Amount due is withheld across currencies.
  - **Deferred work** on the home is totals only, through
    `InvoiceDeferredWorkDisclosure` on the latest invoice.
  - **Open tasks** are ordered as before; the schema has no "waiting on the
    client" flag. "To approve" is not drawn (slice 8).

- **2026-11-06, slice 10: reviewer grants and the cross-workspace queue.**
  - **What a grant is:** the provider's explicit, revocable, audited permission for
    a person or a workspace to *approve or dispute* one client's hours and
    expenses. Review marks (submitted, reviewed, flagged) wait for slice 11; a
    reviewer's dispute with a note is today's flag.
  - **Scope:** one client of the granting workspace, optionally one project.
  - **Grantee:** a contact of that client, matched by the address on the record,
    or a workspace that shares a project with the granter, reached through its
    owners and admins. Read live on every request.
  - **Who a grant never reaches:** the granting workspace's own staff, and anyone
    holding a portal membership of that client. A membership decides alone
    (an explicit no stays a no), and the same rows never appear twice.
  - **What a reviewer sees:** the portal queue's rows (date, time, the client-facing
    description, project, an expense's amount) with no invoice link, rate or
    internal note. Only rows the provider has marked ready to bill are on offer, as
    in slice 8; Other Eng's own time is on the list once someone else in its
    workspace has approved it, until slice 11 adds submission.
  - **Where an approval lands:** the provider's own rows, stamped as the reviewer's
    with source `reviewer`; it stands like a person's portal approval and a paid
    invoice leaves it alone.
  - **Audit:** granting, revoking, every view that discloses rows (a count, never
    content) and every decision are written to the granting workspace's access history.
  - **Revocation:** the grant is read again as the first statement of the deciding
    transaction. No lock is taken on the grant table.
  - **Browser-only:** none of it is an agent operation, so there is no REST or MCP
    surface; the four routes are named exceptions in `WebApiParityTest`.

#### Owner decisions, 2026-11-06 evening

Answers the owner gave in an interview on 2026-11-06.

- **#615** was closed as superseded by #622 and #626.
- **Receipts ready to bill:** owners and admins may still mark their own
  expense receipts ready to bill. Self-approval is forbidden only on the client
  side.
- **"Can approve time and expenses"**, the per-contact toggle, shipped in #633.
- **Slice 11** (the light partner workspace) is built after slice 10.
- **MCP fallback:** a per-credential "Show all tools" setting, for connectors
  that can neither set a header nor handle `list_changed` (this PR; #624 item 1).
- **The #618 migration** has a runbook (#633). The owner runs it.
- **The Stripe publishable key** is checked live against the restricted key's
  account on save (#633).
- **Agreements page:** members see a client's Agreements page only for clients
  they have a project with (#630). Owners and admins see all.

- **2026-11-08, slice 11: the light partner workspace and the approved timesheet.**
  - **Submission:** an author's own draft hours and expenses get `submitted_at`
    and `submitted_by_user_id`. It approves nothing and moves no status. A draft
    is on a reviewer's or a portal contact's queue only while submitted; rows the
    provider marked ready to bill are on it as before. Withdraw clears the stamps
    and is refused once anyone has approved the row, or once it is marked ready to
    bill (that is a manager's to return to draft).
  - **Frozen while submitted:** a submitted time entry or expense cannot be edited
    or deleted; withdraw, change, submit again.
  - **Disputes:** a disputed row is withdrawn, fixed and submitted again; submitting
    again clears the dispute, as the author's answer to it.
  - **Who may decide a submission:** never its author, and never the provider's own
    staff when the row is on the queue only because it was submitted. A grant never
    reaches the provider's staff; the portal path now refuses them too (409).
  - **No locks:** submit and withdraw are conditional updates, so nothing is added to
    the billing lock order.
  - **Approved timesheet:** hours with a person's approval in force (a reviewer's, or
    the client's contact's in the portal) for a calendar month, never one implied by
    payment, pending, disputed or draft. Hours only; expenses are not on it. It
    prints the client-facing line, never a rate or an internal note. An owner or admin
    reads the workspace's, a member their own. CSV (formula-safe) and PDF.
  - **Light workspace:** `workspaces.edition` is `full` or `light`. A light workspace
    hides Dashboard, Invoices, Time, Finance and a client's Agreements and Invoices
    from navigation and opens on My work. That is navigation, not an authorization
    boundary: the endpoints behind them authorize as before. An owner or admin turns
    the full workspace on, one way, in the browser.
  - **Created on acceptance, only when the person manages no other workspace:** the
    invitee names the client they bill (the invitation never tells them the host's
    client) and, optionally, the workspace. It gets that client, a project named like
    the shared one and linked to it, and nothing of the host's. Nobody becomes a
    member of the host's workspace. No agreement is created: its rate is theirs to
    set once they turn agreements on.
  - **Agent surface:** `submissions.list`, `submissions.submit`, `submissions.withdraw`
    and `timesheets.approved`, REST and MCP. Expenses ride along only for a token with
    the expense scope and the expense cutover. The edition switch is browser-only.
  - **No data command:** nothing needs moving, so none was written. The one-off move of
    Direct time (Migration, above) stays a separate, explicit, per-participant command.
- **2026-11-06, #624 items 2 and 10: accounting reads design and agent
  workspace discovery.**
  - **Journal injection:** `InvoiceLifecycleService` takes `InvoiceAccountingJournal`
    as a constructor dependency with a `new InvoiceAccountingJournal` default,
    like its other collaborators, so the two existing tests that build the service
    by hand keep working unedited. The journal is stateless; behaviour is unchanged.
  - **Cash income load:** the SQL union now selects only the keys a change feed
    needs (tagged id, public id, effective date, change stamp, version). The
    page's facts are loaded afterwards by primary key and workspace in at most six
    queries and classified by `CashIncomeClassifier`, which touches no database.
    The union itself stays, because paging, `updated_since` and the cursor are
    defined over one ordering of all three sources and must not change. The
    yearly export hydrates in chunks of 100, so its queries grow with chunks, not
    rows. Money stays integer minor units; no floats.
  - **Missing facts fail loudly:** a key row whose payment, refund or movement
    has vanished between the two reads throws rather than reporting zeros. These
    rows are immutable and never deleted, so this is a corruption signal.
  - **Operation catalog:** the accounting reads carry an `AccountingDeclaration`
    (response component, optional download media type) on their declaration. The
    catalog no longer recognises them by id.
  - **No new scope for workspace discovery (item 10):** `identity:read` stays
    the scope behind `context.get`; widening a narrow token's reach is a product
    decision. Instead the connection says what to ask for: the server instructions
    of a connection without `context.get`, `toolsets.*` results (`data.notes`,
    only for a connection lacking `identity:read`), and the `context.get` refusal
    all name `identity:read`. `docs/mcp.md`, the MCP help page and the scope's
    consent text now say agents need it.

- **2026-11-09, #636: autopay.** Settled by the owner: a per-company limit set by
  whoever turns it on (it replaces the $1,000 online cap for autopay only), no
  retries, client consent in the portal or a provider attestation, the notice on the
  issued invoice, and company scope only. Decided while building, conservatively:
  - **The premise was wider than the code.** `docs/client-management/stripe-billing.md`
    describes a $1,000 cap in `config/client-management.php`, portal method
    management, a stored `failure_reason` and a Stripe-failures filter. None of them
    exists in this checkout: there is no such config file, the webhook stores no
    failure reason, and the portal had no billing page. So the limit is the only
    ceiling autopay has (and it has no default: no limit, no autopay); the failure
    reason lives on the attempt row, and a refused charge also enters the ordinary
    payment list as a `failed` payment; the portal Billing page is new, and shows
    the saved methods read-only. Choosing or adding a method there is not built:
    the mockup's method picker is a read-only line, because nothing in the app can
    yet change a default (that arrives from Stripe's events).
  - **Who consents.** A portal contact with company-wide access. Workspace staff
    previewing the portal can read the page and cannot consent for the client, and
    a contact limited to some projects does not speak for the company's money. The
    provider side is an owner or admin only (the existing "workspace manager").
  - **The wording is stored, not rebuilt.** The server composes the authorization
    sentence from the provider, company, default method and limit; the page shows
    the same sentence beside the checkbox and sends it back; a request whose
    wording is not exactly the server's is refused. Both sides are pinned to one
    literal in their tests.
  - **A provider turn-on needs someone to tell.** It is refused when the client has
    no billing address and no portal contact, and when the attestation is shorter
    than 10 or longer than 1,000 characters. The notice goes to the billing address
    and the portal contacts after the change commits, in the same request, with
    `notice_sent` recorded on the consent row. A failed email does not undo the
    change. A provider changing the limit sends the notice again.
  - **Autopay never reaches back.** An invoice that fell due before autopay was
    turned on, on the workspace's calendar, is not charged. Turning it off and on
    again moves that day forward.
  - **Currency.** The limit is in the client's billing currency (else the
    workspace default). An invoice in another currency is left for the client.
  - **A payment already moving is waited for.** A pending (or unreadable) payment
    on the invoice means autopay claims nothing this run; it looks again next run.
  - **Claimed with no PaymentIntent is a review item, not a retry.** If Stripe
    cannot be read (a timeout, a 5xx) the attempt stays `claimed`; every run of the
    command lists it after ten minutes and logs a warning. Nothing resolves it
    automatically. A command to close one out is follow-up work.
  - **The claim is given back only when nothing was sent.** After the claim the
    facts are read again; if the invoice changed (a payment, a void, a different
    balance or default method) the claim row is deleted and the invoice is looked at
    again next run. After Stripe has been called the row is never deleted.
  - **Over limit is not an attempt.** It is a state, shown to both sides before the
    due date ("Not autopaid: over limit"); no row is written, so raising the limit
    before the due date makes the invoice chargeable.
  - **Skipping is the invoice's one attempt row** (`state = skipped`, who, when).
    It needs autopay on and nothing attempted yet, cannot be undone, and is
    idempotent.
  - **The default can change; removing it turns autopay off.** Autopay charges
    whichever method is the default on the due date, so choosing another default
    keeps it on. Removing the default (a detach, or Stripe clearing it) turns it
    off with reason "Default method removed", recorded by SVC, deferred to after the
    webhook's transaction commits so it takes no new lock inside it.
  - **The metadata is the portal pay path's:** `invoice_public_id` and
    `workspace_public_id`, which is what the webhook resolves an invoice from, plus
    the company and the attempt. The charge is entered as a `pending` payment with
    the PaymentIntent id; Stripe's events settle it. Nothing else books money.
  - **Scheduled, not flagged.** `svc:billing:charge-autopay --apply --limit=50`
    runs every fifteen minutes. It is inert until a company opts in, so it has no
    deployment flag like the cadence drafts; without `--apply` it lists what it
    would charge and calls nothing.
  - **Surfaces.** REST and MCP: `companies.autopay.get` / `.set` and
    `invoices.autopay.get` / `.skip`, owner and admin only, with the confirmation
    and attestation required to turn it on named in the MCP server's instructions.
    The portal's consent and withdrawal are browser-only (the client holds no agent
    credential), classified in `WebApiParityTest`. The "Will be charged to ... on
    ..." line is part of the invoice document, so the PDF, the portal and the email
    attachment carry it, and the email body repeats it.
  - **Known limit.** Between the last-word read and Stripe's answer, a client paying
    the same invoice by hand could also succeed; the PaymentIntent is created
    first and recorded after, as the portal path does, so the overpayment would be
    handled as an overpayment credit. The window is the length of one API call.

- **2026-11-10, #641: autopay follow-ups.** Settled by the owner: a provider-side Stripe
  refusal keeps the invoice's attempt. Decided while building, conservatively:
  - **What counts as our connection.** `AuthenticationException` and `PermissionException`
    always; `InvalidRequestException` only for the codes that name the key or account
    (`api_key_expired`, `account_invalid`, `platform_account_required`,
    `secret_key_required`, `livemode_mismatch`, `testmode_charges_only`). Other invalid
    requests (a deleted customer, a bad parameter) say nothing reliable about the key and
    stay failed attempts, as does every card error. A network failure or 5xx stays "held
    for review", as before.
  - **Release, not record.** The claim is deleted, as when the invoice changed after it,
    so no `failed` payment, no attempt row and nothing in the client's portal. The
    invoice's next run claims it afresh with a new idempotency key.
  - **The marker is `workspaces.stripe_refused_at`.** There was no connection-health
    field. It is on the workspace rather than the connection row because a workspace
    still served by the deployment's environment key has no row. It is ended by a
    `connected_at` later than it (re-saving or re-using a connection) or by the next
    ordinary Stripe answer, never by the clock alone: the six-hour backoff only decides
    when the command tries again. A save in the same second as a refusal does not end it.
  - **Six hours, per workspace.** While paused the run reports each due invoice as
    `connection_needs_attention` without calling Stripe and without spending `--limit`,
    so one broken workspace cannot starve the others. A preview reports the pause too.
  - **Told once, shown always.** One `invoice.autopay_connection_refused` activity per
    breakage (not one per retry); the warning on the client's Autopay card and on the
    invoice's Autopay card (invoices still waiting to be charged) stays until fixed. The
    client's reads never carry it.
  - **Close-out waits ten minutes.** The same age the command reports a held attempt at,
    so a run that is still talking to Stripe cannot be contradicted. An invoice whose
    command hangs past that and then charges would find its attempt already closed; the
    command's 30 minute overlap guard makes that a near-impossibility, and the charge
    would still reach the ledger through Stripe's webhook.
  - **Charged is verified, not trusted.** The intent is read through the existing
    adapter before the mutation's transaction opens (the mutation executor wraps its
    callback in one), then the write is a conditional update. A mismatch on workspace,
    invoice, attempt, amount, currency or a status other than `succeeded`/`processing`
    is a 422 in words and changes nothing. The same idempotency key replays without
    reading Stripe again.
  - **One operation, `invoices.autopay.resolve`,** with `outcome` `charged` | `not_charged`
    on the invoice's version, owner and admin only, `confirm: true`, and a sentence in the
    MCP instructions. A new attempt state `not_charged` and invoice state
    `resolved_not_charged` ("Not autopaid: left for manual collection"); the portal tells
    the client to pay it by hand, as for a skip. Two new columns record who closed it out
    and the note.

- **2026-11-07, #642: clients manage saved payment methods in the portal.** Approved
  by the owner: add (card and US bank account), make default, remove, on the portal
  Billing page, so autopay is self-serve. Decided while building, conservatively:
  - **Who:** the contacts who may consent to autopay (`PortalAutopayAuthority`:
    company-wide portal access). Staff previewing the portal and project-scoped
    contacts see the list and no controls. The server sends finished URLs
    (`payment_method_setup`, and `default_href` / `remove_href` on each method) or
    null, plus a finished sentence (`payment_methods_notice`) saying why there are
    none. One decision, `PaymentMethodManagement`, serves the page and the routes.
  - **Gated on the workspace's Stripe connection, in two levels.** Changing a
    method (default, remove) needs the connection's key; adding also needs the
    publishable key the portal pay path uses (`stripePublishableKey`), because the
    browser mounts Stripe's form with it. No connection: the section says methods
    cannot be added or changed. Connection without a publishable key: the client
    can still remove a method or change the default, and is told adding is
    unavailable.
  - **Stripe's own Payment Element, never SVC's fields.** The server creates a
    SetupIntent (`usage: off_session`, `payment_method_types: card, us_bank_account`,
    bank verification `automatic`) and returns only its client secret. Financial
    Connections permissions are not requested (the flag in the docs is not
    implemented in this checkout), so a bank account verifies by Stripe's
    instant or micro-deposit flow, whichever it offers; a micro-deposit method
    stays "pending" and the webhook records it when Stripe succeeds.
  - **The company's Stripe customer is created on first use**, at Stripe with an
    idempotency key derived from workspace and company, then locally; the row is
    unique per company, so a race or repeat ends with one customer. Nothing else in
    the codebase created that mapping before (it existed only in fixtures), so
    this is the first path that can. Only the company name and public ids are sent
    to Stripe, not an email address.
  - **The browser asks the server to read the SetupIntent back.** After Stripe
    confirms, the browser posts the SetupIntent id; the server retrieves it with
    the workspace's key, refuses one whose customer is not this company's (another
    company's in the same Stripe account is "not found"), and, if it succeeded,
    records the method through the same `StripePaymentMethodService::attach` the
    webhook uses. Idempotent both ways: a second confirm, or the webhook arriving
    after, changes nothing and records one "added" activity. A redirect return
    (bank or 3-D Secure) carries `setup_intent` in the address; the page reads it
    back the same way and tidies the address.
  - **The first method becomes the default.** Autopay needs a default and Stripe
    sets none on its own, so when a company has no default method the one just
    saved is made the default (Stripe, then locally). Later methods never take the
    default.
  - **Default: Stripe first, mirror second, without moving the event marker.**
    `invoice_settings.default_payment_method` is set through the adapter outside any
    transaction, then `adoptDefault` flips the local flag in a short transaction
    (customer, then methods, the order the webhook uses). It deliberately does not
    write `default_payment_method_event_*`: our clock is not Stripe's, and
    advancing the marker could make a genuine later dashboard change look old. The
    `customer.updated` for the same change then finds nothing to do. If the process
    dies between the two writes the webhook brings the record in line.
  - **Remove: detach at Stripe, then forget locally.** A method Stripe already
    reports as gone is removed locally too. The local removal is the webhook's own
    (`forget` shares `forgetLocked` with `detach`) and works for a method with no
    routing-state row; it stamps the state row detached so an older attach event
    cannot resurrect it. Removing the default turns autopay off with reason
    "Default method removed", recorded as SVC's, deferred to after commit exactly
    as the webhook path does it. The confirmation dialog says autopay will turn off
    when it is on and the method is the default, and says it will not otherwise.
  - **Failures are refusals, not detail.** A Stripe error is logged with its code and
    the client sees "Stripe could not complete that. Try again in a moment."
    Nothing local changes when the Stripe call fails.
  - **Browser-only routes.** Four new web routes, classified in `WebApiParityTest`:
    the client holds no agent credential and these are their own acts in the
    portal. They send `Idempotency-Key`, which becomes the SetupIntent's Stripe
    idempotency key so a retried request is handed the same intent.

### Stripe webhook health (check and fix)

SVC checks, and for an owner or admin can fix, its own Stripe webhook
subscription. Decisions, made unattended and conservatively:

- **One list.** `StripeWebhookService::EVENTS` is every type the service handles
  and `REQUIRED_EVENTS` is that list without the legacy alias
  `charge.refund.updated`. The endpoint SVC creates subscribes to `EVENTS`
  (as before); the check requires `REQUIRED_EVENTS`. `refund.updated` is required
  on its own: `charge.refund.updated` is still handled when it arrives but never
  counts in its place (the earlier `STAND_INS` is gone). `StripeWebhookEventsTest` reads the service's source and
  fails if a handled type is not listed or a listed type is not named in a
  handler. The old `StripeKeyAdapter::EVENTS` is gone.
- **Required, not minimal.** The dispute hint events (`updated`, `funds_withdrawn`,
  `funds_reinstated`) are handled and registered by SVC today, so they are
  required: an endpoint following only the documented list is told to add them.
- **Only the stored endpoint is judged.** The signing secret SVC holds belongs to
  the endpoint SVC created (the stored `webhook_endpoint_id`); a different endpoint
  at the same URL signs with another secret and every delivery would fail
  verification, so it is never adopted and its subscriptions prove nothing. If the
  stored id is not listed at Stripe the status is `endpoint_missing` ("The webhook
  endpoint SVC was set up with no longer exists"), never healthy, with no event
  patch offered. If the connection has no stored id (migrated from environment
  configuration), a URL match is reported as `endpoint_unverified` ("SVC cannot
  confirm which webhook endpoint is its own"): decided conservatively, since even
  a fully subscribed match at the legacy URL cannot be shown to be the one the
  stored secret belongs to; with no match at all it stays `not_found`. A stored
  endpoint that now has another URL is `not_found`. The repair for both identity
  statuses is the explicit **Reconnect webhook** (`reconnect`), which creates a new
  endpoint and stores its id and signing secret in one save (the Stripe call is
  outside any transaction; if the save fails the new endpoint is deleted again).
  The plain **Create endpoint** (`create`) remains only for `not_found`.
  **Add missing events** requires the judged endpoint to be the stored one.
  URLs compare by scheme, host, port (default dropped) and path, case-insensitively
  for scheme and host, trailing slash and query ignored. The connection made from
  the environment also accepts the legacy `/api/webhooks/stripe` URL.
- **Stored on the connection row**, three nullable columns
  (`webhook_check_status`, `webhook_check_missing`, `webhook_checked_at`): the
  row is already workspace-scoped, so no new table or foreign key. A failed check
  (Stripe unreachable) is stored as `unreachable` and shown as "could not be
  checked", never as a webhook problem.
- **Non-blocking on save.** The check runs after the save has committed, outside
  any transaction, and any failure is logged (class and workspace id, never the
  key) and swallowed.
- **Reused connections have no health of their own.** They are served by their
  source's endpoint with the source's key, which the borrowing workspace may not
  use; the source workspace shows and fixes it. The check and fix routes answer
  404 for them, and the daily command skips them.
- **The command only checks.** `svc:payments:check-stripe-webhooks` (preview
  calls nothing; `--apply` checks, `--workspace=`, `--limit=`) runs daily at
  03:35 UTC without a queue. Fixing stays a button.
- **Fix never removes an event and never touches another endpoint.** Add events
  sends the union of what the endpoint has and what it lacks. A disabled endpoint
  is reported with instructions, not re-enabled: the owner may have disabled it
  on purpose. Create (nothing at the URL, no recorded id) and Reconnect (see above) create at
  the connection's URL with `EVENTS`, store the new signing secret and endpoint id
  together on the same row through the model's `encrypted` cast (replacing the old
  secret, which belonged to an endpoint that no longer exists or could not be
  confirmed), and delete the endpoint again if they cannot be stored. The browser sends the action
  the person confirmed; the server re-reads Stripe first and refuses (422) a
  fix that the fresh state no longer calls for.
- **Daily order.** `svc:payments:check-stripe-webhooks` takes connections never
  checked first, then by `webhook_checked_at` ascending, then by id, so a run bounded
  by `--limit` does not starve the same workspaces.
- **Errors name the permission.** A 403 on the read says to set Webhook
  Endpoints to Read; on the write, Write.
- **Visibility.** Everyone in the workspace sees the sentence and the time; the
  endpoint URL (which carries the connection token) and the buttons are for
  owners and admins only, as the Square webhook URL already is.
- **No REST or MCP.** Payment connections have no agent reads or writes today, so
  there is nothing to sit beside; the check and fix are browser-only routes in
  `WebApiParityTest` with reasons.
- **No Stripe calls on page load.** The page prints the stored result.

### Migration impact inventory and explicit ownership (2026-11-08)

A reviewer asked for an inventory of what `svc:payments:migrate-environment`
changes before the owner approves running it in production, and noted the
runbook overstated rollback. Decisions, made unattended and conservatively:

- **The preview is the inventory.** The default (no `--apply`) run, and
  `--format=json` under the `inventory` key, report per processor: the masked
  environment credential and its mode, who would own the connection and the
  rule, every workspace that relies on the environment credential with counts
  (open invoices payable online, autopay companies, saved cards, pending
  payments, autopay attempts held for review, webhook events in the last 90
  days; for Square, invoices linked to a Square invoice, linked customers,
  pending payments and webhook receipts), the existing connections that block
  or are untouched, and for Square the location rule and what the local mirror
  says about other locations. It reads with grouped queries, one per measure
  for all workspaces, so the query count does not grow with the number of
  workspaces, and it runs only SELECTs.
- **No new API call.** The preview already called neither Stripe nor Square, and
  still does not. The Stripe account, the Square merchant and the chosen Square
  location therefore read "not checked"; `--apply` reads and stores them. Nothing
  was added to the preview path that talks to a processor.
- **Ownership was implicit, so it is now refused when ambiguous.** The command
  already took `--workspace`, but fell back to `SQUARE_WORKSPACE` without
  asking. That is sound for Square (the environment's Square token has only ever
  served that one workspace) and a guess for Stripe (the environment's Stripe
  key serves every workspace that has not connected its own, and
  `SQUARE_WORKSPACE` says nothing about Stripe). Ownership is now **ambiguous**
  when no `--workspace` was given and another workspace than the configured one
  has Stripe activity (any of the inventory counts above) while having no
  connection of its own. `--apply` then refuses with exit code 2 and writes
  nothing; a preview prints the inventory with the owner "not decided" and the
  same exit code. An explicit `--workspace` always settles it, and the other
  dependent workspaces are listed as losing access. Apply behaviour is
  otherwise unchanged: with an unambiguous owner (explicit, or the only
  workspace with activity) it does exactly what it did.
- **Rollback is described accurately.** The runbook no longer says the only undo
  is a restored backup. Disconnect, Replace key and the conditional manual
  database reversal each undo something, and the runbook says what, and under
  which conditions. It also lists what cannot be undone: money already charged,
  messages already sent, webhook events already applied or refused, and the
  deleted rows' contents.

### Accessibility to zero (#624 item 4, 2026-11-08)

- **Every screen gates, in both themes.** The `uiGated` list and the
  measurement-only exemption for axe and UI-consistency are gone: any axe
  violation at any impact, and any UI-consistency error, fails the harness on
  every capture, light and dark. Overflow and navbar assertions keep their
  existing `measuredOnly` exemptions (only the autopay and consent states use
  it); axe and the token checks do not honour it.
- **Advisory stays advisory: a text button with no icon.** `action-button-icon`
  still reports without failing. About a hundred buttons are legitimately
  text-only (Cancel, Dismiss, Void, recovery buttons), and putting an icon on
  each would be a visual redesign, not an accessibility fix. Revisit if the
  list is ever worth gating.
- **A modal dialog counts alone for "one filled button".** While a modal is
  open the page behind it is inert, so only the dialog's own buttons are
  counted; the page is counted whole again when it closes. This is a change to
  what the check counts, made because the dialog's confirm button and the
  page's one primary button are never both actionable. Pages with several
  independent forms (client settings, task planning, payment connections) went
  to outlined buttons rather than choosing one form's Save as the primary.
- **Tokens, not overrides.** Light `--destructive` darkened (0.577 to 0.48
  lightness) and dark lightened (0.704 to 0.78) so destructive text is 4.5:1 on
  its own tint and hover tint; light `--muted-foreground` darkened (0.556 to
  0.5) for text on `--muted`; `--ring` darkened/lightened to 3:1 against the
  background and card. The operations "bridge" palette gained dark mappings for
  `text-slate-700` and `text-cyan-700`.
- **Card titles are level-2 headings by default** (`CardTitle` has
  `role="heading" aria-level={2}`); a page whose title is a card passes
  `aria-level={1}`. A page with no visible title (portal billing, portal
  invoice) has a screen-reader-only `h1`.
- **Scroll regions** become keyboard-focusable only while they overflow
  (`useScrollable`), instead of a tab stop on every table.
- **Native `<select>` is banned, all of them.** The task named the buy-down
  picker; the grep found nine more (appearance, portal scope, project access,
  history, three on collaboration, two on expense schedules). All use
  `OptionSelect`; `native-select.test.ts` guards it. An option whose stored
  value is the empty string is carried under a stand-in value, because Base UI
  reads `''` as "nothing chosen".
- **Skip link finds `<main>`.** Pages draw their own `<main>`, so the link
  gives the first one an id and `tabindex="-1"` on mount instead of every page
  naming a target.
- **Toasts.** The application renders none (the Sonner `Toaster` is unused), so
  announcements stay `role="status"`/`role="alert"` in the page; nothing was
  added.
- **Harness upkeep.** Four groups already failed on a clean `origin/main`
  (clients, disputes, square, tasks) because the UI had moved on: the client
  switcher is named "Current client: ...", a disputed invoice offers no "Send to
  client", a client sees "Still deferred" only when the agreement opts in (the
  Square fixture's invoice had no agreement), and the section tabs collapse
  below `xl`. The harness and seed follow the product; no product behaviour
  changed. The harness now runs with reduced motion and waits for styles to
  settle after a theme switch, because axe otherwise measured half-faded
  popups and mid-transition colours as contrast failures.
- **CI.** Every harness group runs on every push and pull request in eight
  concurrent shards (`layout`), required by `ci` through `layout-gate`;
  `LayoutWorkflowTest` fails when a group has no shard. The collaboration,
  task-organization and attachment-sharing scripts run in `layout-scripts` with
  the same gate (`scripts/layout/a11y.mjs`). The four `workflow_dispatch` layout
  jobs stay as they were.
- **Found by the wider gate.** A task list was a `ul` of `Card`s each holding an
  `li` (invalid list structure) - now `ul > li > Card`; two "Files" landmarks per
  comment became a labelled group; an open menu's popup is not counted by axe's
  `region` rule in the focused scripts (it is a transient widget, not page
  content).

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
