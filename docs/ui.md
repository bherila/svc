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

    ⏻ Workspace  [Client ▾]  Client Home  Invoices  Time  Tasks     ⌘K  ☾  ⚙

Read left to right it is a sentence about where the reader is: which tenant,
which client, then which part of that client — then, pushed to the far end, the
things that mean the same thing whatever the client is. An item whose meaning
changes when the switcher does belongs on the left; everything else belongs on
the right.

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
