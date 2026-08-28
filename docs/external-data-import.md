# External data import

SVC lets a workspace bring in its existing client, project, agreement, time,
invoice, payment, and attachment records from an external source when it signs
up. This document is the safety contract for that onboarding-import feature.

## Safety contract

Import tooling is dry-run by default. It reads from a dedicated read-only
source connection and writes only with an explicit `--apply` flag. Production
exports, database dumps, manifests containing personal data, and copied files
must remain outside this public repository.

Every imported row carries a source connection, source table, and source key in
a unique import ledger. Stable mappings use SVC public UUIDs, not assumptions
that source-side integer IDs can be reused.

## Dependency order

1. Create or select one SVC workspace for the onboarding business.
2. Bind users by trusted OAuth provider and subject. Email alone never links an
   external identity to an existing local user.
3. Import client companies and memberships.
4. Import projects, tasks, time entries, proposals, agreements, and recurring
   billing definitions in parent-before-child order.
5. Import invoices, line items, manual payments, and reconciliation references.
6. Import Stripe customer, payment-method display metadata, payment-intent, and
   event references. Stripe remains authoritative for provider objects; no card
   or bank credentials are copied.
7. Copy attachments through the attachment import ledger and verify every
   digest.
8. Reconcile the links that point backwards. A time entry's invoice line and a
   milestone task's invoice line both live on the child row in the source but
   name a row imported later, so they are resolved after every importer has run
   rather than inline. Both fill a hole only: a link this system already holds
   is left alone, because repointing a billed row is not a decision an import
   pass gets to make.

Every destination column an importer owns is either mapped, reconciled in step
8, or listed as exempt with a reason in `ImportedColumnCoverageTest`. A column
that is merely fillable is not covered - the model accepts it and nothing ever
passes a value, which is how milestone links and invoice opening balances both
arrived null on every imported row.

## Rehearsal and verification

- Build a schema-only source adapter and synthetic fixture set first.
- Run a read-only source inventory that reports counts, date ranges, orphaned
  foreign keys, duplicate source keys, and file totals without printing
  personal values.
- Rehearse into a disposable database, compare per-table counts and
  deterministic fingerprints, then discard it.
- Verify representative imported records through the UI and API before relying
  on them.
- Independently verify counts, money totals, payment status, and file hashes
  after every `--apply` run, including delta passes for a source that keeps
  changing.
- Import tooling never deletes or modifies the external source; a source that
  the business wants to retire is their own decision, made outside SVC.

## Required import tooling

The implemented foundation includes:

- `svc:import:external --source=... --workspace=... [--apply]`;
- `svc:import:external:rehearse [--format=json]`, which creates isolated
  synthetic source and destination SQLite databases, applies the importer
  twice, verifies stable counts and fingerprints, and removes both databases;
- `svc:import:external:inventory --source=... [--format=json]`, which reads an
  explicitly allowlisted source without resolving any destination connection or
  workspace and emits only redacted aggregate evidence;
- `svc:import:external:attachments --source=... --workspace=... --uploader=...
  [--apply]`, which copies only planned attachment rows from an exact private
  local root, verifies both digests, and records path hashes instead of paths;
- `svc:import:external:verify --run=... [--workspace=...]`, which checks a
  completed run's ledger against the destination without printing source
  values;
- parent-ordered entity importers with idempotency and provenance tests;
- a redacted inventory report and machine-readable verification summary;
- high-water-mark and failed-row ledgers;
- planned attachment-copy ledger entries that never delete source files.

Attachments and provider-owned Stripe references are deliberately ledgered as
planned work rather than copied by the row importer. The attachment command is
dry-run by default and copy-only; the private mirror and repair commands remain
separate so a data import cannot delete or overwrite source objects.

Identity mapping must be supplied as JSON maps from source-side identifiers (or
trusted provider-and-subject pairs) to existing SVC public UUIDs. Email
addresses are never accepted as identity proof.

The rehearsal command refuses every environment except `local` and `testing`.
It accepts no database path or source override and never uses the configured
default database.
