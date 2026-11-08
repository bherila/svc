# Interface

How the authenticated screens are built, and why each rule is a rule. Every one
of these was a defect first: the reasoning is here so the rule survives contact
with a screen it was not written for.

## One shell, one column

`resources/js/layouts/workspace-shell.tsx` is the chrome for every page inside
an entered workspace — operator and client portal alike. A page renders its
content and nothing else: no header of its own, no theme control, no client
picker, no "back to somewhere" link that duplicates a tab.

Both the navbar's row and every page's `<main>` use `SHELL_CONTAINER` from
`resources/js/lib/layout.ts`. This is one definition on purpose. When each page
centred a column of whatever width it had picked — `max-w-4xl` here,
`max-w-6xl` there — while the header spanned the viewport, the wordmark sat
against the left edge of the window and the account menu against the right,
lining up with nothing below them. It reads as a margin bug because it is one.

The header still paints its border and background edge to edge; only its
contents are brought into the column. A page that wants a narrower measure for
reading constrains its own content *inside* the container rather than replacing
it, so the outer gutter never moves.

## The navbar is one row, and never wraps

Two tiers, one row. The workspace tier is the row with no client selected; picking a
client drops into the client tier, where the same row names the client.

    ⏻ Workspace   Dashboard  Clients  Invoices  Time  Finance            ⌕  ?  ☾  ⚙
    ⏻ Workspace › Acme ▾ ⋯  Overview  Agreements  Invoices  Time  Tasks  Files   ⌕  ?  ☾  ⚙

Read left to right it is a sentence about where the reader is: which tenant,
which client, then which part of that client — then, pushed to the far end, the
things that mean the same thing whatever the client is. An item whose meaning
changes when the switcher does belongs on the left; everything else belongs on
the right.

The tab set for each tier is chosen on the server per role and arrives as
finished URLs (`WorkspaceNavigationFactory`); a null is a tab that is not
rendered. The switcher opens with "All clients", which goes back up to the
Dashboard, and the menu beside the client's name (⋯) holds its settings and the
modules that are not tabs (Expenses, Notes, Discussions).

The workspace is named, and the exit control sits to its left. This was an
"SVC" wordmark that quietly returned to the selector, and it failed twice over:
nothing on the screen said which tenant you were in, and the one way out was a
piece of branding that gave no sign of leading anywhere. An operator with two
workspaces had to open a client to find out where they were.

Wrapping turns one bar into two and moves the tabs to where the reader has
stopped looking, so the row must always fit. What gives ground, in order: the
workspace name, which disappears entirely below `sm` — the exit button stays,
because losing the way out is worse than losing the label, and the selector it
leads to names the tenant anyway; then the tab strip, which scrolls and then
collapses into a menu naming the section you are in; then the theme control,
which sheds its icon and caps its width. The client switcher truncates its name
but never disappears, because it is the context everything else is scoped by.

Two things that cost real time here:

- **A minimum width belongs on the flex item, not on its child.** A `min-w-*`
  on a button inside a wrapper that has already shrunk makes the button
  overflow the wrapper and *paint over the control beside it*. The row measures
  as fitting while two controls sit on top of one another.
- **Measure, do not squint.** Read the bounding boxes of the bar's children in
  a browser. A screenshot at 390px will not tell you that two boxes overlap.

## A select renders its value, not its label

Base UI's `Select.Value` prints the raw value in the trigger unless the root is
given `items`. So a field the operator had just set to "Bank transfer" read
`bank_transfer` back at them, an agreement's first-cycle rule read `unset`, and
choosing a project on a time entry left a bare UUID in the field. Pass the same
`{ value, label }` list to `items` that you render as `SelectItem`s.

Worth stating because nothing catches it: the markup is correct, the value is
correct, the form submits correctly, and only a person looking at the screen
can see that the control is showing a database value to a reader.

## Tables do not wrap unless you say so

The shared `TableCell` sets `whitespace-nowrap`. That is right for a date, an
amount, an hour count or a status badge, and wrong for the one column on each
screen that holds prose. Left alone, a long time-entry description ran the
sheet wider than its own card and pushed Hours, State and Actions off the
screen entirely.

Mark the prose column, and only it:

```tsx
<TableHead className="min-w-64">Work</TableHead>
...
<TableCell className="max-w-0 whitespace-normal wrap-anywhere">
```

`max-w-0` is what lets the cell take its width from the table rather than from
its content; `min-w-64` on the header keeps the column from collapsing to a
word per line.

## Never truncate what a reader came to read

`truncate` is for an identity string in fixed chrome — the client's name in the
switcher, where the full value is one click away and a tooltip carries it.
Applying it to content throws away the half of the row worth reading: Client
Home's time previews were clipped mid-sentence on the one screen whose job is
saying what happened recently.

