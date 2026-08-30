# Tenant foreign keys

A tenant-owned row's parent must live in the same workspace. Since #113 the
database refuses to store one that does not.

## Why this is schema work rather than another scoped query

The single most-repeated finding across the review cycles on this port was a
tenant-owned row reached on its parent's authority: a ledger lookup keyed on a
source id alone, a deferred allocation selecting on company alone, a pivot row
resolved by id with no ownership check, a holder probe keyed on everything except
the workspace. Each was fixed where it was found — a scoped query plus an
isolation test — and the class kept reappearing on the next surface, because the
schema permitted the invalid row. Nine issues fixed nine instances of one
defect.

Application scoping is still required and still tested. What changed is that it
is no longer the only thing standing between two tenants.

## The invariant

For a tenant-owned child table `C` with a column naming a tenant-owned parent
`P`:

```
unique (workspace_id, id) on P
foreign key (workspace_id, C.parent_id) references P (workspace_id, id)
```

`id` is already `P`'s primary key. The unique index adds no uniqueness; it exists
because InnoDB will only accept a foreign key whose referenced columns are the
leftmost prefix of some index on the parent, and `(workspace_id, id)` is not a
prefix of anything the schema already had.

**That index is load-bearing, not redundant.** Dropping one because a primary key
already covers `id` is accepted silently by SQLite and refused by InnoDB with
errno 1553 the moment a foreign key depends on it. The same goes for the
single-column foreign keys these sit beside: none of them was dropped, and
dropping one is a separate change with the MariaDB job as its judge.

## Adding a tenant-owned table

1. Give it `workspace_id`, NOT NULL, with its own key to `workspaces`.
2. Add `unique (workspace_id, id)` to any parent it will point at, if the parent
   does not have one.
3. Add the composite key, with the same `ON DELETE` rule as the single-column key
   on the same column.
4. Add the reference to `App\Support\Tenancy\TenantReferenceInventory`.

`TenantForeignKeyInventoryTest` walks the live schema for tenant-owned columns
that name a tenant-owned parent, so a table added without step 4 fails there
rather than becoming a defect later.

## Why the delete rule has to match

Two keys from the same column to the same parent are both evaluated when the
parent row is deleted, so they have to agree. CASCADE beside CASCADE deletes the
same child row twice, which is a no-op. RESTRICT beside RESTRICT refuses twice.
A RESTRICT beside a CASCADE blocks a delete the schema allows today.

## Exemptions, and the engine rule behind them

Every exemption here has the same root cause, so it is worth stating once.

A composite key pairs the parent column with `workspace_id`, and `workspace_id`
is NOT NULL on every tenant-owned child table. **InnoDB refuses
`ON DELETE SET NULL` on a foreign key containing a NOT NULL column (errno
1830).** So a nullable column whose existing rule is SET NULL cannot be given a
matching composite key, and the alternatives — RESTRICT or CASCADE — each
contradict the rule already governing that column.

The exempt columns fall in two groups:

**Nullable references with an existing SET NULL rule.** Deleting the parent
detaches the child today; a composite key would either block the delete or take
the child with it.

| Child | Column | Parent |
| --- | --- | --- |
| `client_proposals` | `client_project_id` | `client_projects` |
| `client_agreements` | `client_project_id` | `client_projects` |
| `client_agreements` | `source_proposal_id` | `client_proposals` |
| `client_invoice_lines` | `client_project_id` | `client_projects` |
| `client_tasks` | `client_invoice_line_id` | `client_invoice_lines` |
| `client_time_entries` | `client_task_id` | `client_tasks` |
| `client_time_entries` | `split_from_time_entry_id` | `client_time_entries` |
| `external_import_attachment_copies` | `client_attachment_id` | `client_attachments` |
| `stripe_payment_method_states` | `client_company_id` | `client_companies` |
| `stripe_payment_method_states` | `client_stripe_customer_id` | `client_stripe_customers` |

`stripe_payment_method_states` is doubly exempt: it is a webhook-ordering cache
whose own `workspace_id` is nullable, because an event can arrive before anything
here knows which tenant it belongs to.

