# Client Management CLI

These Artisan commands are admin-only operational helpers. They default to user id `1`; pass `--user=<id>` only when another admin should be the actor.

Use built-in help for the current option list:

```bash
php artisan list client-management
php artisan help client-management:invoices
php artisan help client-management:apply-payment
php artisan help client-management:create-time-entry
php artisan help client-management:invoice-email-status
```

## List Invoices

```bash
php artisan client-management:invoices
php artisan client-management:invoices --client=acme-inc
php artisan client-management:invoices --status=issued --status=paid
php artisan client-management:invoices --client=acme-inc --status=draft,issued --format=json
```

The table includes invoice totals, payment totals, remaining balances, and hour-balance columns. Omit `--client` to list across all clients.

## Apply Payment

```bash
php artisan client-management:apply-payment INV-202605-001 250.00 2026-05-14
php artisan client-management:apply-payment 123 250.00 2026-05-14 --type=wire --notes="Wire confirmation 1042"
```

Payments can be applied only to issued invoices. `--type` defaults to `ach` and accepts `ach`, `credit-card`, `wire`, `check`, or `other`. A full or over payment marks the invoice paid, matching the admin API behavior.

## Create Time Entry

```bash
php artisan client-management:create-time-entry acme-inc "Build payment export" 1:30 2026-05-14
php artisan client-management:create-time-entry acme-inc "Discovery call" 0.75 2026-05-14 --project=platform --billable=0 --category="Project Management"
php artisan client-management:create-time-entry acme-inc "Deferred implementation" 2.25 2026-05-14 --defer=1
```

If `--project` is omitted, the command uses the client's only project. When a client has zero or multiple projects, pass `--project=<id|slug|exact name>`. Defaults are billable `true`, deferred billing `false`, and category `Software Development`.

## Migrate Legacy Cadence Invoices

One-off, idempotent migration of legacy `period == cycle` cadence invoices to the prior-period layout. Dry-run by default; pass `--apply` to write.

```bash
php artisan client-management:migrate-legacy-cadence-invoices                       # preview all
php artisan client-management:migrate-legacy-cadence-invoices --company=acme-inc     # scope to one client
php artisan client-management:migrate-legacy-cadence-invoices --apply                # write changes
```

Issued/paid legacy rows are re-keyed so `period_start`/`period_end` point at the prior work cycle (`cycle_*` and the invoice number are left untouched); void legacy rows are soft-deleted, and any orphaned unbilled billable time entries in their window are marked non-billable. Re-running is a no-op once migrated. See [Cadence billing & regeneration › Regenerating Cadence Invoices](cadence-billing.md#regenerating-cadence-invoices) for why this is needed.

## Invoice Email Delivery Status

Show or refresh the Brevo email-delivery status for a client invoice.

```bash
php artisan client-management:invoice-email-status INV-202605-001            # show stored status
php artisan client-management:invoice-email-status 123 --refresh             # query Brevo for latest events
php artisan client-management:invoice-email-status 123 --delivery=45 --format=json
```

`--refresh` queries Brevo and stores the latest delivery events; without it the command reports the last stored status. Use `--delivery=<id>` to scope to one delivery record.

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
with this, resolving is *take both sides, re-run, commit*.

It cannot weaken anything by itself: it derives the manifests from `REGISTRY`, so
running it after a branch is deleted produces a manifest that agrees with the
deletion. It resolves conflicts, it does not approve them — read the diff. The
guard test remains the gate.

## Audit Opening Rollover

Read-only. Counts the agreements whose ledger would change if the opening-rollover seed in `InvoiceLedgerBuilder` were repaired (#134), so the size of that change is known before it is made.

```bash
php artisan svc:billing:audit-opening-rollover                 # counts
php artisan svc:billing:audit-opening-rollover --format=json   # machine-readable
```

Three conditions have to hold together, and each alone overstates the population: the agreement carries an initial rollover, it takes the legacy monthly branch (an agreement with period retainer terms returns before the seed and never reaches it), and it has a rollover policy (with none, the seeded capacity expires in the month it is granted and no invoice sees it).

It reports counts and aggregate minutes only — never a row, an id, a name, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It deliberately does not report the change to any particular invoice: that depends on how much of each month's capacity was actually used, which cannot be read off the agreement. Capacity at stake is the ceiling on what the repair can move. It always exits zero; it is a number to read, not a gate.

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
null there reads as *zero already billed*, and its hours can be sold a second
time.

Same defect class as #135 by a different route: there a `<=` answered false for
a null and the row left the window; here the row is inside the window and the
value it contributes vanishes.

**No fix is implied, and none should be inferred from the count.**
`service_period_end` could be read fail-closed because the question was which
side of a window a row falls on. This one cannot: the question is *how much* was
billed, and a null is not a quantity. Coercing it to zero is the current
behaviour and is the bug; coercing it to anything else invents a number. The
decision is to refuse on unknown, or to establish the column is never null on a
charged invoice and make it `NOT NULL` for that status.

The agreement count is the one that sizes the exposure, because the sums are per
agreement: ten such invoices on one agreement leave one figure unknown, one on
each of ten leaves ten.

It says *may* throughout, deliberately. A null proves the contribution is
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

`ClientInvoicingService::totalBilledOveragesThrough()` is where that costs money, and its read is now fail-closed: a null period counts as *inside* the window, so overage already charged can no longer be charged a second time (#135). The interim generator's parallel already-billed sum (`InterimOverageGenerator`) carries the same guard. This command exists because that guard places an invoice by fallback rather than by a date anyone entered. Run it after an import and after any bulk invoice edit, and give the rows it names a real period.

Four conditions have to hold together, and each alone overstates: the invoice has no service period, it is charged (a draft has charged nobody), it names an agreement that exists in its own workspace (the sum filters on both keys, and the agreement column is unconstrained lineage that can dangle or cross tenants), and it carries nonzero overage hours (zero contributes nothing whichever side of the window it lands on, while negative hours move the sum too, so the hours at stake are reported as a magnitude).

### The cycle columns

`cycle_start` and `cycle_end` are nullable for the same reasons and drop rows out of the same kind of predicate (#141), so the command reports them too — in two counts, because they endanger two different things. `InterimOverageGenerator::cycleInvoices()` matches on both columns, so a row missing either is invisible to every caller: the **charged** count is the money one, feeding the already-billed subtraction and `interimOverageHoursForCycle()`, where a dropped row is charged a second time; the **live** count is the guard one, since the duplicate checks that refuse to create a second invoice for a cycle read live and settled statuses, and a row they cannot see costs a whole invoice rather than a wrong number.

Kind is applied before either, exactly as those lookups apply it — an ad-hoc or terminal invoice is excluded by kind before its cycle columns are read at all, so a null there is inert. A **null** kind is counted, deliberately: a migrated invoice carries none, and the cadence resell guard reads it on purpose for that reason.

**No fix is implied for the cycle columns, and none should be inferred from the count.** A null service period can be read fail-closed because the question is which side of a window the row falls on. A null cycle cannot: the question is which single cycle the row belongs to, and counting it in every cycle would under-charge repeatedly rather than repair anything. Those rows need a real value, which is what this command exists to find.

It reports counts and aggregate hours only — never a row, an id, an invoice number, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It always exits zero; it is a prompt to correct rows, not a gate.
