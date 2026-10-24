# Client Management CLI

Application commands use `svc:<area>:<operation>`. Run `php artisan list svc` or
`php artisan help <command>` for the current argument list. Commands live under
`app/Console/Commands/<Area>`; the old `engagement:log-time`, `svc:log-time` and
`billing:apply-payment` aliases remain available.

## Write convention

**Preview is the default. Pass `--apply` to write.** This includes payment
recording and corrections, refunds, finance reconciliation, logging time,
portal access, credential issuance and revocation, maintenance and delivery.
`--confirm` and `--expected-version` still apply wherever the operation requires
them; `--apply` does not replace those checks.

`--dry-run` and `--pretend` remain deprecated preview aliases through the next
CLI compatibility window. Combining either with `--apply` exits `2` before the
operation runs. Storage status also keeps `--write` as a deprecated alias for
`--apply`. Deprecation notices go to stderr in a terminal; JSON captured through
`Artisan::call` omits notices so its single output buffer stays parseable.

Database-only operations (time, payments, finance allocations and portal scope)
preview the same operation inside a transaction that is always rolled back.
Payment and time results include `dry_run`; IDs from a preview do not identify
persisted new records. A preview can still refuse invalid or stale input.

Credential commands, email delivery, Square sync/reconciliation and receipt
pruning report intent without invoking their effectful operation. Their preview
JSON is `{ "dry_run": true, "operation": "svc:…", "executed": false }`.
This is not a certification that a future applied run will succeed. OAuth client
pruning and attachment repair use their existing native preview paths; payout
backfill retains its existing read-only provider lookups. The scheduler and
deployment smoke explicitly pass `--apply`; the vendor Passport and auth
maintenance commands retain their own contracts.

## Workspaces and output

Workspace arguments/options take a **public UUID**, resolved by one shared
lookup. Log-time and payout backfill still accept a workspace slug with a
deprecation notice. Other commands do not interpret internal numeric IDs or
slugs as workspace public IDs. An explicitly empty `--workspace` never widens a
scoped command into all workspaces. Square commands use the workspace bound in
server configuration through `SquareConfiguration::workspace()`.

Every application command accepts `--format=text|json`; other values exit `2`
before the operation runs. Audit JSON shapes and exit codes are preserved.
Replay and rehearsal now emit a single `summary` object without progress text;
early refusals produce an `error` object and the same failing exit code.
Rehearsal `--show --format=json` adds a `details` array with the same private
billing detail as text output. OAuth prune wraps the vendor's report text in
`{ "dry_run": true|false, "exit_code": number, "report": string }`.
Existing credential commands that already emit JSON by default keep doing so.

## Tasks

`svc:tasks list|get|create|update WORKSPACE_UUID --actor=USER_UUID` uses the same
workspace-scoped reads, task lifecycle, calendar dates, assignee eligibility,
versions and retry receipts as REST/MCP. Use `--project=PROJECT_UUID` to create or
filter a list, and `--task=TASK_UUID` to read or update one task. List accepts
`--limit=1..100` and `--cursor`.

Task pages also respect a serialized byte budget, so a short page can still have
more rows. Follow `meta.next_cursor` until it is null. REST and MCP project
details embed at most 25 tasks within the whole project response budget; when
`tasks:read` is held, follow `tasks_next_href` or pass its `tasks_cursor` to
`projects.get` to continue. Project-only tokens receive the base project without
task data or a continuation.

```bash
php artisan svc:tasks list WORKSPACE_UUID --actor=USER_UUID --format=json
php artisan svc:tasks create WORKSPACE_UUID --actor=USER_UUID --project=PROJECT_UUID --input=task.json --key=unique-retry-key --format=json
```

Mutations preview and roll back unless `--apply` is supplied. `--input` accepts a
JSON object in a file or `-` for standard input, at most 1 MiB. Updates require the
current opaque `expected_version`. Omitted fields remain unchanged; explicit
`null` clears a date, and an empty `assignee_ids` array clears assignments.
Assignments do not grant project access. Local commands require current account
permissions independently of OAuth deployment switches. Reusing an applied
request and key replays the existing operation; stale versions and revoked
permissions are refused.

