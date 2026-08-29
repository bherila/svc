# Schema drift from the predecessor

The billing engine was ported from another system. Its arithmetic transferred
cleanly; almost every defect found since has been at the **seam** — a place
where ported code met a column whose name, type, or meaning had changed, and
assumed it had not.

Twelve of the fifty-one review findings on this work were that. One of them —
`quantity` — meant the generator could not have written a single invoice line in
production, and the test suite could not see it because SQLite accepts what
MySQL rejects.

None of that needed discovering one bug at a time. **Both schemas exist**, so
the difference is mechanically derivable. This page is that derivation.

## How to regenerate this

The predecessor's schema is restored, read-only, in `bherila_legacycm` on the
application host:

```bash
ssh <host> 'mysql --defaults-file=$HOME/.svc-legacy-ro.cnf -N -B -e "
  SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
  ORDER BY TABLE_NAME, ORDINAL_POSITION;"' > legacy-schema.tsv
```

SVC's side comes from `Schema::getTables()` / `Schema::getColumns()` after a
fresh migrate. Compare on shared table and column names; a type difference on a
shared name is a seam, and a seam is where a port breaks.

The credentials file is `0600` on the host and grants `SELECT` only — a write
probe against it is refused by the database, not by convention.

## Columns that share a name and differ in type

Every row here has cost, or could cost, a defect.

| Column | Predecessor | SVC | Consequence |
| --- | --- | --- | --- |
| `client_invoice_lines.quantity` | `varchar(20)` | `decimal(16,4)` | **The one that mattered.** The predecessor migrated this column *to* varchar specifically to store `h:mm`. Writing `1:30` here is accepted by SQLite and rejected by MySQL in strict mode, so generation worked in tests and could not work in production. |
| `client_invoices.status` | `enum('draft','issued','paid','void')` | `varchar(32)` | SVC adds `partially_paid`. Every exhaustive four-value list ported from that world silently omits it — which produced four separate defects, three found only by this diff. Now centralised in `App\Support\Billing\InvoiceStatus`. |
| `client_invoices.issue_date` | `datetime` | `date` | Time-of-day comparisons behave differently at boundaries. |
| `client_invoices.due_date` | `datetime` | `date` | As above. |
| `client_agreement_recurring_items.amount` | `decimal(10,2)` | `bigint` | Money is integer minor units here, decimal currency units there. Converted at the model seam; never inside arithmetic. |
| `client_invoice_payments.amount` | `decimal(10,2)` | `bigint` | As above. |
| `client_invoice_lines.description` | `varchar(255)` | `text` | Widening only; safe. |
| `client_agreement_recurring_items.description` | `varchar(255)` | `text` | Widening only; safe. |
| `client_agreement_recurring_items.anchor_day` | `tinyint` | `integer` | Widening only; safe. |
| `client_agreement_recurring_items.anchor_month` | `tinyint` | `integer` | Widening only; safe. |

## Meanings that changed without the name changing

Type comparison cannot catch these; they are recorded because each one has
already caused a defect.

- **`client_time_entries.status`** — the predecessor kept `approval_status`
  separate from invoicing. SVC collapsed both into `status`, so issuing an
  invoice rewrites approved work to `invoiced`. Ported code matching the literal
  `approved` therefore forgets every entry it has already billed. See
  `ClientTimeEntry::scopeApproved()`.
- **A time entry's invoice line** — a column there, a pivot with a uniqueness
  constraint here. Releasing is a detach, billing is an attach, and an entry
  spanning two lines must become two rows.
- **A task's invoice line** — still a column, because a deliverable cannot be
  split. This asymmetry is why voiding an invoice had to release two different
  things, and originally released only one.
- **`subcontractor_billing_mode`** — gone. The presence of
  `subcontractor_cost_amount` is the flat-hourly signal in this schema.
- **Currency** — recurring items and time entries carry their own. The
  predecessor was effectively single-currency, so ported code copies amounts
  without checking, which relabels rather than converts.

## The engine gap, and how it is closed

Everything above is only findable if the tests run on the engine that ships.
They did not. The suite ran on in-memory SQLite; production is **MariaDB 10.6**,
reached through Laravel's `mariadb` driver with `strict => true`. SQLite hides
schema drift in two distinct ways, and both have now cost real defects.

**It stores what it is handed.** SQLite's column types are advisory, so
`'1:30'` into `decimal(16,4)` is kept verbatim. That is why the `quantity`
defect passed 404 green tests: the write MySQL refuses is the write SQLite
records.

**It turns an unknown identifier into a string.** Laravel's SQLite grammar
double-quotes identifiers, and SQLite falls back to reading an unresolvable
double-quoted identifier as a string literal. So `orderBy('id')` on a table with
no `id` column becomes `ORDER BY 'id'` — ordering by a constant. No error, and
the ordering silently disappears, which means a test asserting order can pass
for the wrong reason. MySQL raises `1054` instead. The replay harness had
exactly this bug against `workspace_invoice_counters`, which is keyed on
`workspace_id` alone.

