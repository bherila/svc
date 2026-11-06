# Codex handoff: SVC UX overhaul, independent slices

Repository: `bherila/svc`. Read `AGENTS.md` first and follow it exactly: pnpm only, workspace-scoped queries with isolation tests, exhaustive `match` over billing enums with no `default` arm, no real client data in fixtures, focused local checks, and hosted CI for the full suites.

Context: `docs/ux-overhaul.md` on branch `claude/relaxed-einstein-qmprci` is the overall spec. A separate agent (Claude) is building the navigation, dashboard, approval model, shared projects and the new invoice document on that branch. **Do not edit** these, to avoid conflicts:

- `resources/js/pages/**`, `resources/js/layouts/**`
- `resources/views/invoices/show.blade.php`
- `app/Services/Billing/InvoiceDocumentService.php`
- anything under `app/Services/Navigation`
- the time-entry approval code (`TimeEntryMutationService`)

Each task below is its own branch off `origin/main` and its own PR. Do them in order; task 1 matters most.

---

## Task 1: golden tests for today's invoice document (tests only)

Branch: `codex/invoice-document-golden-tests`

Goal: before the invoice document is rewritten, pin down every figure the current document shows, so the rewrite can be checked against it. Change no production code.

1. Read `app/Services/Billing/InvoiceDocumentService.php`, `resources/views/invoices/show.blade.php`, `app/Support/Billing/InvoiceHoursStatement.php`, `app/Support/Billing/InvoiceHoursStatementRows.php`, `app/Support/Billing/InvoiceLineDetail.php`, and the existing tests in `tests/Feature/Billing/` (especially `DeferredWorkSnapshotLifecycleTest`, `DeferredBuyDownOnInvoiceTest`, `InvoiceLineDetailTest`).
2. Add a test helper that extracts structured facts from `InvoiceDocumentService::html()`, so the tests compare facts rather than markup:
   - each line: description, type label, quantity, unit, tax, total;
   - each hours-statement section title, with its rows (label → value);
   - the "Still deferred" entries;
   - appendix A entries per line;
   - the reissue notice;
   - the totals.
3. Write feature tests with synthetic fixtures (reserved domains, obvious test names) that cover each of these, for **both audiences** (`InvoiceLineDetail::OPERATOR` and `CLIENT`):
   - **Opening pool:** retainer, rollover, deficit and expired hours, and hours expired within the period.
   - **Work reconciled:**
     - ordinary hours applied to the work pool and to the next retainer;
     - hours billed at rate;
     - subcontractor hours;
     - deferred hours applied, with entry count;
     - re-carried settled hours;
     - deferred hours billed on termination.
   - **Catch-up billed at the hourly rate:** catch-up, minimum availability and its threshold, interim billing.
   - **Carried forward:** rolled forward, expiring, deficit carried forward, deferred backlog (hours and entries), re-carried remaining.
   - **Closing position:** next retainer hours.
   - **Pool position:** a pool sold on an earlier invoice (`retainerSoldBy`, pool remaining, available before).
   - **Correction variants:** each section above as it appears on a correction.
   - **"Still deferred":** shown to the client only when `show_deferred_backlog_to_client` is true, and always shown to the operator.
   - **Appendix A:** internal notes never appear in the client audience, and the generic-label notice appears when it applies.
   - **Reissue notice.**
   - **Line types:** at least one line of every `InvoiceLineType` case, with its printed label. Use a data provider built from `InvoiceLineType::cases()`, so a new case fails the test until someone adds a fixture for it.