Task commands use current internal staff authority. An account that also has
portal access can use its staff permissions locally; REST/MCP treat that account
as a portal reader and refuse task mutations.

## Business contacts

Company and business-contact listings apply both a maximum row count and a
serialized byte budget. Large profiles/notes can produce shorter pages in REST,
MCP and CLI. Continue with `meta.next_cursor` until it is null; a short page does
not imply completion. Full notes remain available on every returned record.

`svc:clients:contacts` lists, reads, creates, updates, archives and restores
business contacts. The acting `--actor` must currently be an owner or admin of
the workspace. Workspace, company and contact identifiers are public UUIDs.
These records describe people at a client company; editing them does not create
login accounts, send invitations or change portal access.

```bash
php artisan svc:clients:contacts list WORKSPACE_UUID COMPANY_UUID --actor=USER_UUID --format=json
php artisan svc:clients:contacts get WORKSPACE_UUID COMPANY_UUID --actor=USER_UUID --contact=CONTACT_UUID --format=json
php artisan svc:clients:contacts create WORKSPACE_UUID COMPANY_UUID --actor=USER_UUID --input=contact.json --key=unique-retry-key --format=json
```

Mutations preview and roll back by default. Add `--apply` to persist. `--input=-`
reads a JSON object from standard input; files and standard input accept at most
1 MiB. Use the same body as `clients.contacts.create`, `update`, `archive` or
`restore` in REST/MCP. Updates merge only the fields supplied, and explicit
`null` clears an optional field. At least one name must remain. Update, archive
and restore require the current opaque `expected_version` returned by reads;
archive additionally requires `"confirm": true`. Keep the request and `--key`
unchanged when retrying an applied operation. List accepts `--status=active` or
`archived`, `--limit=1..100` and a continuation `--cursor` bound to that company
and status. Local commands use current internal account permissions independently of
OAuth deployment switches. REST/MCP business-contact operations treat any current
portal identity in the workspace as the client audience, including dual-role
accounts, and refuse those private reads and writes. Native CLI authority remains
internal and is rechecked on each write and replay.

## Apply Payment

Amounts are integer currency minor units, as in REST. The workspace is required;
the invoice must belong to it. Recording uses the shared `RecordReceivedPayment`
operation and accepts `succeeded` or `pending`. Later status changes and refunds
use their own commands. A full payment updates the invoice through the same
billing rules as the API.

```bash
php artisan svc:billing:payment INVOICE_PUBLIC_UUID 25000 USD wire --workspace=WORKSPACE_PUBLIC_UUID --format=json
php artisan svc:billing:payment INVOICE_PUBLIC_UUID 25000 USD wire --workspace=WORKSPACE_PUBLIC_UUID --apply
php artisan svc:billing:correct-payment PAYMENT_PUBLIC_UUID --workspace=WORKSPACE_PUBLIC_UUID --reference=SYNTHETIC-001 --reason="Synthetic correction" --apply
```

## Log Time

The console twin of `time_entries.log`. It validates with the same rules and
creates through the same `TimeEntryMutationService::create()` as REST, MCP and
the time sheet, so it accepts and refuses exactly what they do
(`TimeEntryCreationParityTest` runs every case through all four).

```bash
php artisan svc:engagement:log-time <workspace> <project> 45 "Synthetic work" --user=worker@example.test --apply
php artisan svc:engagement:log-time <workspace> <project> 90 "Synthetic work" --user=7 --no-billable \
  --worked-on=2026-10-01 --client-description="Release preparation" --format=json --apply
```

