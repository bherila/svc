# Engagement, billing, and migration contract

This document fixes the shared names and invariants for the first complete SVC
workflow. It is intentionally provider-neutral and contains no production data.

## Cross-cutting invariants

- Every business row has an immutable UUID `public_id` and a direct
  `workspace_id`, even when ownership can also be reached through a parent.
- Nested writes verify that every supplied parent belongs to the route
  workspace. Workspace administrators can manage records; client-company
  members can only see records explicitly exposed to their company.
- Money is stored as integer minor units (`*_amount`) with an ISO 4217
  `currency` code. Durations are stored as integer `minutes`.
- Provider identifiers are opaque strings. Finance reconciliation uses an
  external UUID and never a foreign key into another application's database.
- Domain state changes run through services and are covered by tenant-isolation
  and idempotency tests.

## Engagement tables

`client_time_entries`

- workspace, client company, project, optional task, and worker user
- `worked_on`, `minutes`, `description`
- `is_billable`, `is_deferred`, optional `billing_rate_amount`, `currency`
- client visibility requires an explicit client-facing description; internal
  descriptions are never used as a client-facing fallback
- status: `draft`, `approved`, or `invoiced`
- optional approval user/time and subcontractor cost metadata
- billable approval snapshots the rate/currency from the most recently effective
  active project agreement, falling back to a company-wide agreement; an
  explicit manager override is required when no applicable rate exists
- time-derived invoice totals use integer `minutes * hourly_rate / 60` rounding;
  decimal hours are display metadata and never the authoritative amount
- workspace managers see workspace time; project owners/managers see team time in
  managed projects; contributors see their own assigned-project time; viewers see
  no time; clients see only approved entries explicitly shared with their company

`client_proposals` and `client_proposal_items`

- proposal belongs to a workspace/client and optionally a project
- title, summary/terms, currency, validity and lifecycle timestamps
- status: `draft`, `sent`, `accepted`, `declined`, or `expired`
- immutable acceptance metadata records the local accepting user when present,
  plus the supplied signer name/title; it never stores an authentication secret
- items store description, decimal quantity, unit amount, cadence, and ordering

`client_agreements` and `client_agreement_recurring_items`

- agreement belongs to a workspace/client, optionally a project and source
  proposal
- status: `draft`, `active`, `paused`, `terminated`, or `expired`
- effective dates, provider-neutral agreement text, portal visibility, signer
  metadata, currency, hourly rate, retainer amount/minutes, billing cadence, and
  rollover policy
- cadence values: `one_time`, `monthly`, `quarterly`, `semi_annual`, or `annual`
- recurring items retain their own cadence, anchor month/day, effective dates,
  amount, taxability, and active state

## Billing tables

`client_invoices` and `client_invoice_lines`

- invoice belongs to a workspace/client and optionally an agreement
- invoice number is unique inside a workspace
- status: `draft`, `issued`, `partially_paid`, `paid`, or `void`
- issue/due/service dates, currency, subtotal/tax/total/paid/balance minor units,
  notes, and lifecycle timestamps
- issued invoices are immutable except through explicit payment, void, email,
  or correction operations; generation never silently rewrites them
- lines store type, description, quantity, unit amount, tax amount, total amount,
  and ordering
- `client_invoice_line_time_entries` explicitly associates billed time entries
  with lines and prevents one entry from being billed twice
- draft replacement and discard release removed time allocations; voiding an
  unpaid invoice restores linked `invoiced` time to `approved` and releases it
- `workspace_invoice_counters` allocates monotonic `SVC-*` numbers under a row
  lock in the same transaction that creates the invoice, while preserving the
  highest existing SVC sequence among externally imported numbers
- every project-attributed manual line belongs to the invoice client as well as
  the workspace

`client_invoice_payments`

- payment belongs to the same workspace as its invoice
- status: `pending`, `succeeded`, `failed`, `refunded`, or `disputed`
- amount/currency, received date, method, reference, notes, optional provider and
  provider payment identifier, and optional external finance transaction UUID
- successful non-refunded payments determine invoice paid and balance amounts;
  transitions are idempotent

`payment_reconciliations`

- allocation belongs directly to a workspace and invoice payment
- external system slug plus external transaction UUID identify the finance-side
  record without creating a cross-database foreign key
- allocated amount and currency allow one finance transaction to cover multiple
  payments and one payment to use multiple finance transactions
- active allocations cannot exceed a successful payment amount net of refunds;
  later refunds enforce the same invariant
- deactivation preserves reconciliation history instead of deleting it

`client_billing_schedules`

- provider-neutral recurring invoice definition tied to an agreement
- cadence, anchor month/day, next run date, due-days, active state, and a JSON
  line template constrained to descriptions and numeric pricing metadata
- generation is idempotent per schedule and service period

`client_invoice_email_deliveries`

- append-only delivery attempts with recipients, subject, status, provider
  message reference, lifecycle timestamps, and a redacted error summary

`client_stripe_customers`, `client_stripe_payment_methods`, and
`client_stripe_events`

- optional adapter metadata only; no card or bank credentials
- Stripe event IDs are globally unique and processed exactly once
- webhook signature verification occurs before persistence or state changes

## Private attachments

`client_attachments` belongs directly to a workspace and identifies an allowed
record by `record_type` plus immutable `record_public_id`. It stores an opaque
object key, encrypted original filename, media type, byte count, SHA-256 digest,
uploader, and lifecycle state. Controllers use the attachment service rather
than Laravel storage directly. Upload promotion and deletion follow the
two-phase process in `file-storage-plan.md`.

## External data import

`external_import_runs`, `external_import_items`, and
`external_import_failures` form the import ledger. Source identity is the
tuple `(source_connection, source_table, source_key)`. Successful items map that
tuple to an SVC record type and public UUID. A run records source high-water
marks, redacted counts, deterministic fingerprints, mode, and completion state.

`svc:import:external` is dry-run by default. `--apply` is required for writes,
the configured source connection must be explicitly marked read-only, and the
command must refuse the destination connection as its source. Output contains
counts, keys only when safe, and hashes; it never prints names, email addresses,
descriptions, notes, invoice contents, or file paths.

`svc:import:external:attachments` consumes only planned attachment ledger rows.
It requires an explicit workspace member as the import uploader, stores no
raw source path in the provenance ledger, and verifies source and destination
SHA-256 digests before an attachment becomes an imported item.
