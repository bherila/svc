# R2 attachment sharing contract

Status: proposed interface freeze, based on reader `01d56d39`. This document
authorizes no activation or production change. It implements the R2 phase in
[the execution plan](collaboration-execution-plan.md) after the deployed reader
and retained fallback have been verified as described in
[the reader rollout](attachment-reader-rollout.md).

## Release and compatibility boundary

The reader remains authoritative regardless of any writer switch: a file's
stored checkbox can only narrow the current parent audience. Workspace,
company/project lineage, exact Portal grants, task/list ancestry, tombstones,
lifecycle and the file checkbox precede counts, limits, names and bytes.
Turning R2 off never makes a private file public or withholds existing reads.

Add `svc.attachments.sharing_writes_enabled`, from
`SVC_ATTACHMENT_SHARING_WRITES_ENABLED=false`. This is a strict product
availability condition, outside Agent write cutovers, with no first-party
bypass. Require the retained runtime privacy latch for every R2 writer as well;
configuration true without the marker refuses before staging. Existing Agent
file/expense/collaboration cutovers remain additional requirements for Agent
writers. Native/CLI authority is selected by the server, never an input field.

| Surface                                                  | R2 switch off                                               | R2 switch on, retained latch present                        |
| -------------------------------------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------- |
| Existing seven native upload parents                     | Existing route/policy and latch-selected private default    | Same contract                                               |
| Existing six generic Agent upload parents                | Existing manager gate, scopes, cutovers and private default | Same contract                                               |
| Separate Agent expense receipts                          | Existing expense gate/scopes and private default            | Same contract                                               |
| Any existing upload with supplied file visibility        | Reject, including null/true                                 | Reject; sharing is a separate operation                     |
| Explicit file sharing and new topic/comment uploads      | Unavailable before bytes/recovery rows                      | Current authorized publisher, versions and receipt required |
| Existing metadata/content/held URLs and lifecycle repair | Available with current reader authorization                 | Same contract                                               |

The seven legacy native parents are company, project, task, proposal, agreement,
invoice and expense. The six generic Agent upload parents exclude expense.
Do not make `AttachmentRecordResolver::uploadableTypes()` flag-dependent or
broaden either legacy upload enum. Add a separate closed topic/comment upload
allowlist and a dedicated adapter. Existing generic Agent list/get/download/
upload/delete remain workspace-manager-only, including their current dual
identity rules.

All new uploads are private first, with no visibility input. An explicit later
checkbox mutation can publish an available file. Retain every existing stored
flag, including true files under currently private parents and every recovery
lifecycle. Historical blobs/imports remain outside active attachment sharing.
Expense receipts have no Portal parent: retain staff-only readers and offer no
client-sharing control for them.

Reserve additive migration `2026_10_22_180210_use_private_attachment_default`:
change only the database default to false, with no row backfill or drop. The
deployment order requires the verified reader/fallback and retained marker
before this migration; a guard must refuse an absent/inaccessible marker rather
than infer readiness from cached settings. Normal application rollback retains
the schema/data. Its down path refuses while the latch or private/new-parent
state is retained, before DDL. Keep R1's stronger down barrier intact.

The application never installs, removes or relocates the latch. R1 rollback
must continue ordinary uploads with stale config false by reading the same
persistent marker. R2 cannot solve rollback by disabling uploads or depending
on a cached setting. The optional existing private-default consistency check
is independent of the new controls flag.

## Authority, locking and persistence

Use one immutable `AttachmentVisibilityChange` containing the explicit boolean,
`expected_version` for the file, and `expected_parent_version`. All three are
required; false is valid, null/empty/whitespace/omission are invalid. Neither a
route identity nor origin/audience may be supplied inside this DTO. Use a closed
`AttachmentParentReference` for public type/UUID values and an explicit
`AttachmentMutationOrigin::{Native,Agent}` selected by the adapter. CLI selects
Native through its trusted exact-user lookup.

Add `AttachmentSharingAction` and a tenant-scoped
`AttachmentSharingRepository`; the action never accepts an arbitrary Eloquent
patch. Add `AttachmentPublicationAuthority` for current parent facts, reusing
existing collaboration/task repositories and access facts. Proposed entrypoints:

