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

## Audit Opening Rollover

Read-only. Counts the agreements whose ledger would change if the opening-rollover seed in `InvoiceLedgerBuilder` were repaired (#134), so the size of that change is known before it is made.

```bash
php artisan svc:billing:audit-opening-rollover                 # counts
php artisan svc:billing:audit-opening-rollover --format=json   # machine-readable
```

Three conditions have to hold together, and each alone overstates the population: the agreement carries an initial rollover, it takes the legacy monthly branch (an agreement with period retainer terms returns before the seed and never reaches it), and it has a rollover policy (with none, the seeded capacity expires in the month it is granted and no invoice sees it).

It reports counts and aggregate minutes only — never a row, an id, a name, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It deliberately does not report the change to any particular invoice: that depends on how much of each month's capacity was actually used, which cannot be read off the agreement. Capacity at stake is the ceiling on what the repair can move. It always exits zero; it is a number to read, not a gate.

## Audit Unplaceable Invoices

Read-only. Counts invoices with no `service_period_end`, and how much billed overage they carry.

```bash
php artisan svc:billing:audit-unplaceable-invoices                 # counts
php artisan svc:billing:audit-unplaceable-invoices --format=json   # machine-readable
```

The column is nullable and stays that way (#73): an invoice can be created by hand without a service period, and the external importer passes the source value through unchanged. Everything downstream, though, decides which period an invoice belongs to by comparing that column, and SQL comparison answers false for a null rather than unknown — so an unplaceable invoice is silently treated as outside whatever window is being asked about.

`ClientInvoicingService::totalBilledOveragesThrough()` is where that costs money, and its read is now fail-closed: a null period counts as *inside* the window, so overage already charged can no longer be charged a second time (#135). This command exists because that guard places an invoice by fallback rather than by a date anyone entered. Run it after an import and after any bulk invoice edit, and give the rows it names a real period.

Four conditions have to hold together, and each alone overstates: the invoice has no service period, it is charged (a draft has charged nobody), it names an agreement (the sum is taken per agreement), and it carries overage hours (a row with none contributes nothing whichever side of the window it lands on).

It reports counts and aggregate hours only — never a row, an id, an invoice number, a company, or a workspace — so it is safe to run against real billing data and to paste into an issue. It always exits zero; it is a prompt to correct rows, not a gate.
