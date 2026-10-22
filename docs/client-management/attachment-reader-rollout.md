# Attachment reader compatibility

The first release adds readers before private files or collaboration uploads can
be written. It does not activate historical blobs or imported attachment copies.

## Release phases

| Phase                                     | Database default | Upgraded ordinary uploader                        | Sharing and topic/comment uploads                          |
| ----------------------------------------- | ---------------- | ------------------------------------------------- | ---------------------------------------------------------- |
| Before R1                                 | No file flag     | Existing parent inheritance                       | Unavailable                                                |
| R1 reader                                 | `true`           | Explicit `true` in the central staged-row factory | Rejected before bytes or recovery rows                     |
| R2 writer, after verified R1 reader floor | `false`          | Explicit private, even when controls are disabled | Separate domain controls flag; private remains the default |

R1 keeps every existing lifecycle row true, including files under a currently
private parent and staged/deleting/corrupt rows. An old uploader omitting the new
column therefore retains existing inheritance. R1 has no switch that enables
private or new-parent uploads. A supplied visibility field is rejected rather
than ignored, including null or true. Existing Agent cutovers remain additional
requirements and cannot enable these future writers.

All R1 readers enforce a stored false value independently of writer controls.
Once R2 writes incompatible rows, rollback below R1 is unsafe. The R1 down
migration refuses retained private files or topic/comment parents; it never
publishes or reassigns them. Disabling R2 sharing controls must not turn future
ordinary uploads public: every R2 adapter keeps its private default.

## Shared readers and adapters

`AttachmentReader` scopes to one workspace and applies current parent authority
in SQL before limits, counts or decrypted filenames. Its explicit audience is
selected by the adapter. Native operator access preserves internal or authorized
Portal access. Fixed Portal reads use exact current grants. Generic Agent files
remain workspace-manager-only, then use Portal-first disclosure for dual
identities. Expense receipts have no Portal parent and remain staff-manager-only.
This intentionally narrows dual Agent access to legacy files and denies dual
Agent file/receipt writes; native operator permissions remain independent.

Native owner/admin Portal previews retain their existing authority through a
server-selected `PortalPreview` context. The reader independently rechecks the
current native manager role, applies client-safe parent/ancestry and file flags,
and supplies no management actions. This context also preserves native previews
for dual managers with no project grants; fixed Portal and Agent adapters never
select it. New topic/comment parents retain exact Portal grants even in preview.

File/receipt API and MCP lists retain their existing data and parent/expense
version fields and add `meta.next_cursor`. Optional `limit` is 1–100 (default 100);
rows retain descending ID order. A serialized-data budget may shorten a page, so
follow the opaque continuation until null. Cursors bind workspace, parent, actor,
audience and order, and reject legacy numeric encodings for these new pagination
contracts. No filename is truncated or silently dropped. Existing cursor users
retain their explicit legacy compatibility policy.

Native agreement/receipt pages receive finished older/newer file URLs with
100-row pages. The operations overview pages its authorized file metadata in
300-row pages and also sends finished navigation URLs. Parent authorization and
the stored file flag are evaluated in SQL before both row and byte budgets.

`AttachmentRecordResolver` validates parent workspace/company/project lineage.
Readable and uploadable types are separate: topic/comment readers exist in R1,
but upload request schemas, prepared URLs, native actions and the staged-row
model refuse these parents. Their readers reuse collaboration policies,
including tombstones and a comment's validated topic/task ancestry. Task files,
task-comment files, comment threads and authorized search reuse `TaskVisibility`
before pagination. Invalid or private task/list ancestry fails closed for Portal
readers; it conveys no foreign ancestor names or identifiers.

