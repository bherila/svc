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

## Rule

Before porting any write, check the destination column against this table. A
column that changed type is not a detail — it is the most likely place for the
port to be wrong, and the least likely place for a test on SQLite to notice.
