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
  - Charges use the workspace's own key.
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