**Nullable attribution columns with no referential constraint at all.** These
name the agreement, schedule, or recurring item a charge came from. An invoice is
the financial record and outlives whatever explains it, so the schema has always
allowed the named row to disappear. A composite key would newly refuse that
deletion, and the SET NULL that preserves today's behaviour is barred by the same
errno 1830.

| Child | Column | Parent |
| --- | --- | --- |
| `client_invoices` | `client_agreement_id` | `client_agreements` |
| `client_invoices` | `client_billing_schedule_id` | `client_billing_schedules` |
| `client_invoice_lines` | `client_agreement_id` | `client_agreements` |
| `client_invoice_lines` | `client_agreement_recurring_item_id` | `client_agreement_recurring_items` |

**An exemption is not an unmeasured gap.** `svc:schema:audit-tenant-fks` counts
violations on the exempt columns exactly as it does on the enforced ones, and
exits non-zero either way. What an exemption buys is that the drift is reported
rather than refused at write time — not that it goes unnoticed.

## `client_company_memberships` had no workspace at all

It was the one table in the tenant graph reachable only through its parent: a
membership named a company and nothing else, so every question about which
workspace it belonged to was answered by a join a caller could forget. It grants
a portal user access to a client's records, which makes it the worst place in the
schema for that.

`workspace_id` was added, backfilled from the owning company, and made NOT NULL
in one migration. The backfill is total and derivable: `client_company_id` is NOT
NULL under a cascade key, so every row has exactly one company and a company has
exactly one workspace. Nothing is invented. If any row still lacked a workspace,
the migration aborts with a count rather than deleting the row.

The column is derived by the model's `creating` hook and deliberately absent from
its fillable list: a caller that could set it could set it wrongly, and the
composite key would then refuse the write anyway.

**Watch for ambiguity when adding `workspace_id` to a pivot.** `client_companies`
and `client_company_memberships` now both have the column, so
`$user->clientCompanies()->where('workspace_id', ...)` became ambiguous — MariaDB
raises errno 1052 and SQLite raises its own error. Four call sites in
`AgentAccess` and `InvoiceController` were qualified to
`client_companies.workspace_id`.

## Running the audit before migrating

```bash
php artisan svc:schema:audit-tenant-fks            # counts, exits non-zero on any
php artisan svc:schema:audit-tenant-fks --format=json
```

It prints counts and schema identifiers only — never a row, an id, a name, or a
workspace — so it is safe to run against a database of client and billing records
and to paste the output into an issue. A non-zero result is a migration that will
abort partway through, not a report to read later. A reference the schema cannot
answer yet reports `pending` rather than passing, because a check that cannot
distinguish "passed" from "did not run" has to fail.

## Testing against a row that can no longer be written

A good number of tests exist to prove the *application* refuses a cross-tenant
row, and they can only prove it by producing one. Their subject did not go away:
a database migrated from before these keys can still hold rows the keys would now
refuse, and the scoped query that ignores them is the second line of defence.

`Tests\Concerns\WritesLegacyCrossTenantRows` writes such a fixture with
enforcement suspended. MariaDB takes `SET FOREIGN_KEY_CHECKS = 0` inside a
transaction. SQLite ignores `PRAGMA foreign_keys` inside one — silently, which is
why `ProjectAccessLegacyOrphanTest` runs in its own process without a transaction
— but honours `PRAGMA defer_foreign_keys`, which postpones every check to a
COMMIT a `RefreshDatabase` transaction never reaches.

Reaching for that helper anywhere else means the schema is being argued with
rather than tested.

## What SQLite can and cannot see here

Unusually for this repo, SQLite does enforce composite foreign keys when
`PRAGMA foreign_keys` is on, so `CompositeTenantForeignKeyTest` asserts the
refusal on both lanes rather than skipping one — and it asserts the pragma is on
rather than assuming it, because a test that passes by not running is worse than
no test.

What SQLite cannot see is everything around them: the errno 1553 index
dependency, the errno 1830 SET NULL bar, the 64-character identifier limit that
forced explicit names on every key here, and the errno 1052 ambiguity above. The
MariaDB job is the one that has to be true. See
[schema-drift.md](schema-drift.md).