- `setVisibility(actor, workspace, attachmentId, change, origin, parentScope)`;
- `prepareUpload(actor, workspace, company, parent, file, expectedParentVersion, origin)`;
- `publishPrepared(...)` and `assertReplayAllowed(...)` under the same authority;
- new-parent deletion delegates existing lifecycle deletion after the same
  scoped current authority, explicit confirmation and file/parent versions.

`parentScope` is server-owned: legacy generic files require current workspace
owner/admin authority; scoped collaboration parents require current
`CollaborationAccessFacts::canPublish()`. Company-wide topics/comments therefore
require a workspace manager; project topics/comments allow a current project
owner/manager. Comment authorship, contributor status, assignment and the right
to comment alone never grant file publication. This first R2 slice uses that
publisher requirement for private uploads/deletes too; it introduces no new
contributor or Portal write permission. Sharing applies to any author's file
when the caller has publishing authority; file bytes and filename stay immutable.

Native staff and trusted CLI retain independent internal policy, including dual
staff identities. Every Agent/REST/MCP caller with any current scoped Portal
membership is refused a write, including dual owners and first-party REST.
Actual Portal/PortalPreview routes never expose write capabilities. Receipt
replay repeats current authorization and live lineage checks; a receipt does
not revive authority after demotion, grant changes or tombstoning.

Before staging, validate the product/latch policy, closed payload, full lineage,
live parent and current publishing authority. Release those preflight locks
before object I/O. Immediately before publication, acquire the receipt and
existing registered locks, reload current parents/access rows, compare the
parent version, and lock the staged file last. If authority/lineage changed
after staging, publish nothing and discard only unused preparation; cleanup
failure retains a non-readable recovery row and keys for repair.

Preserve the existing rank order: receipt, BillingMaintenance, early financial
parents when applicable, Workspace, task/topic parent, comment, company/project,
current staff/project/Portal membership rows, then ClientAttachment. Workspace
share locks serialize with existing exclusive access/graph writers. For comments,
lock their validated task/topic before the comment and then the project. Do not
lock a file first and discover its parent afterward. Do not use ordinary company
subqueries to discover current Portal identity inside an old MariaDB snapshot.
Record exact sequences in the existing lock inventory; add no new lock resource.

For sharing, lock the available file and its exact parent, verify both opaque
versions, and update only the flag, file revision and timestamp through a scoped
compare-and-swap repository statement. Leave every other immutable attribute
guard in `ClientAttachment` intact; do not globally permit model mass updates of
the flag. Every accepted new receipt key advances the file once, including a
same-value request; parent content/version is unchanged. Replay stores/reuses
the file ID, makes no second change, reauthorizes, and presents currently
authorized metadata rather than stale serialized receipt content. Changed body
or bytes under the same key gives 409.

A checked file under a hidden project/list/task/topic/comment stays effectively
hidden. Show the reason; never publish the parent or rewrite descendants. A
later authorized parent publication may expose files whose stored choice is
already true. Deleted/staged/corrupt files cannot be shared. Repair, publication
and deletion preserve flags; only the explicit action changes them.

## Proposed transport freeze

Root owns registration, generated schemas/types and inventory integration. The
following names are proposals to freeze before source implementation.

| Operation                                     | Binding                                                             | Scope/policy                                                                       |
| --------------------------------------------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `attachments.visibility.update`               | PATCH `/workspaces/{workspace}/attachments/{attachment}/visibility` | `files:read`, `files:write`; six legacy generic parents, manager-only              |
| `collaboration.attachments.list`              | GET scoped parent base below                                        | `files:read`, `collaboration:read`; current parent reader                          |
| `collaboration.attachments.download_url`      | POST scoped base `/{attachment}/download-url`                       | Same read scopes; current file reader                                              |
| `collaboration.attachments.download`          | GET scoped base `/{attachment}/content`                             | Same; binary REST only, held URL rechecks authority                                |
| `collaboration.attachments.upload_url`        | POST scoped base `/upload-url`                                      | `files:write`, `collaboration:write`; current publisher; read-only URL preparation |
| `collaboration.attachments.upload`            | POST scoped base `/upload`                                          | Same; multipart REST only, current publisher at publication                        |
| `collaboration.attachments.visibility.update` | PATCH scoped base `/{attachment}/visibility`                        | `files:read`, `files:write`, `collaboration:write`; current publisher              |
| `collaboration.attachments.delete`            | DELETE scoped base `/{attachment}`                                  | `files:write`, `collaboration:write`; current publisher plus confirm               |