A second CI job (`mariadb` in `.github/workflows/tests.yml`) now runs the whole
suite against MariaDB 10.6, and `deploy` waits on it. SQLite stays the default
so the local loop remains fast — the MariaDB run is the one that has to be true.

To run it locally against any MySQL-compatible server:

```bash
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=svc_testing DB_USERNAME=root DB_PASSWORD=secret \
DB_EXPECT_DRIVER=mariadb php artisan test
```

PHPUnit does not overwrite variables already set in the environment, so these
win over the sqlite defaults in `phpunit.xml` without a second config file to
drift out of step.

`DB_EXPECT_DRIVER` is not read by the application. It states what the run claims
to be, and `tests/Feature/DatabaseDriverTest.php` fails if the claim is false —
otherwise a mistyped variable or a service container that never came up would
fall back to SQLite and report a green run that proved nothing. That test also
asserts strict mode is on, because without `STRICT_TRANS_TABLES` MariaDB
truncates rather than refuses and is no more informative than SQLite. The
production server's own `sql_mode` does **not** include it; Laravel's `strict`
connection flag sets it per session, which is what the assertion checks.

The driver now names the server honestly: both production and the hosted job use
Laravel's `mariadb` connection. At MariaDB 10.6 this is schema-equivalent to the
old `mysql` connection for this application. The equivalence ends at 10.7,
where Laravel starts compiling `uuid()` columns as native `uuid` instead of
`char(36)`. The current migration inventory has 31 logical UUID columns: 29
declared with `uuid()` (discounting the repeated alteration of
`identity_memberships.public_id`) and two Passport `foreignUuid()` client IDs.
`svc:database:status` runs before every deployment migration and refuses 10.7+
until a fresh inventory and one deliberate migration cover every UUID and
foreign-UUID column. The hosted MariaDB job runs the same guard; a driver typo
or a premature image upgrade is therefore a test failure rather than silent
schema drift. That job also creates a JSON column through Laravel's MariaDB
grammar and proves malformed JSON is rejected, preserving the validation
behavior of the existing production schema.

### What the first MariaDB run found

Fourteen failures, from four causes — none in the billing arithmetic, which
passed unchanged:

- `ProjectAccessLegacyOrphanTest` opts out of the wrapping transaction (SQLite
  refuses to toggle `PRAGMA foreign_keys` inside one) and so **commits**. On
  in-memory SQLite the separate process gets its own database and those rows die
  with it; against a shared server they survive, and eight later tests saw a
  workspace and a client company they had not created. It now cleans up after
  itself.
- The replay fingerprint's `orderBy('id')`, above.
- A source-equivalence test hard-coded sqlite as the destination, so on MySQL
  the two connections were genuinely distinct and the guard correctly let the
  import through — the test asserted nothing.
- A query-log assertion matched `from "external_import_items"`; MySQL uses
  backticks.

### The driver now names the server

An earlier version of this document left production on `DB_CONNECTION=mysql`
and made the CI job mirror it. #78 closes that mismatch in both places at once.
The switch is a no-op at the current MariaDB 10.6 version for this schema, and
the pre-migration deployment guard above makes the 10.7 UUID boundary explicit.

## Verifying a source that moved

The import ledger records, per row, a hash of the source row as it was. That is
the right check for a source nobody touches and the wrong one for a source that
kept being used: it collapses "someone renumbered the invoices" and "the money
is different" into the same refusal.

The predecessor's database was renumbered and partly soft-deleted after the
migration, so 1052 of 1372 rows failed that hash while every money column, every
date and every status was in fact intact. A declared restore
(`restore_of_database`) is therefore verified by comparing it against what the
importer wrote, column by column, in `RestoreAgreementVerifier`. Differences are
named and counted, and each has to be accepted by name with `--accept-drift`.
Accepting a renumbering does not accept a changed total.

Columns being backfilled are skipped, because the destination holds no copy of
them - which is exactly what makes them worth backfilling. Their trustworthiness
rests on the rest of the row still agreeing, and that is now measured.

This is also where the engine gap bit twice: the verifier truncated only the
source side of a date comparison, which is invisible against MySQL (a `date`
column returns `2026-03-01`) and wrong against SQLite (`2026-03-01 00:00:00`).
It passed against the real restore and failed the moment a test ran it on the
other engine.

## Rule

Before porting any write, check the destination column against this table. A
column that changed type is not a detail — it is the most likely place for the
port to be wrong, and the least likely place for a test on SQLite to notice.
The MariaDB job is what makes "SQLite did not notice" stop being the end of the
story.
