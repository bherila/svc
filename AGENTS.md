# SVC Agent Instructions

SVC is a standalone Laravel 13 and React operations platform for independent service businesses: workspaces, clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows.

- Use hosted CI for heavy validation, including full PHPUnit suites, full PHPStan, whole-repository Pint, and mutation/coverage runs. Run these locally only when the user explicitly requests local execution.
- Keep local checks focused: short, relevant tests, formatting or linting touched files, and targeted browser verification. Push a focused candidate promptly so CI can run the expensive jobs.
- Require the hosted gates to pass for the exact candidate commit before merging. Inspect actual mutation artifacts when a green advisory job can conceal escaped mutations; do not weaken checks or deadlines to make a candidate pass.
- Keep tenant ownership explicit through workspaces and memberships. Every tenant-owned query and write must be workspace-scoped and covered by an isolation test.
- Prefer immutable DTOs for non-trivial domain operations and keep persistence at explicit tenant-scoped repository/DAO boundaries. Use bounded eager loads instead of query-per-rule behavior; after loading, keep calculations and classifications database-free where practical so their unit tests do not require a database.
- Treat OAuth, file storage, email, payment processing, and finance reconciliation as replaceable adapters. Bherila-specific behavior belongs in configuration or adapters, not domain models.
- Never copy production databases, client records, financial documents, credentials, or private fixtures into this repository.
- Never commit exports, uploads, invoices, payment processor records, or real client/billing test fixtures. Use generated synthetic data with reserved domains and obvious test identifiers.
- Do not write production data while developing product features. Migrations and tests target local disposable databases only.
- Load `database/schema/*.sql` only through Laravel (`php artisan migrate`, `migrate:fresh`, the test bootstrap). Laravel fires `SchemaLoaded`, and `App\Listeners\RestoreRowsMigrationsWrote` puts back the rows a dump cannot carry; piping a dump into `mysql` or `sqlite3` skips that and leaves the credential revocation tables refusing every token. When a migration writes rows an empty database needs, add it to that listener; `SchemaDumpMatchesMigrationsTest` fails until you do.
- Run `pnpm run hooks:install` once per clone so staged changes receive the same disclosure scan enforced by CI.
- Use `pnpm`, never `npm ci`. Run focused PHP and frontend checks locally during iteration, and use hosted CI for the full repository checks before publication.
- **Claude Code cloud sessions:** the `SessionStart` hook in `.claude/settings.json` first runs `scripts/cloud/vendor-bundle.sh install`, then `cloud-repo-bootstrap` (installed by the cloud environment's setup script). `vendor-bundle.sh` installs `vendor/` from this repo's prebuilt bundle for the current `composer.lock` (`.github/workflows/cloud-vendor.yml` publishes one per lock to the `cloud-vendor` draft release, from `main` only), and refuses it unless its sha256 matches an immutable Actions artifact from a successful run of that workflow on `main` binding the repository, workflow, ref, commits, lock and bundle name. It stamps `vendor/` so `cloud-repo-bootstrap` sees it already matches the lock; `cloud-repo-bootstrap` then seeds `.env`, sets `APP_KEY`, runs pnpm and prints a `[cloud-repo-bootstrap]` status report. Do not take a clean report as proof that `vendor/` matches `composer.lock`: when a PHP result is surprising, run `scripts/cloud/vendor-bundle.sh verify`, and `scripts/cloud/vendor-bundle.sh install --force` to re-fetch a damaged tree. In the cloud never run `composer install`/`update` against the network and never call `add_repo` for a dependency repository: the cloud GitHub proxy refuses GitHub archive downloads for repositories not attached to the session, and each `add_repo` is a manual permission grant. Only `main` publishes bundles: if `install` finds no bundle for this lock, land the lock on `main`, wait for **Cloud vendor bundle**, and rerun `scripts/cloud/vendor-bundle.sh install --force`; branch runs build read-only and publish nothing. On a `[vendor-bundle]` `REFUSED`, do not set `CLOUD_VENDOR_ALLOW_UNTRUSTED=1` without the user's say-so. Keep the `cloud-vendor` release a draft: publishing it would create a tag and fire release workflows.
- After focused local checks and disclosure scans are green, push the candidate head promptly so hosted Actions run the full suites, static analysis, formatting and mutation jobs in parallel. Never merge until the required focused local checks and hosted gates both pass for that exact head.
- Keep independent GitHub Actions jobs concurrent; do not serialize lanes that can safely run in parallel. Both database lanes use four ParaTest workers. Treat the 1 GiB needed for serial testing as a floor, not a parallel-run budget: measure aggregate worker memory and per-process MariaDB schema overhead before increasing concurrency, and preserve enough headroom for the measured peak.
- Before re-requesting review for P0/P1 fixes, fetch `origin/main` and require a green hosted `composer test:mutation-diff` result for the candidate. Escaped mutants need either the missing assertion or an `@infection-ignore-all` reason comment. Fetching does not require a rebase merely to request review.
- Render every authenticated screen through `WorkspaceShell`, and draw the navbar row and the page's `<main>` in the same `SHELL_CONTAINER`. A page supplies content only: no header, theme control, client picker or back-link of its own, and no `max-w-*` on `<main>` - a page that centres its own column while the bar spans the viewport lines up with nothing.
- Give every single-column `grid` an explicit `grid-cols-1`, and give every unbreakable run - identifiers, URLs, names with no spaces - somewhere to go (`truncate`, `wrap-anywhere`, or a container of its own that scrolls). A bare `grid` column is sized by the widest thing inside it, so one long string stretches the whole page sideways. Check a page against deliberately oversized fixtures with `horizontalOverflowRisks`; jsdom cannot measure a width, so short fixtures prove nothing about layout.
- `TableCell` does not wrap. Mark the one prose column per table `max-w-0 whitespace-normal wrap-anywhere` with a `min-w-*` header and leave dates, amounts and statuses alone; an unmarked description column pushes every column right of it off the screen. Never `truncate` content a reader came to read - truncation is for an identity string in fixed chrome, where the full value is one click away.
- Send finished URLs and capabilities from the server, never ids for the browser to assemble or booleans for it to interpret: a module the viewer's route family does not serve arrives as `null` and its tab is not rendered. Print stored enums through `statusLabel`, never straight from the column.
- Give every `Select` an `items` list as well as its `SelectItem`s: Base UI renders the raw value in the trigger otherwise, so a control shows a stored enum or a UUID to a reader while the markup, the value and the submit are all correct.
- Verify a layout in a browser before calling it done - at 390, 820, 1440 and 1920, asserting no horizontal document overflow and a navbar still one row high - and read the screenshots for overlap and clipping, which are visible and not assertable. See [the interface guide](docs/ui.md).
- Nothing on the deployment runs a queue worker, and `QUEUE_CONNECTION=sync` under PHPUnit hides that: a dispatched job runs inline in the suite and is written to `jobs` and never read in production. Work that must actually happen happens in the request. Where an act is irrevocable and the caller is inside a transaction, defer it explicitly to commit rather than testing `DB::transactionLevel()` - `RefreshDatabase` opens a transaction around every test, so an ambient check takes a path production never takes.
- Prefer one reviewable PR with stacked commits for a coherent slice of work.
- Keep `match` expressions over billing enums exhaustive: enumerate every case and never add a `default` arm. Adding an enum case must make every undecided billing path fail static analysis instead of silently inheriting fallback behavior.

## Security invariants

CI enforces these (ported from x-template). Each census prints the fix in its failure message, every allowlist entry needs a reason a reviewer can check, and an allowlist or baseline entry that no longer matches anything fails the census.

- **Every write is throttled, in its own bucket.** No numeric throttle without a prefix (`throttle:N,M`, a bare `throttle`, `ThrottleOuterAgentRequests:N,M`): it keys on the person alone, and an agent principal's id is its user's id, so it shares one bucket with every other such route, the agent API included. Every non-GET/HEAD/OPTIONS route, and every export, PDF or pro forma route, carries a named limiter from `App\Support\Http\RateLimits` (an ordinary browser write takes `web-writes`) or a prefixed one; the agent API uses `RateLimits::AGENT_API_THROTTLE`, which REST and MCP share on purpose. Routes that send mail, call Stripe or Square, fan out notifications or render documents get a dedicated limiter (`RateLimits::COSTLY`); reviewers must check this, because the census sees only middleware. Signature-verified processor webhooks and vendor routes are allowlisted with reasons. Enforced by `tests/Feature/Security/RouteThrottleCensusTest.php`.
- **The MCP endpoint caps JSON-RPC batch size.** Every MCP route that accepts a POST carries `LimitMcpBatchSize` (asserted by the route census; `AgentMcpReadOnlyTest` proves the refusal). The HTTP throttle counts a POST once however many messages it holds.
- **Input is bounded.** Every `array`/`list` rule has `max:` (or `size:`/`between:`, or a key list `array:a,b`), every date has `after`/`before`, and every year or month key has a range. Cap the span of a from/to window in code. Open-ended dates that predate the census are frozen in `tests/Fixtures/Security/validation-date-baseline.json`, which may only shrink: never add a new finding to it. Enforced by `tests/Unit/Security/ValidationBoundsCensusTest.php`.
- **Only `main` ancestry reaches deploy credentials.** A job with an `environment:`, deploy-class secrets or write-class token permissions must run only on push to `main`, gate on `github.ref == 'refs/heads/main'`, or fail closed on `git merge-base --is-ancestor`, and must not check out another ref - except the verified SHA a gate listed in the census's `MAIN_ANCESTRY_GATES` writes (`scripts/deploy/staging/gate.mjs`). Enforced by `tests/Unit/Security/WorkflowCredentialCensusTest.php`. Also restrict the `web1`, `pilot` and `staging` Environments' deployment branches to `main` in the repository settings; the census cannot see a branch's edited workflow.
- **Authorize on the relationship, not a role flag.** Policies and visibility scopes check how the user is linked to the record (workspace membership and role, project membership, grantee, billing contact), never a bare role boolean. There is no census for this yet, so it is a review rule.
- **When you fix a security bug class**, check the sibling Laravel repositories on this stack (x-template and the applications built like SVC) and the shared packages (`bherila/auth-laravel`, `bherila/mcp-laravel-bridge`) for the same class, and fix it or file it there. Port the census when one exists.
- **Trust assumptions** the code relies on but does not verify belong in [SECURITY.md](SECURITY.md).

## Billing review checklist

Reviews of #655 found the same shapes repeatedly. Check every billing change for them, and keep each guard green rather than weakening it:

- **A digest or fingerprint missing a column a state change moves.** A new column on `client_time_entries` must be decided in `tests/Feature/Billing/IssueDigestCoverageTest.php`.
- **A write derived from an ordinary read after a locking check.** Under REPEATABLE READ that read returns the caller's snapshot, which can predate what was checked (the API's mutation executor reads first). Read with a lock or refuse an older snapshot; the ratchet in `LockOrderConformanceTest` (`tests/Fixtures/Concurrency/snapshot-reads.txt`) fails on a new site.
- **A stale draft that issues, a rebuild that misses a dependent draft, a bulk walk priced from a stale ledger, an agreement billing past its segment, a final reconciliation leaving its own deferred work.** `tests/Feature/Billing/BillingHistoryInvariantTest.php` asserts these as invariants over scripted histories; add a history when a change creates a new shape.
- **Monthly and non-monthly paths diverging.** A fix on one cadence path needs the same case on the other.
- **An ordering nobody wrote down.** `tests/Feature/Billing/BillingHistorySequenceTest.php` generates histories across the agreement shapes. A new billing operation or line type belongs in its generator; widen a run with `BILLING_HISTORY_SEEDS`.

## Codex review workflow

After the initial review, let finding severity determine whether to run another
Codex review:

- **P0/P1 findings:** fix confirmed P0/P1 issues on the same PR branch, push the
  updated candidate, then re-request Codex review. While making those fixes, you
  may opportunistically fix related P2+ findings. Repeat the fix/push/review cycle
  only while P0/P1 findings remain.
- **Only P2+ findings:** do not start another fix/push/review cycle solely for
  these findings. Defer them to follow-up work; do not request another review
  merely because the PR head changed.
- **Remaining P2 findings:** create or update one consolidated follow-up issue
  for the PR. Start its title with `P2:` and explicitly state `Priority: P2` in
  the body. Include links to the PR and each finding, the impact, and acceptance
  criteria. Link the issue from the PR and affected review threads. Any P3+
  notes included in that issue must retain their actual priority.
- **Finding disposition:** inspect issue comments, review submissions and inline
  comments. Reply with the fixing commit or the follow-up issue and deferral
  rationale; resolve affected threads after that disposition is recorded.
- **Merge gate:** require successful CI for the exact candidate commit and no
  merge conflicts. This severity policy does not waive required checks or allow
  unresolved P0/P1 issues to merge.
