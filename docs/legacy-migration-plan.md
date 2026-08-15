# Legacy client-management migration plan

## Safety contract

Migration tooling is dry-run by default. It reads from a dedicated read-only
source connection and writes only with an explicit `--apply` flag. Production
exports, database dumps, manifests containing personal data, and copied files
must remain outside this public repository.

Every imported row carries a source system, source table, and source key in a
unique migration ledger. Stable mappings use SVC public UUIDs, not assumptions
that legacy integer IDs can be reused.

## Dependency order

1. Create one SVC workspace for the existing business instance.
2. Bind users by trusted OAuth provider and subject. Email alone never links an
   existing local user to an identity.
3. Import client companies and memberships.
4. Import projects, tasks, time entries, proposals, agreements, and recurring
   billing definitions in parent-before-child order.
5. Import invoices, line items, manual payments, and reconciliation references.
6. Import Stripe customer, payment-method display metadata, payment-intent, and
   event references. Stripe remains authoritative for provider objects; no card
   or bank credentials are copied.
7. Copy attachments through the file migration ledger and verify every digest.

## Rehearsal and cutover

- Build a schema-only source adapter and synthetic fixture set first.
- Run a read-only production inventory that reports counts, date ranges,
  orphaned foreign keys, duplicate source keys, and file totals without printing
  personal values.
- Rehearse into a disposable database, compare per-table counts and deterministic
  fingerprints, then discard it.
- Deploy SVC in shadow-read mode and verify representative records through the
  UI and API.
- At cutover, pause legacy client-management writes, capture the source high-water
  marks, run `--apply`, perform a delta pass, and independently verify counts,
  money totals, payment status, and file hashes.
- Keep the legacy application read-only through a defined rollback window. A
  rollback restores routing and resumes legacy writes; it does not reverse-copy
  partially changed records.

## Required migration tooling

The implemented foundation includes:

- `svc:migrate:legacy --source=legacy --workspace=... [--apply]`;
- `svc:migrate:legacy:rehearse [--format=json]`, which creates isolated
  synthetic source and destination SQLite databases, applies the importer twice,
  verifies stable counts and fingerprints, and removes both databases;
- parent-ordered entity importers with idempotency and provenance tests;
- a redacted inventory report and machine-readable verification summary;
- high-water-mark and failed-row ledgers;
- planned attachment-copy ledger entries that never delete source files.

Attachments and provider-owned Stripe references are deliberately ledgered as
planned work rather than copied by the row importer. The private attachment
mirror and repair commands remain separate so a database migration cannot delete
or overwrite source objects.

Identity mapping must be supplied as JSON maps from legacy identifiers (or
trusted provider-and-subject pairs) to existing SVC public UUIDs. Email addresses
are never accepted as identity proof.

The rehearsal command refuses every environment except `local` and `testing`.
It accepts no database path or source override, never uses the configured default
database, and cannot be used for production cutover. Production inventory,
shadow reads, write freeze, and cutover remain separately authorized operations.