4. Assert that the hours statement comes from the snapshot stored at issue. Change the ledger after issue (edit an earlier month's time), re-render, and assert the figures did not change.
5. Name the tests so they can be pointed at a second renderer later. For example, put the fact extraction behind an interface `InvoiceDocumentFacts` with one implementation for today's Blade HTML.

Local checks: run only the new test files and Pint on the changed files. Push and let hosted CI run the rest.

---

## Task 2: payment connections per workspace (Stripe Connect and Square OAuth)

Branch: `codex/workspace-payment-connections`

Today: `config/services.php` reads one deployment-wide `STRIPE_*` key set and one `SQUARE_*` key set, and `SQUARE_WORKSPACE` binds Square to a single workspace. That is a deployment-specific shortcut. Payment processing must be a replaceable adapter, and each workspace (a business) must get paid into its own account.

Design (agreed with the product owner):

- **Deployment level (environment file, unchanged role).** One Stripe Connect platform (client ID, platform secret, Connect webhook secret) and one Square application (application ID and secret, webhook signature key). Entered once.
- **Workspace level.** New table `workspace_payment_connections`, one row per connected processor account:
  - `workspace_id`;
  - `processor`: an enum, `stripe` or `square`;
  - `external_account_id`: Stripe `acct_…`, or the Square merchant ID;
  - encrypted OAuth tokens and expiry (Square), where the processor needs them;
  - `connected_by_user_id`, `connected_at`, `revoked_at`;
  - a display name.

  Each workspace has at most one active connection per processor.
- **Connecting.** "Connect Stripe" or "Connect Square" in workspace settings runs the processor's OAuth flow. Owners and admins only. Users never paste keys. A `state` value tied to the workspace and the user protects against CSRF.
- **Reusing a connection.** A workspace whose owner owns another workspace with an active connection can reuse it ("Use the Stripe account connected to <workspace>") without a second OAuth flow. Model this as connections that link to a shared account record, or as a second row pointing to the same `external_account_id`. Pick one, and document it in `docs/finance-api.md`.
- **Charging.** Stripe uses direct charges on the connected account (the `Stripe-Account` header). Square uses the connection's access token. Resolve the connection from the invoice's workspace, never from config.
- **Webhooks.** Route incoming events by connected account ID, and confirm the match with the invoice's workspace from payment metadata. An event that matches no active connection is logged and ignored, never applied to a guessed workspace.
- **Migration.** A command (not an automatic migration step) that turns the current environment credentials into the configured workspace's first connection. It runs once, by hand, on the deployment, and it never touches production from tests. The current environment-variable path keeps working until that command has run, then is removed in a follow-up.
- **Isolation tests.** Workspace A can never charge into, read, or receive webhooks for workspace B's connection. Revoking a connection stops charges immediately.
- **No queue worker runs in production.** Do the OAuth token exchange and any refresh in the request. Defer irrevocable external calls until after commit, explicitly, rather than checking `DB::transactionLevel()`.

Deliverables:

- the migration, model and repository;
- the OAuth controllers for both processors;
- a settings page section. Build it with the existing components (`WorkspaceShell`, `Select` with `items`), and keep it small: Claude will restyle it.
- adapter changes in `StripeGateway`, the Stripe payment-intent and webhook services, and the Square sync, so the connection is resolved per workspace;
- tests;
- documentation in `docs/finance-api.md` and `docs/square-status.md`.

---

## Task 3: one duration parser and formatter, plus a per-user setting

Branch: `codex/duration-format`

- `resources/js/lib/duration.ts`:
  - `parseDuration(input): number | null` returns whole minutes. It accepts `2:30`, `2.5`, `2.5h`, `2h30m`, `2h 30m`, `150m` and `150min`. It rejects negative and ambiguous input.
  - `formatDuration(minutes, style)` takes `style` = `'decimal' | 'clock'`. Decimal shows up to two places, with no trailing zeros (`2.5 h`, `2 h`, `2.25 h`). Clock shows `2:30`.
- A PHP twin in `app/Support/Time/Duration.php`, for PDFs and emails, with the same rules. Add a shared test-case table: one JSON file in `tests/Fixtures`, read by both the Vitest and the PHPUnit tests.
- A user preference, `duration_style`, defaulting to `decimal`. Add it to the users table and the user settings, and share it to the frontend through Inertia's shared props. Invoices always render decimal, whatever the preference.
- **Do not migrate the existing pages.** Claude switches call sites over as each page is rebuilt. Add an ESLint rule, or a test, that lists today's raw `minutes / 60` call sites as a baseline, so new ones fail.

---

When each task is done, open a PR against `main` that follows the repository's PR template. Then report back: the PR link, what local checks you ran, and anything you had to decide that the spec didn't cover.