Content wraps. Where an unbreakable run is possible — an identifier, a URL, a
name with no spaces — give it somewhere to go with `wrap-anywhere`, and see
[the overflow rules](#checking-a-layout) below.

## The server decides destinations and capabilities

The browser never assembles a URL from ids, and never infers what a viewer may
do. Each client option in the navigation payload carries finished module URLs,
generated after authorization; a module the viewer's route family does not
serve arrives as `null` and its tab is not rendered. Actions arrive the same
way — `actions.issue` is a URL or `null`, not a boolean — because a portal user
reaches the same company through a different route family entirely, and one
person can be an operator of one client and a client of another.

A hidden link is not an authorization check: every endpoint behind one
authorizes again.

## Show stored values as words

Never print an enum column into the interface. `statusLabel` in
`resources/js/lib/labels.ts` turns `partially_paid` into "Partially paid", in
one place, so a list and a detail screen cannot disagree about what a status is
called. It formats rather than looks up, so an enum case it has never seen
reads as words instead of rendering blank — the better failure on a screen
about money.

## Say which kind of nothing it is

"None yet" and "none for you" look identical from an empty list and mean
completely different things: one is an invitation to create something, the
other is a wait for someone else. Decide it on the server, where the
distinction exists, and send both facts.

A repeated empty unit collapses but does not vanish. The time sheet's window is
twelve months, and a client worked for two of them produced ten full cards to
scroll past; those months are now one row each, because "did I log anything in
April" is a question the screen should still answer — and an empty month with a
retainer still has something to say, which is how much capacity went unused.

## Size a field to what goes in it

A name and an email stretched to the full width of the page read as a form
nobody finished laying out, and make the eye travel the whole line to check a
value twenty characters long. Short fields get a measure (`max-w-md`);
descriptions and addresses can be wider.

## Bounded previews link to their module

A preview that does not say where the whole thing lives is a truncated list.
Every section on Client Home shows a handful of rows and one "view all" link,
and the limits live on the view model rather than in each query adapter — two
adapters choosing their own limits is two screens disagreeing about what
"recent" means.

## Accessibility is a gate, not a review comment

Every screen the layout harness opens is checked, at 390, 820, 1440 and 1920
pixels, in the light and the dark theme: any axe violation at any impact fails
it, and so does any UI-consistency error. There is no reporting-only screen and
no measurement-only exemption for these two checks; a new page is held to them
from its first capture. CI runs every harness group on every pull request
(`layout` shards in `.github/workflows/tests.yml`, and `LayoutWorkflowTest`
fails when a group has no shard). The rules below are what the gate keeps
true, and each lives in a shared component so a page gets it by using it.

- **Skip link.** `SkipLink` is the first focusable element of `WorkspaceShell`
  (all three of its branches) and of the welcome page. Pages draw their own
  `<main>`, so the link finds the first `<main>` when clicked, gives it
  `tabindex="-1"` and focuses it. Do not add a second one to a page.
- **Focus is always visible.** Components draw their own `focus-visible` ring;
  `app.css` adds a 2px `--ring` outline as the floor for bare links, summaries
  and native controls. `--ring` is 3:1 or better against `--background` and
  `--card` in both themes - change it only with a measured replacement.
- **Names.** A control's accessible name contains its visible text (WCAG 2.5.3):
  "Make default: Visa ending 4242" for a button that reads "Make default", never
  a reworded sentence. An icon-only button has an `aria-label`. Every field has
  a `Label` (`FormField` pairs them) or an `aria-label`; a placeholder is not a
  label.
- **Choices are the shared `Select`.** Use `OptionSelect` (`components/ui`):
  it passes `items` so the trigger prints the label, and a native `<select>` fails
  `native-select.test.ts`.
- **Errors are tied to their field.** `FormField` takes `error`; hand-built
  forms use `fieldErrorProps` and `FieldError`. The control gets `aria-invalid`
  and `aria-describedby`, the message is `role="alert"`, and focus moves to the
  first invalid control when a submit fails (`useFocusFirstInvalid`).
- **Scroll regions are keyboard reachable.** `Table` makes its box focusable
  whenever the table is wider than it (`tabindex="0"`; pass `scrollLabel` to
  name it as a region). Any other `overflow-auto` box needs `tabIndex={0}` and an
  `aria-label` - axe's `scrollable-region-focusable` fails otherwise.
- **Headings.** One `h1` per page, in `<main>`. `CardTitle` is a level-2 heading
  by default, so a card's own subsections are `h3`; never skip a level.
- **Contrast lives in the tokens.** `--muted-foreground`, `--destructive` and the
  `--ring` are chosen so text is 4.5:1 on `--background`, `--card`, `--muted` and
  on the 10-20 percent `destructive` tint of a destructive button, in both themes.
  Fix a failing colour in `app.css`, not with a per-page override. Dialogs and
  menus fade in; the harness runs with reduced motion so axe never measures a
  half-faded popup.
- **Motion.** `app.css` zeroes animation and transition durations under
  `prefers-reduced-motion: reduce`. New animations need no extra work; do not
  start one from script without checking `matchMedia('(prefers-reduced-motion)')`.
- **Announcements.** Status text that changes (saving, a result, a refusal) is
  `role="status"` or `role="alert"` in the page, not a toast: the application
  renders no toasts today, and the shared Sonner `Toaster` (already an aria-live
  region) is the one to mount if that changes.
- **Dialogs** are Base UI's `Dialog`/`AlertDialog`: they trap focus, close on
  Escape and return focus to the trigger. Use them rather than a positioned div.
- **One filled button.** A modal dialog counts alone while it is open (the page
  behind it is inert); otherwise a page has at most one filled button.

## Checking a layout

**In jsdom, with hostile fixtures.** `horizontalOverflowRisks` in
`resources/js/test/horizontal-overflow.ts` applies the two CSS rules that
produce sideways scroll — an intrinsically sized grid track, and a run that
cannot break — to a rendered tree. jsdom performs no layout, so nothing here
can measure a width; the fixtures have to be hostile enough for the rules to
fire. A client name with no spaces, a description that runs for a paragraph, an
identifier nobody would type. Short fixtures prove nothing about layout, which
is exactly how the original overflow reached production.

Give every single-column `grid` an explicit `grid-cols-1`. A bare `grid` leaves
the column at `auto`, whose minimum is the min-content width of everything in
it — and min-content passes straight through `overflow: hidden`, so one
`whitespace-nowrap` string anywhere in the subtree sizes the whole page.

**In a browser, before calling a layout done.** Drive the real pages at 390,
820, 1440 and 1920 and assert, at each width, that
`document.documentElement.scrollWidth === window.innerWidth` and that the
navbar row is still one row high. Then look at the screenshots: overlap,
clipping and dead space are visible and are not assertable. Seed the fixtures
the same way — a sixty-character client name, paragraph-length descriptions, a
partially paid invoice.

### Run the synthetic browser harness

```bash
pnpm exec playwright install chromium   # once per Playwright browser version
pnpm run test:layout
```

For a focused portal-access pass, run `node scripts/check-layout.mjs --portal-access`.
When the matching browser is unavailable locally, dispatch the `tests` workflow
with `portal_layout: true` on the feature branch. Its disposable runner prepares
the browser and uploads the `portal-access-layout` artifact: client settings at
390, 820, 1440 and 1920 pixels, including project selection and the removal
confirmation. Download that artifact and read every screenshot before calling
the card complete.

For the Square explore and linked operator/portal invoice pages, dispatch the
`tests` workflow with `square_layout: true`. Its `square-layout` artifact covers
390, 820, 1440 and 1920 pixels with oversized synthetic identities and a
mirrored hosted payment URL. Download and inspect every screenshot. The same
focused harness is `node scripts/check-layout.mjs --square`.

For the workspace payment connections page (`/workspaces/{workspace}/payments`), run
`node scripts/check-layout.mjs --payments`. It covers the disconnected, Stripe
connected (masked key only), Square connected, reused and validation-refusal
states, plus the open reuse list, at 390, 820, 1440 and 1920 pixels with unbroken
workspace, account and location names, and asserts the key never reaches the
page. It also covers the Stripe webhook health line in every result (healthy,
missing events with a long list, no endpoint, key without permission, disabled,
and a member's read-only view with no URL or button), with the endpoint URL's
unbroken token, and the fix confirmation dialog. No processor is asked. Read
every screenshot afterwards.

For payment reminders, dispatch with `reminder_layout: true`: its
`reminder-layout` artifact covers the operator invoice with a reminder log of
oversized recipients and reasons, the send confirmation, and the client cadence
switch on settings. Locally, `node scripts/check-layout.mjs --reminders`.

For the Dashboard, client roster and client overview, run
`node scripts/check-layout.mjs --revenue`. It seeds seven clients with unbroken
names, agreements whose titles have no break, millions-priced invoices and
agreements ending inside the projection window, waits for the lazy chart and
the deferred retainer meters, and captures each screen at every required width
with the chart's table open, the Collected basis chosen, and the roster
searched. The build fetches fonts, so build first with the proxy's CA
(`NODE_EXTRA_CA_CERTS`) and pass `--skip-build` where the harness's own
sanitized build environment cannot reach the network.

For "My work" and the portal home, run `node scripts/check-layout.mjs --work-portal`.
It seeds a member who is not an owner or admin (a contributor on one project, a
manager on another) with tasks in every due-date group and time in every review
state, all priced in the rows so a rate reaching the page would show, and a client
contact with an overdue invoice linked to a hosted payment page, an agreement with
a recurring service, hours past the retainer, disclosed deferred work and approved
time, all with unbroken names. Each page is read as the person it is for, asserts
no price on My work and no workspace-internal text or approval queue on the portal,
and is gated by the UI consistency and axe checks at every required width.

For the deferred backlog on the operator agreement page (#366), run
`node scripts/check-layout.mjs --deferred-backlog`. It seeds a separate
synthetic client with an unbroken name, an agreement whose title has no break,
and a shelf priced in the millions, and captures the agreement page at every
required width.

Run from a checkout without an active Vite dev server. The command refuses an
existing `public/hot` marker and leaves it untouched. It builds current assets,
creates a fresh synthetic SQLite database,
and launches Chromium against a temporary PHP server bound to `127.0.0.1` on a
free port. It does not use your `.env`, existing database, OAuth session or
production records. The test router is under `scripts/layout/`, outside the
application routes and public directory. It requires a random per-run header,
an exact loopback host, and GET requests except for the synthetic fixture's
schedule create/update/generate actions. Those bounded POST/PATCH requests exercise
real saves and draft generation only in the disposable database. Other writes
remain refused. The runner probes those refusals before opening a page; there is
no new application login endpoint. Browser requests to
other origins are blocked. SSR is disabled for this client-layout check.

The initial coverage is deliberately bounded:

- **Asserted:** the operator invoice detail page initially, with Record payment
  open, and with the payment-date correction control open; the operator proposal
  detail page and the eligible sent proposal in the client portal with its
  acceptance form visibly present. Fixtures include unbroken company names, invoice numbers and
  proposal titles, lengthy descriptions, and a partially paid invoice. The time sheet is
  also asserted initially, with approved time selected, and with the invoice review
  dialog showing both its priced lines and its entry fields. Its fixtures include
  unbroken descriptions/project names, flat-hourly cost pricing, and ineligible
  draft/deferred rows that must not become invoice checkboxes. The manager expense
  list includes a claimed invoice link, an approved expense awaiting a matching
  currency invoice, a draft awaiting approval, and oversized project/description
  text.
- **Measured only:** nothing is measurement-only now that the legacy
  Operations screen is retired. A green exit still does not certify any
  unlisted page.
- **Accessibility:** every capture in every group is also run through axe and the
  UI-consistency check in both themes, and the same pair of checks runs in the
  focused collaboration, attachment-sharing and task-organization scripts
  (`scripts/layout/a11y.mjs`).
- **Additional static coverage:** the proposal acceptance form and unbroken
  summary/terms text have hostile-fixture coverage in `layout-overflow.test.tsx`.

At each of 390, 820, 1440 and 1920 pixels, asserted pages must have no horizontal
**document** overflow and a 48-pixel navbar row whose visible direct children
share a vertical center. A horizontally scrolling table or tab strip inside its
own container remains allowed. Browser exceptions fail the run. The command
exits nonzero on failed assertions and writes measurements, screenshots and a
Markdown report beneath ignored `test-results/layout/`; its database and session
files are removed when the run finishes.

**Read every screenshot in the report before declaring the layout complete.**
The generated report explicitly leaves visual review as a manual step; numeric
checks cannot establish absence of overlapping or clipped content. Keep the
report with the PR's local verification evidence. It contains synthetic data,
but is generated output and should not be committed.

To extend coverage, add synthetic rows and named URLs in
`scripts/layout/seed.php`, then add the page and its relevant expanded states in
`scripts/check-layout.mjs`. Use real application routes, wait for visible controls
before capturing, and add the matching hostile-fixture case where practical.
Only an explicitly listed pre-existing screen should use measurement-only mode;
a new page under test must assert the invariants. Never add an application auth
bypass or point this harness at an existing database or remote URL.

The time sheet uses `/api/v1` for logging, editing, deleting, approval, withdrawal, and adding selected time to an existing draft. Each mutation uses the API client with an idempotency key and the rendered version where applicable, then reloads the page props. New invoice drafts still use the browser route. `pnpm run test:layout -- --time` checks the time sheet and draft selection at all four viewport widths.

Client settings' portal access uses `/api/v1` for giving, changing and removing a contact's access and for creating, resending and withdrawing invitations, through the same operations as REST, MCP and `svc:portal:project-access`. Each contact on the page is the API's own presentation with its version; changes send that version, `confirm: true` and an idempotency key, then reload the page props. Grants and invitation sends keep one key across retries of the same attempt, so a lost answer replays instead of acting twice, and a withdrawal whose provider half failed reloads to show it while keeping its key for the retry. A new contact version remounts its row, so edits never pair stale fields with a fresh version. Only this page receives an invitation's acceptance link. `node scripts/check-layout.mjs --portal-access` checks it at all four widths.