| Entry point                                   | R1 integration                                                                                                                           |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Native file upload/download/delete            | Existing routes; legacy true uploads, shared held-URL read authorization                                                                 |
| Operator and Portal agreement file lists      | Shared bounded reader; exact Portal or verified native preview; no Portal management                                                     |
| Operations overview file metadata             | Shared authorized query before its result bound                                                                                          |
| Expense receipt screen                        | Existing manager policy plus the shared reader                                                                                           |
| Generic Agent list/get/download URL/content   | Same manager gate and canonical metadata; exact dual Portal disclosure                                                                   |
| Generic Agent upload URL/multipart/delete     | Existing scopes/cutovers; reject unsupported intent before staging, deny dual writes, current locked publication/delete/replay authority |
| Agent expense receipt URL/list/content/upload | Existing separate expense scope/cutover; no dual Portal access; same staging and current authority                                       |
| Repair CLI                                    | Existing preview/apply lifecycle cleanup; preserves stored visibility                                                                    |
| Historical library/blob download              | Separate manager-only history contract, unchanged                                                                                        |

The central `AttachmentStorageService::recordStaged` creates every active native
attachment row and explicitly persists true in R1. No native attachment CRUD CLI
exists yet. R2 must add any new CLI operation through shared actions, exact actor
UUIDs, private JSON and preview/apply semantics. Preview must not stage physical
objects or leave recovery rows; a database rollback alone cannot undo staging.
MCP continues to use authenticated prepared multipart REST URLs, not inline blobs.

Agent publication and replay recheck current staff/Portal membership rows with
registered locking reads. Workspace and domain locks precede current membership
facts, and the file row follows them. Proposal/agreement/invoice domain locks
precede Workspace in the existing registry; task/company/project/expense domains
follow Workspace. Direct current Portal membership rows avoid stale ordinary
company subqueries under an outer repeatable-read snapshot.

## Ownership and validation

The file lane owns file schema, reader/upload policy, resolver/storage/action,
file/receipt adapters and focused tests. The task lane owns task graph schema,
`TaskVisibility` and bounded ancestry facts; consumers share that policy rather
than implementing their own recursion. Shared read enum/schema changes add no
operations and leave upload enums unchanged. Generated contracts and inventories
must remain aligned before publication.

Required proofs cover actual SQLite/MariaDB defaults and guarded rollback, an old
uploader omitting the column, all upgraded uploader families writing true, and no
staged objects/rows for rejected private/new-parent uploads. Metadata and bytes
must deny after file flags, parent visibility, ancestry, tombstones or grants
change, including held signed URLs and dual identities. Authorization precedes
limits and query counts remain bounded as file/comment counts grow. Current
membership probes use a real old MariaDB snapshot plus a separate committed
new-company Portal grant for upload/delete and receipt replay. Historical blobs
and repair behavior keep their existing independent tests.

## Local checkpoint evidence

This reader candidate was checked on the collaboration foundation plus task graph
reader checkpoints `52e12be0` and `7dad3fed`. Its temporary byte-budget prerequisite
is an exact copy of the shared `CursorPage` and `AgentPageBudget` source from
contacts `8bdcca66`; the real integrated foundation must replace that dependency
before publication. The only additional shared cursor behavior is the optional
strict legacy-rejection parameter, with old callers retaining their default.

Focused SQLite file workflows passed 76 tests / 936 assertions before the final
preview additions; the final reader/strict-cursor follow-up passed 26 tests / 171
assertions. MariaDB reader, ancestry, pagination, migration and six real snapshot
races passed 39 tests / 696 assertions, followed by the two native-preview cases
(52 assertions) and native agreement/operations continuation (95 assertions).
Contract and registry checks passed 41 tests / 4,348 assertions. These are
intersecting focused selections, not a full-suite result.

One production build and the synthetic browser harness passed 44 page states at
390, 820, 1440 and 1920 pixels. Each native file family navigated older and newer
pages, including native owner and dual-owner Portal previews; oversized filenames
wrapped without horizontal document overflow. Workspace shell navbars remained
49 pixels high. The pre-existing Operations overview keeps its separate legacy
header; its file pagination works at all four widths. Full-suite, mutation and
final integrated-foundation checks remain required before release.