Scoped base:
`/workspaces/{workspace}/clients/{client}/collaboration/attachments/{recordType}/{recordPublicId}`,
with `recordType` exactly topic or comment. Every file query also verifies its
parent type/UUID and company/project lineage, including download and replay.
The route IDs are excluded from request bodies; extra identity/body/origin keys
are rejected even when they agree with the route.

There are eight new REST bindings and six new MCP tools; binary download and
multipart upload remain REST-only. `AgentMcpAttachmentSharingTools` uses the same
REST dispatcher and a concrete factory instance. Use authenticated ten-minute
prepared URLs, never inline blobs. URL preparation has no receipt or durable
side effect; multipart publication, sharing and deletion require an
Idempotency-Key. Recheck the product flag for held upload URLs. Read URLs never
depend on the writer switch. Existing generic/expense operation contracts remain
unchanged.

Use `AgentDeploymentFlags::ATTACHMENT_SHARING` as a non-bypassable availability
condition on the five new writer bindings (including upload URL preparation),
and existing file/collaboration Agent cutovers as additional Agent conditions.
Keep the three new readers usable with the flag off; they enforce R1 reader
policies, including exact Portal-first disclosure for dual Agent identities.
Do not mark the scoped collaboration operations blanket manager-only.

Proposed closed schema names: `AttachmentVisibilityRequest`,
`AttachmentSharingState`, `AttachmentVisibilityResponse`,
`CollaborationAttachmentListResponse`, `CollaborationAttachmentUploadRequest`,
`CollaborationAttachmentUploadUrlResponse`, `CollaborationAttachmentResponse`,
`CollaborationAttachmentDeleteRequest`. Reuse canonical Attachment scalar fields
and Version/Uuid definitions. Upload requires `file` and `expected_version`
(the immediate parent version). Sharing uses the three DTO fields above;
deletion adds true-only `confirm` and both file/parent versions. Omitted visibility
on every upload means private, never an implicit publication. No new OAuth scope.

Native `AttachmentSharingController` exposes JSON sharing at
`svc.files.visibility.update` and dedicated company-scoped topic/comment
list/upload/delete routes. Preserve legacy seven-parent form routes and their
redirect behavior. Native uses Idempotency-Key headers; it never routes through
the Agent dual-identity policy. Keep different controller methods for different
operations so Wayfinder does not generate duplicate method/URI keys.

## Native DTO, UI and CLI

The reusable file panel gets finished list/download/upload/delete/sharing URLs
from the server. Browser code assembles no URLs or inferred permissions.
`AttachmentSharingPresenter` adds nullable `sharing` to an authorized file row:

```text
sharing: null | {
  is_visible_to_client: boolean,
  effective_visible_to_client: boolean,
  sharing_note: string | null,
  version: string,
  parent_version: string,
  update_href: string
}
```

It is non-null only for the current native publisher, available file, supported
parent and enabled controls. A separate nullable upload capability supplies the
finished URL and parent version. Read-only viewers, Portal/PortalPreview and
expense receipts get no sharing control. Labels use “Visible to client” with
server-derived hidden-parent explanations. Save only an explicit checkbox
request; hold a stable key for an identical lost-answer retry; close a captured
editor before reloading stale versions. Do not auto-toggle the parent.

Agent topic/comment embeddings must retain their existing `collaboration:read`
contract: attach no file names, counts or preview metadata without current
`files:read` scope as well. Send a nullable/absent file capability when that scope
is missing; checking only the later download endpoint would leak metadata.
Native and trusted CLI use their corresponding internal reader audience, while
Portal embeddings use exact grants plus both parent/file flags. Never reuse an
internally loaded attachment relation to present Portal or dual Agent metadata.