`<workspace>` is a public UUID (legacy slugs are deprecated) and `<project>` a public id or slugified
name. The worker named by `--user` must hold a project role that may log time
there. Only someone who may approve the time may pass `--rate`: a project owner
or manager, or a workspace owner or admin. The currency defaults to the
workspace's. `--worked-on` defaults to today on the workspace calendar and
must fall inside the time sheet's window. Time is billable unless `--no-billable`
is given, as an API entry without `is_billable` is. Client-facing text makes the entry
client-visible unless `--internal` is given. It exits `0` when the entry is
logged or successfully previewed, `2` when the input is refused (the API's 422) and `1` when the
workspace, project, task or worker is not found or may not log the time (the
API's 404 and 403). `engagement:log-time` and `svc:log-time` remain aliases.

## Replay — the billing regression gate

`svc:billing:replay` is kept on purpose, as the regression gate for the billing
engine. It regenerates a workspace's invoice history inside one transaction that
is always rolled back and compares every regenerated line and total with what
was billed. Money is exact: an unexplained difference of one minor unit fails
the run. Hours differences are reported for a human read and do not fail it.

```bash
php artisan svc:billing:replay --workspace=<workspace public id>
php artisan svc:billing:replay --workspace=<workspace public id> --company=<company public id> --as-of=2026-09-30
php artisan svc:billing:replay --workspace=<workspace public id> --report=storage/app/replay-report.json
```

Run it:

- before merging any change to invoice generation, the ledger, rate resolution,
  rollover or overage arithmetic;
- after an import, before the imported history is relied on;
- before and after an engine fix for a divergence it reported, to show the
  divergence gone and nothing else moved.

It never writes, so it is safe against real data, but it holds one long
transaction across periods: do not run it while the workspace is generating
invoices (see [concurrency.md](concurrency.md#the-known-inversions)). Stdout is
aggregate; `--report` writes per-invoice detail with keyed description digests,
which stays on the host and is never pasted into an issue. Do not add a
`--commit` option: if a divergence needs fixing, fix the engine and replay again.

## Portal Project Access — break-glass

`svc:portal:project-access` narrows a portal user to named projects, or returns
them to company-wide access, through the same `PortalAccessOperation` as the
client's settings page, REST and MCP (`clients.portal_access.update`). It is
kept as a break-glass tool by owner decision (#460): use the settings page day to
day, and the command when no workspace manager can reach it.

```bash
php artisan svc:portal:project-access <company public id> person@example.test --show
php artisan svc:portal:project-access <company public id> person@example.test \
  --project=<project public id> --expected-version=<version from --show> --confirm --apply
php artisan svc:portal:project-access <company public id> person@example.test --company-wide \
  --expected-version=<version from --show> --confirm --apply
```

## Rehearse Generation

Runs `generateAllInvoices()` for every client of a workspace inside a transaction that is always rolled back, and fails if any issued, paid or void invoice would change. Nothing is written.

```bash
php artisan svc:billing:rehearse-generation --workspace=<workspace public id>
php artisan svc:billing:rehearse-generation --workspace=<workspace public id> --company=<company public id> --show
```

`--company` narrows the run to one client of that workspace (a company of another workspace is refused). `--show` prints, before the rollback, every draft the run would create or refresh: number, kind, issue date, work period, the cycle it sells and totals, then each line with its hours, amount, the minutes of time it links and the dates that time was worked (and how much of it is deferred). For each agreement it adds the capacity ledger for the last twelve months (charged invoices only, so this run's drafts are not yet in it — it is what they were sized against, together with the catch-up of each earlier draft, which generation overlays as though issued; each draft records those earlier charges as its `catch_up_basis`, and issuing it refuses once any has moved, so a draft sized against an earlier month that has since been regenerated must be refreshed by a real run before it is issued — see [the minimum availability rule](billing.md#minimum-availability-rule-catch-up-billing)), flags any charged cadence invoice whose recorded `hours_billed_at_rate` disagrees with the additional-hours lines that figure represents — every one except those linking deferred work, which termination bills at rate without touching the figure (`RecordedOverage`; the ledger reads only the recorded figure), and totals the deferred work still carried forward. `--show` prints client billing detail: read it on the host, do not paste it into an issue.

A company with no agreement in force, ended, or starting within the month is listed as skipped, not failed.

## Generate Cadence Drafts

Creates the draft for every active recurring agreement whose current cadence period has started with no non-void invoice — the periods `billing_audit.stale_and_missing` reports missing — through the same guard and engine as `invoices.generate_period`. It only creates drafts: nothing is issued or sent. Agreements with an active billing schedule are skipped, because their schedule issues their invoices, and older gaps are left for an operator to look at.

```bash
php artisan svc:billing:generate-cadence-drafts                      # list what it would create
php artisan svc:billing:generate-cadence-drafts --apply              # create the drafts
php artisan svc:billing:generate-cadence-drafts --workspace=<workspace public id> --limit=10 --apply
```

Each period commits on its own. A refusal is logged, reported with its reason, retried on the next run, and makes the command exit non-zero. The scheduler runs it hourly with `--apply` once `SVC_BILLING_SCHEDULED_CADENCE_DRAFTS=true` (`svc.billing.scheduled_cadence_drafts`). Running it by hand works whether or not the flag is on.

## Retired client-management commands

The old `client-management:*` commands documented here no longer exist. Use the
current `svc:*` commands and API for those operations. Legacy cadence migration
and the old invoice-email-status command have no active CLI alias.

## Regenerate the Null-Semantics Manifests

Developer tooling, not an operational command. Rebuilds the two pinned constants
in `NullSemanticsRegistryTest` from the registry itself.

```bash
composer registry:manifest          # report drift; writes nothing, exits 1 if stale
php scripts/registry-manifest.php --apply
```

The registry ratchets on identity rather than on counts, so `REGISTERED_BRANCHES`
names every branch that may not be lost and `PENDING_COLUMNS` names every column
with no known reader, both compared as exact sets. Adding a branch therefore
means editing a constant in the same commit — which is the point, and also a
guaranteed conflict as soon as two branches add one. Hand-resolving a sorted
70-line constant is the friction that gets a guard deleted rather than fixed;
with this, resolving is _take both sides, re-run, commit_.

It cannot weaken anything by itself: it derives the manifests from `REGISTRY`, so
running it after a branch is deleted produces a manifest that agrees with the
deletion. It resolves conflicts, it does not approve them — read the diff. The
guard test remains the gate.

## Audit Opening Rollover — deprecated

**Deprecated (#460); removal is tracked in #500.** The audit sized the opening-rollover repair (#134), and that repair is done, so the count now describes capacity agreements already receive rather than a pending change. The command, its `billing.audit_opening_rollover` MCP tool and `GET /workspaces/{workspace}/billing-audits/opening-rollover` stay through a deprecation window; nothing replaces them. Text output opens with the deprecation notice. The JSON output is unchanged.

Read-only. Counts the agreements whose ledger takes the opening-rollover seed in `InvoiceLedgerBuilder` (#134).

```bash
php artisan svc:billing:audit-opening-rollover                 # counts
php artisan svc:billing:audit-opening-rollover --format=json   # machine-readable
```

Three conditions have to hold together, and each alone overstates the population: the agreement carries an initial rollover, it takes the legacy monthly branch (an agreement with period retainer terms returns before the seed and never reaches it), and it has a rollover policy (with none, the seeded capacity expires in the month it is granted and no invoice sees it).

It reports counts and aggregate minutes only — never a row, an id, a name, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It deliberately does not report the change to any particular invoice: that depends on how much of each month's capacity was actually used, which cannot be read off the agreement. Capacity at stake is the ceiling on what the repair can move. It always exits zero; it is a number to read, not a gate.

## Audit Undated Agreements — retired

`svc:billing:audit-undated-agreements` was removed when #147 closed.

It existed to size a population before a decision: `client_agreements.starts_on`
was nullable and the code had at least seven answers for what a null meant, two
of them in direct conflict — `AgreementBillingRateResolver::resolve()` read a
null as **in force** while `TimeSheetController::capacityByMonth` and every
date-based selector read it as excluded, so an agreement could stamp its hourly
rate onto approved time while contributing no capacity and being invisible to
billing selection.

The audit ran against both databases and both came back empty: 0 undated of 9 in
production, 0 of 9 in the source. With the population known to be zero the
contract could be chosen on its merits, and the one chosen removes the state
rather than assigning it a meaning — `starts_on` is now `NOT NULL`. A command
that counts a condition the schema forbids reports zero forever and tells an
operator nothing, so it went with the ambiguity it was built to measure.

What replaced it is the migration itself. `require_an_agreement_start_date`
counts the undated rows before altering anything and refuses with that count
rather than backfilling a date, because every candidate default rewrites what an
agreement billed. If it refuses, the rows are found with:

```sql
SELECT id, status, billing_cadence FROM client_agreements WHERE starts_on IS NULL;
```

Set each from the agreement itself and migrate again.

## Audit Undated Collectible Invoices

Read-only. Counts collectible invoices with no `due_date` — rows that appear in
collectible balances and in no overdue figure (#149).

```bash
php artisan svc:billing:audit-undated-collectible-invoices                 # counts
php artisan svc:billing:audit-undated-collectible-invoices --format=json   # machine-readable
```

`AgentReadController::summary()` builds the collectible set and then narrows it
with `whereDate('due_date', '<', ...)`. SQL answers false for a null rather than
unknown, so an undated invoice stays in `collectible_balances` — which does not
filter on that column — and vanishes from `overdue_count` and `overdue_balances`.
The two figures disagree and nothing says why.

The null survives because `InvoiceLifecycleService::issue()` defaults a null due
date to the issue date, but **returns early for an invoice that is already
charged** — so an imported issued or paid invoice never passes through that
transition and keeps its null permanently.

**Do not fix this with `orWhereNull`.** That was right for #135, which was
fail-closed against charging a client twice. Here it would move invoices into a
collections-adjacent report on no evidence: an invoice with no stated term is not
self-evidently late, and reclassifying it silently is a different wrong answer
rather than a safer one. The command says so in its own output.

The population is split by whether an `issue_date` exists, because that split is
the size of what the preferred repair can reach — backfilling to the issue date is
exactly what `issue()` would have done. `would_become_overdue_if_backfilled` and
its balances say how much that repair moves into overdue reporting on the day it
runs, which is what an operator approving it needs to see first. Rows with no
issue date either cannot be dated honestly at all, and are the population a
separate `undated_collectible` bucket exists to report rather than absorb.

Balances are reported per currency, never summed across them. It reports counts
and balances only, so it is safe to run against real billing data and to paste
into an issue. It always exits zero.

## Backfill Invoice Due Dates — retired

`svc:billing:backfill-invoice-due-dates` and `UndatedCollectibleInvoiceRepairer`
were removed by #460. They dated the imported collectible invoices #149 found;
that repair is done, and `InvoiceLifecycleService::issue()` dates every invoice
it issues. The read-only audit above stays: run it after an import, and date
any row it reports from the invoice itself.

## Derive Time Rates — retired

`svc:billing:derive-time-rates` was removed by #460. It priced time imported by
the importer that #196 removed, so it had no rows left to reach. Approval
resolves an entry's agreement rate through `AgreementBillingRateResolver`, which
`AgreementBillingRateResolverTest` pins.

## Audit Missing Billed Overage

Read-only. Counts charged invoices carrying no `hours_billed_at_rate` at all, and
the agreements whose already-billed sums may therefore read short (#144).

```bash
php artisan svc:billing:audit-missing-billed-overage                 # counts
php artisan svc:billing:audit-missing-billed-overage --format=json   # machine-readable
```

Three sums total the overage an agreement has already been charged so the next
period does not charge it again. All three are `SUM(hours_billed_at_rate)`, and
SQL aggregation contributes nothing for a null — so a charged invoice with a
null there reads as _zero already billed_, and its hours can be sold a second
time.

Same defect class as #135 by a different route: there a `<=` answered false for
a null and the row left the window; here the row is inside the window and the
value it contributes vanishes.

**No fix is implied, and none should be inferred from the count.**
`service_period_end` could be read fail-closed because the question was which
side of a window a row falls on. This one cannot: the question is _how much_ was
billed, and a null is not a quantity. Coercing it to zero is the current
behaviour and is the bug; coercing it to anything else invents a number. The
decision is to refuse on unknown, or to establish the column is never null on a
charged invoice and make it `NOT NULL` for that status.

The agreement count is the one that sizes the exposure, because the sums are per
agreement: ten such invoices on one agreement leave one figure unknown, one on
each of ten leaves ten.

It says _may_ throughout, deliberately. A null proves the contribution is
unknown — not that the invoice carried overage — so a flagged sum may read
short and may be exactly right. This audit exists to establish whether #144 is
live at all, and claiming more than counts can support is how an audit stops
being believed.

It reports counts only — never a row, an id, an invoice number, a company or a
workspace — so it is safe to run against real billing data and to paste into an
issue. It always exits zero.

## Audit Unplaceable Invoices

Read-only. Counts invoices whose period or cycle cannot be placed on a calendar, and how much billed overage they carry.

```bash
php artisan svc:billing:audit-unplaceable-invoices                 # counts
php artisan svc:billing:audit-unplaceable-invoices --format=json   # machine-readable
```

The column is nullable and stays that way (#73): an invoice can be created by hand without a service period, and the external importer passes the source value through unchanged. Everything downstream, though, decides which period an invoice belongs to by comparing that column, and SQL comparison answers false for a null rather than unknown — so an unplaceable invoice is silently treated as outside whatever window is being asked about.

`BilledOverageLedger` is where that costs money. Its chronological read is fail-closed: a positive charge with no service-period end cannot be placed into an expiring month, so pricing stops instead of dropping or guessing it (#135). The interim generator's parallel already-billed read (`InterimOverageGenerator`) carries its own fail-closed guard. Run this audit after an import and after any bulk invoice edit, and give the rows it names a real period.

Four conditions have to hold together, and each alone overstates: the invoice has no service period, it is charged (a draft has charged nobody), it names an agreement that exists in its own workspace (the sum filters on both keys, and the agreement column is unconstrained lineage that can dangle or cross tenants), and it carries nonzero overage hours (zero contributes nothing whichever side of the window it lands on, while negative hours move the sum too, so the hours at stake are reported as a magnitude).

### The cycle columns

`cycle_start` and `cycle_end` are nullable for the same reasons and drop rows out of the same kind of predicate (#141), so the command reports them too — in two counts, because they endanger two different things. `InterimOverageGenerator::cycleInvoices()` matches on both columns, so a row missing either is invisible to every caller: the **charged** count is the money one, feeding the already-billed subtraction and `interimOverageHoursForCycle()`, where a dropped row is charged a second time; the **live** count is the guard one, since the duplicate checks that refuse to create a second invoice for a cycle read live and settled statuses, and a row they cannot see costs a whole invoice rather than a wrong number.

Kind is applied before either, exactly as those lookups apply it — an ad-hoc or terminal invoice is excluded by kind before its cycle columns are read at all, so a null there is inert. A **null** kind is counted, deliberately: a migrated invoice carries none, and the cadence resell guard reads it on purpose for that reason.

**No fix is implied for the cycle columns, and none should be inferred from the count.** A null service period can be read fail-closed because the question is which side of a window the row falls on. A null cycle cannot: the question is which single cycle the row belongs to, and counting it in every cycle would under-charge repeatedly rather than repair anything. Those rows need a real value, which is what this command exists to find.

It reports counts and aggregate hours only — never a row, an id, an invoice number, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It always exits zero; it is a prompt to correct rows, not a gate.

### Agreement handover coverage

`svc:billing:audit-handovers --workspace=<workspace-public-id> --format=json`
reports issued invoice coverage overlapping a successor, with at most 100 invoice
identifiers. Omit `--workspace` for an operator report across all workspaces.
It writes nothing and exits 0 when the report completes (2 for an unknown workspace).
Review the original pricing and proration before correcting an invoice.
`svc:billing:preflight-schedule-generation` also reports due schedule handover
conflicts and exits 1 until they are resolved, using the generator's policy.

### Deferred work settlement

`svc:billing:deferred-buydown <workspace-public-id> <agreement-public-id>
--user=<manager-public-id> --hours=4 --format=json` previews the same selection
as REST/MCP. Use `--amount=<gross-minor-units>` instead of hours for a money target.
`--round-up`, `--discount-amount=<minor-units>` and `--discount-reason=<text>` are
optional. It writes nothing by default. To reserve the shown selection as a
draft, repeat with `--apply --expected-backlog-version=<backlog_version>
--idempotency-key=<key>`. Reusing the exact request and key returns the same draft.
Refused input/versions or missing write arguments exit 2; a successful preview or
creation exits 0. Issue and send remain separate operations.

## Read signed processor movements

`svc:finance:processor-movements` is the read-only console twin of
`finance.processor_movements.list`, using the same workspace-scoped service as
REST and MCP. The acting `--user` must be a workspace owner or admin. It needs no
`--apply`: it performs no provider HTTP or database writes.

```bash
php artisan svc:finance:processor-movements --workspace=WORKSPACE_PUBLIC_UUID \
  --user=USER_PUBLIC_UUID --provider=stripe --kind=refund --per-page=50 --format=json
```

The JSON envelope is `{data, meta: {next_cursor, watermark}}`. Pass `--cursor` to
continue, retaining the original immutable filters; once complete, pass the
watermark as `--updated-since` for the next inclusive pass. Optional filters are
`--provider`, `--kind`, `--payment-id`, `--occurred-from` and `--occurred-to`.
`--watermark` can fix an earlier snapshot. There is no numbered page or mutable
review-status filter. See the [signed movement contract](../finance-api.md#signed-processor-movements)
for money signs, public fields and deduplication.

## Acknowledge a finance allocation review

`svc:finance:acknowledge-reconciliation PAYMENT SYSTEM TRANSACTION` is the
console twin of `finance.reconciliations.acknowledge`. Supply `--workspace`,
`--user` (owner/admin), `--expected-payment-version`, `--expected-review-reason`,
`--expected-review-flagged-at` and `--confirm`. It previews by default; `--apply`
persists the acknowledgement and audit. Only the current review is cleared;
allocated money and active state stay unchanged, including an allocation above
payment net. A stale revision or cleared review fails and requires refetching,
so the same confirmation never clears a later review. There is no browser action.
See the [finance acknowledgement contract](../finance-api.md#acknowledge-an-allocation-review).

## Native collaboration

`php artisan svc:collaboration <operation> <workspace-uuid> <client-uuid>
--actor=<user-uuid> --input=<private-json-path>` accepts the same nine short
operations as REST: `topics.list/get/create/update/delete` and
`comments.list/create/update/delete`. Input may also come from stdin with
`--input=-`. Files must be regular, non-symlink files readable only by their
owner; the input limit is 1 MiB, including Unicode bodies.

The JSON object contains `payload` (the REST body or read query), optional `id`
(the topic/comment UUID, or parent UUID for comment list/create), optional
`parent_type` (`task` or `topic`), and a required `idempotency_key` for writes.
`--format=json` returns the canonical result plus `dry_run` and `operation`.
Writes preview by default and roll back content, activity and receipts; use
`--apply` for the reviewed change. Reusing a key with different input fails.
Versioned writes require `expected_version`; deletes also require `confirm:
true`. The explicit actor's current staff role and parent access are checked,
even on receipt replay. Native CLI is independent of agent deployment flags;
portal access does not become a client write grant. No history or email is
changed.
