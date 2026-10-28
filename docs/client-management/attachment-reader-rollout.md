# Attachment reader compatibility

This document records the staged reader/privacy compatibility boundary. R1 and
R2 are implemented in PR533/543; the phase table describes deployment order,
not a claim about a particular installation's active flags. The reader artifact
honors the persistent private-default latch across compatible rollback.
Historical blobs and imported copies stay separate.

## Release phases

| Phase                                | Database default | Ordinary uploader                                            | Sharing and topic/comment uploads          |
| ------------------------------------ | ---------------- | ------------------------------------------------------------ | ------------------------------------------ |
| Before R1                            | No file flag     | Existing parent inheritance                                  | Unavailable                                |
| R1 initial reader deployment         | `true`           | Explicit inherited `true`; no latch and config check off     | Rejected before bytes or recovery rows     |
| Verified R1, privacy latch installed | `true`           | Explicit private `false`, including with an old config cache | Still rejected before staging              |
| R2 controls, after verified floor    | `false`          | Explicit private `false`; persistent latch retained          | Separate disabled-by-default controls gate |

R1 keeps every existing lifecycle row true, including files under a currently
private parent and staged/deleting/corrupt rows. An old uploader omitting the new
column therefore retains existing inheritance. The first reader deployment has
no privacy latch and `SVC_ATTACHMENT_PRIVATE_UPLOADS_ENABLED=false`; every active
ordinary uploader explicitly writes true. A supplied visibility field is rejected
rather than ignored, including null or true. No setting admits topic/comment
uploads or sharing controls. Existing Agent cutovers remain additional requirements.

All R1 readers enforce a stored false value independently of writer controls.
Only after the deployed reader floor and its retained rollback artifact are
verified may the operator install the private 0600 marker at
`storage/app/private/attachment-private-default.enabled`. This fixed LOCAL path is
inside the deployment's persistent storage and private backup, independent of the
attachment disk adapter. The application and rollback never create or remove it.
The upload policy reads it at runtime, clearing stat cache, so an old `.env` or
Laravel config cache cannot restore public defaults. A retained entry forces
private defaults, including a broken alias; an unreadable or missing policy
directory refuses uploads instead of guessing public.

`SVC_ATTACHMENT_PRIVATE_UPLOADS_ENABLED=true` is an optional consistency check,
not the durable authority. Without the marker it refuses upload preparation and
publication with 503 before staging bytes or recovery rows; ordinary reads remain
available. With the marker, all ordinary factories explicitly write false even
when that config value is false. The policy is checked before staging and again
when persisting the recovery row. Prepared publication, deletion and repair keep
the stored choice; existing rows are never backfilled private during activation.

Automatic rollback to the verified capable R1 artifact restores service while
new ordinary uploads stay private, because the selected artifact reads the same
retained marker. Before activation, prove this using an old config value of false
and synthetic native, generic Agent and expense uploads. Check private staged
rows, publication, repair and held URLs; record exact deployed/fallback artifact
identities and marker permissions/readback. Local capability tests do not prove a
production fallback has been verified. Never remove the marker or restore a
pre-activation storage backup while serving retained private state.

R2 may change the database default to false only after that floor is verified.
Its separate sharing/new-parent controls gate starts off. Disabling those controls
or restoring an old config cache never defeats the privacy latch. Rollback below
the verified capable R1 artifact is unsupported after activation. The R1 down
migration refuses retained private files, topic/comment parents, or an activated
latch even before its first private row; it never
publishes or reassigns them. Historical blobs stay separate. Activation, marker
installation and deployment are separate operator actions; this code does none.

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
| Native file upload/download/delete            | Existing routes; latch-selected upload defaults, shared held-URL read authorization                                                                 |
| Operator and Portal agreement file lists      | Shared bounded reader; exact Portal or verified native preview; no Portal management                                                     |
| Operations overview file metadata             | Shared authorized query before its result bound                                                                                          |
| Expense receipt screen                        | Existing manager policy plus the shared reader                                                                                           |
| Generic Agent list/get/download URL/content   | Same manager gate and canonical metadata; exact dual Portal disclosure                                                                   |
| Generic Agent upload URL/multipart/delete     | Existing scopes/cutovers; reject unsupported intent before staging, deny dual writes, current locked publication/delete/replay authority |
| Agent expense receipt URL/list/content/upload | Existing separate expense scope/cutover; no dual Portal access; same staging and current authority                                       |
| Repair CLI                                    | Existing preview/apply lifecycle cleanup; preserves stored visibility                                                                    |
| Historical library/blob download              | Separate manager-only history contract, unchanged                                                                                        |

The central `AttachmentStorageService::recordStaged` creates every active native
attachment row and explicitly persists the latch-selected flag: true during
the initial deployment, false after verified activation. R2 now provides
`svc:attachments` through shared actions, exact actor/workspace UUIDs, private
JSON and preview/apply semantics. Preview stages no physical objects and leaves
no recovery rows; a database rollback alone cannot undo staging. See the
[sharing contract](attachment-sharing-contract.md) for its exact operations.
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

The rollback-capable latch follow-up passed 106 focused workflow/contract tests
with 1,147 assertions, scoped PHPStan and Pint. The exact activation/default
selection passed 28 MariaDB cases / 132 assertions, covering all seven native
parents plus both Agent upload families with initial, latched and old-cache
settings. A separate callback probe installs the latch during staging and proves
no public recovery row is created. The latch is installed only in isolated local
test directories; no production activation or verified fallback deployment is
claimed here.

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
