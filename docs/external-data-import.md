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

   A claim can name a line the source has since superseded. The source
   regenerates an invoice by soft-deleting its lines and inserting fresh ones
   without repointing the rows that named the old ones, so the work was billed
   while the line that billed it is gone. Such a claim is followed to the
   invoice the superseded line belonged to and resolved to the live line that
   replaced it, but only when the replacement is unambiguous in both directions:
   exactly one live line on that invoice shares the superseded line's type, and
   exactly one superseded line of that type is still claimed. The second half
   matters because not every type is one line per invoice - a milestone is one
   line per task and a subcontractor charge one per rate - so collapsing two
   claims onto the one line that survived would mark work billed that the
   regenerated invoice dropped. Counting claims rather than copies is also what
   keeps the ordinary case working: an invoice regenerated twenty-one times
   carries twenty-one superseded copies of one aggregate line, and only the last
   is named by anything. Where the claim is exclusive there is a third
   direction: a milestone task holds its line in a column because a milestone
   cannot be split, so a live line another task already holds - at the source or
   in this system - is not available to this one. A time entry's claim is a
   pivot row precisely because one line bills many entries, so the same test
   there would refuse the ordinary case. What replaces it is a restriction on
   type: a time entry's claim is recovered only for line types the generator
   emits once per invoice. The rest it emits per item - a milestone per task, a
   subcontractor charge per rate group - and there nothing establishes which of
   two lines of a type a superseded one stood for.
   Anything less than certain is refused and reported:
   attaching work to a line that did not bill it suppresses a charge that is
   owed, which is the same size of mistake as billing it twice. The superseded line is read unfiltered -
   the only such read - because the one thing asked of it is which invoice it
   was on, and the replacement is held to the same fingerprint check as the row
   carrying the claim.

A reconciliation pass reads the source a second time, later than the read the
importer observed, so it re-checks each row against the fingerprint the ledger
recorded. That covers both a row this run refused as `source_changed` and one
edited in the gap between the two reads: either way the ledger item describes a
snapshot this run never observed, and a billing link must not be written from
it.

The ledger is keyed on the source identity rather than on a workspace, so a
public id can resolve to a row owned by a tenant this run is not importing into.
Both sides of a link are checked, not just the row being written - a foreign key
here is not workspace-composite, so nothing below the application stops one
tenant's task pointing at another's invoice line. A blocked write is reported
rather than counted as a link.

Filling a hole is decided in the write rather than in a read before it, so an
operator issuing an invoice between the two does not have their decision
replaced by an import pass.

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
