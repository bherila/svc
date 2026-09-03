# External data import (retired)

SVC once carried a dry-run-first importer for bringing a workspace's existing
client, project and billing history in from a predecessor system. It ran once,
for one workspace, and it has been removed. This page records what it did, what
survived it, and what a future reader should not have to re-derive.

The contract it was built against is in this file's history — `git log --follow
docs/external-data-import.md` — along with the tooling itself. Nothing here is a
plan; it is all past tense on purpose.

## Why it was retired rather than kept

Import tooling is only safe while someone is watching it. It held a live
connection to a foreign database, a set of credentials, a write path into
tenant-owned billing rows, and roughly five thousand lines that no test in
ordinary use exercised. Keeping that indefinitely to serve an operation that
happens once per workspace — and had already happened — is a standing cost with
no matching benefit.

The judgement is specific to this application's situation, not general advice. A
product that onboards a new customer from a predecessor every month should keep
its importer and treat it as a feature.

## What was removed

- The import, attachment-copy, verification, rehearsal and inventory commands
  (`svc:import:*`), and the services behind them.
- The superseded-import repair, which existed to undo one specific defect in the
  import that had already been applied and verified.
- `svc:billing:backfill-ledger`, which restored columns an early version of the
  import had dropped. It read the same guarded source and could not outlive it.
- The `external` source allowlist in `config/external-import.php`, and its
  `EXTERNAL_IMPORT_*` environment variables. **Nothing in this application now
  opens a connection to any database but its own.**

## What was deliberately kept

Four tables, and the models that read them:

| Table | What it records |
|---|---|
| `external_import_runs` | One execution: when, against which source identity, and what it counted. |
| `external_import_items` | Which destination row came from which source row. |
| `external_import_failures` | A source row that was read and refused, with its reason. |
| `external_import_attachment_copies` | Which stored blob came from which source file, with digests. |

Nothing writes them any more. They are history, and the reason they are history
worth keeping is concrete rather than sentimental.

A destination row carries no memory of where it came from. It cannot say whether
the source row behind it was a current record or a superseded revision — which is
exactly the information a defective import discards, and exactly the question
that has already had to be answered once here. An early version of the importer
read the predecessor's soft-deleted rows as live, so a workspace ended up holding
its predecessor's entire history of replaced invoice drafts as current records.
The repair was possible only because `external_import_items` could still name
which rows those were. Without the ledger the only remedy would have been a full
re-import, which rewrites rows that are correct and forces every one of them to
be re-established.

So: **no migration should drop these tables**, and no cleanup should treat them
as orphaned by the absence of the code that wrote them.

## The lesson worth carrying forward

The corruption above survived a comparison that checked every imported amount
against the source to the minor unit, and passed. The invoice headers had been
imported from the source's own totals and were right; only the lines beneath them
were multiplied.

A money comparison asks whether the amounts match. It does not ask whether the
rows should exist. Those are separate questions and an import needs both — the
second is answered by reconciling each parent's children against its own stored
total, which costs one query and would have caught this immediately.
