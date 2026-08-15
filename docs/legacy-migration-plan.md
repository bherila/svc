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

The implementation slice should add:

- `svc:migrate:legacy --source=legacy --workspace=... [--apply]`;
- per-entity importers with idempotency and provenance tests;
- a redacted inventory report and machine-readable verification summary;
- high-water-mark and failed-row ledgers;
- attachment copy/verify commands that never delete source files.