Use one `components/files/attachment-sharing-panel.tsx` on existing agreement/
proposal/invoice and Operations file surfaces and on collaboration topic/thread
pages. Existing company/project/task upload API compatibility remains covered;
add a bounded parent file view where an existing native screen lacks a file
panel, rather than widening every unrelated detail payload. Comment-page file
previews load in one bounded batch for the authorized comment page, at most three
files per comment plus finished continuation links. Remaining files are readable
through the dedicated parent page; no silently truncated filename or hidden
file count. Apply both row and AgentPageBudget byte limits before response
serialization, including outer parent metadata and MCP's actual SDK representation.

Add `Console/Commands/Attachments/AttachmentsCommand` with primary
`svc:attachments`, using exact public workspace/actor UUIDs and owner-only JSON
or stdin. Proposed operations: list, get, upload, visibility.update and delete.
Uploads accept a local source path inside private JSON, never bytes or a private
path in process arguments. Native authority and shared DTO/actions/receipts apply.
Default preview validates authorization/versions/file facts but calls no staging,
promotion or object write; DB rollback alone is not an upload preview. Only
explicit `--apply` enters durable upload/mutation paths. Reads/preview create no
objects, graph states, recovery rows, receipts or audit. Repair CLI is unchanged.

## Implementation ownership and exit proof

File lane owns new DTO/repository/action/authority/presenter/gate, narrow model/
storage/resolver policy changes, additive default migration, adapters, reusable
file UI and focused tests. Root owns shared route/catalog/schema/scopes/MCP
factory/generated artifacts/inventories. Collaboration presenter/page integration
and task-reader seams are coordinated before edits; reuse their policies rather
than fork ancestry or grants. No source work begins until root freezes this
contract, and activation remains a separate future operator action.

Implement in three reviewable checkpoints: domain/policy/default migration and
regressions; additive native/REST/MCP/CLI adapters with real route contracts;
bounded UI/comment previews and four-width browser evidence. Required tests:

- SQLite and MariaDB actual defaults; unchanged old true rows in all lifecycles;
  old omitted-column inserts; every legacy native/Agent/expense factory private
  with the latch, both controls settings and stale false configuration.
- Flag/latch off, malformed/private-field input, foreign lineage, tombstones,
  unsupported expense sharing and Portal/dual Agent requests reject before
  preparation; no objects/recovery rows or successful receipts.
- Positive native dual manager, project publisher, company manager and exact
  Portal reader controls; contributor/comment-author/assignment never grants
  publication. API first-party cannot bypass product or Agent-origin policy.
- Parent/file stale versions, same-value new key, concurrent opposite checkbox
  requests, body-ID substitution, exact replay and changed-body/bytes conflict.
  Real MariaDB old-RR snapshots plus committed new-company Portal grant and
  demotion before publication/delete/sharing/replay must deny without disclosure.
- Held URLs, parent/list/task visibility, depth/cycles, corrupt lineage and
  tombstones deny metadata and bytes after changes; checkbox cannot broaden a
  parent; checked-child hidden reason and later parent publication are explicit.
- Bounded SQL/query counts for one/many comments/files, all IDs across byte-only
  continuation, hostile Unicode/escaped filenames, actual SDK result/response
  ceilings, no filename loss or hidden-related counts. Authorization precedes
  counts/limits; every adapter uses the same reader.
- CLI preview row/object fingerprints unchanged, apply parity, exact actor and
  private input enforcement; prepared cleanup/repair and capable-R1 rollback
  remain available with marker retained and old cached settings.
- Closed OpenAPI/MCP schemas, exact inventories, six actual tools/eight REST
  bindings, native server URLs, stable retry/stale UI behavior; browser at
  390/820/1440/1920 with individual screenshot review. Run full/static/frontend/
  mutation gates only in the coordinator's scheduled slots, then require exact
  local and hosted candidate results before merge. No activation is a test result.
