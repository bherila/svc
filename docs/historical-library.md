# Historical library

Workspace owners and administrators can open **History** from the account menu
or use the existing command-palette search. Preserved invoices, tasks, comments,
notes and discussions are read-only. Their authors, original dates, company
context and parent/child relationships remain visible. Long threads paginate;
files download from private storage through the same workspace authorization.

Historical entries live outside active billing and task tables. Viewing or
indexing them cannot create invoices, collect a balance, send a reminder, invite
a participant or activate a recurrence. Current company names come from the
linked company; original billing identities remain in preserved details. Exact
source decimal strings in the presentation are never recalculated by the UI.

Source snapshots and security/runtime recovery archives are separate concerns.
The library receives a reviewed plain-text `HistoryDocument`, not an arbitrary
serialized source record. React escapes all displayed content; source HTML is
never injected. The private import adapter must strip legacy credentials before
calling the source repository. Source grants are historical facts and do not
create current access. Portal users, scoped project members and existing agent
read scopes do not receive this manager-only library.

MariaDB maintains the FULLTEXT index inside the application database. Title and
body/author/detail/table text are indexed atomically with each immutable entry.
Literal SQL matching also retains short terms, stopwords, punctuation and invoice
identifiers; SQL wildcards are escaped. All OR branches stay inside an explicit
workspace predicate. There is no external search service or asynchronous indexer.
The SQLite adapter uses literal matching for disposable tests; the MariaDB lane
also proves non-adjacent words match a committed FULLTEXT index.

`HistoricalLibraryRepository` serializes publication on the historical source
lock. Replaying the same presentation returns the existing entry; changed
documents or lineage fail explicitly. `HistoricalBlobRepository` checks the
original SHA-256 and size, uses a workspace/content-addressed object key and
refuses a different file for the same entry. Missing content can be repaired by
replaying the verified copy. No browser mutation endpoint exists for history.

Run `node scripts/check-layout.mjs --history` to inspect the index, discussion and
invoice pages at all four required widths with synthetic oversized content.
