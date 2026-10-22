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

The retained-state reader floor and the future-upload writer floor are distinct.
Rolling the application back to R1 preserves reads of existing private files but
resumes its explicit-true ordinary uploader, even if the R2 database default is
still false. Before enabling R2 privacy defaults, verify a rollback artifact whose
ordinary upload adapters also explicitly persist false; that becomes the minimum
operational writer version. If fallback to R1 is unavoidable, all upload paths
must be independently disabled before traffic reaches that binary. Changing the
column default alone cannot make an R1 uploader private.

The R2 rollout drill must exercise native uploads, Agent prepared URLs and
multipart publication, both generic files and expense receipts, and any future
CLI uploader. With sharing/new-parent controls disabled, ordinary uploads must
remain private on both the release and its writer-compatible fallback. A fallback
that cannot meet that contract must reject preparation and publication before
staging bytes or recovery rows. These are R2 prerequisites, not new R1 writers.

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

This reader candidate integrates checked collaboration foundation `6a53d058`,
including actual contacts release `33a7ddb1` and task pagination checkpoint
`27229317`, plus task graph reader checkpoints `52e12be0` and `7dad3fed`.
The shared byte-budget helpers now come from the actual contacts dependency;
there is no remaining copied helper prerequisite. Strict cursor behavior uses the
same optional shared parameter as the foundation, with old callers retaining
their default. The final task and collaboration squash commits must still be
merged before final exact-head gates.

Focused SQLite file workflows passed 76 tests / 936 assertions before the final
preview additions; the final reader/strict-cursor follow-up passed 26 tests / 171
assertions. MariaDB reader, ancestry, pagination, migration and six real snapshot
races passed 39 tests / 696 assertions, followed by the two native-preview cases
(52 assertions) and native agreement/operations continuation (95 assertions).
Contract and registry checks passed 41 tests / 4,348 assertions. These are
intersecting focused selections, not a full-suite result.

After integrating foundation `6a53d058`, the combined SQLite workflow selection
passed 182 tests / 3,610 assertions across file lifecycle/privacy, graph readers,
comment/search readers, native pagination and Agent byte-budget continuation.
Contract, route, schema, tenant-FK and fillable inventories passed 85 tests /
6,887 assertions. A fresh MariaDB selection passed 17 tests / 671 assertions,
including the six genuine old-snapshot write/replay revocation cases, migration
defaults, shared task-ancestor readers and file pagination. Scoped PHPStan checked
all 38 changed application PHP files
without errors; Pint, TypeScript and 25 focused frontend tests passed. OpenAPI,
MCP snapshots and generated TypeScript regenerated without drift. These remain
focused integration results, not a full-suite or mutation result.

The early hosted run exposed three legacy integration checks. The correction
preserves malformed native agreement terms while withholding every file and
upload capability, prunes Operations to its server-owned billing parent types,
and updates the receipt lock assertion to include current authority facts. The
affected SQLite selection passed 118 tests / 2,993 assertions; the added policy
allowlist test passed 1 test / 9 assertions. The five exact regressions passed
on MariaDB with 110 assertions. Operations now proves equal query counts for
one and four companies, including the two shared-reader authority reads.

Before foundation integration, one local production-asset build and the synthetic
browser harness passed 44 page states at
390, 820, 1440 and 1920 pixels. Each native file family navigated older and newer
pages, including native owner and dual-owner Portal previews; oversized filenames
wrapped without horizontal document overflow. Workspace shell navbars remained
49 pixels high. The pre-existing Operations overview keeps its separate legacy
header; its file pagination worked at all four widths. This is pre-integration
browser evidence; it does not claim a fresh combined build or final-head browser
run after importing task pagination. Repeat the relevant combined checks when a
build slot is scheduled. Full-suite, mutation and final integrated-foundation
checks remain required before release.
