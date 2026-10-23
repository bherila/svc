# Client collaboration execution plan

Planning baseline: `fa6d72a8f34af60386dfc876abc190b0404cb74a`.

## Implementation checkpoint (2026-10-22)

The first native collaboration slice now supplies company/project notes and
discussions, flat comments on both topic kinds and tasks, escaped plain text,
server authorship, optimistic versions and tombstones. Website, REST, MCP and
native CLI share scoped actions; metadata-only activity, authorized text search,
Notes/Discussions navigation and stable comment permalinks are integrated.
Portal clients and dual agent identities remain read-only. Agent writes require
the global cutover and the separate collaboration cutover, which defaults off;
native operator routes and CLI use their current staff permissions.

Transport ceilings remain bounded: MCP request 1 MiB, capability result 2 MiB,
and complete response 4 MiB. REST/MCP comment lists default to one row, with a
configurable maximum of 1–30. Agent REST/MCP and native CLI comment lists use
opaque ascending cursors bound to workspace, company, parent, actor and audience.
Serialized byte bounds can shorten a page; clients follow `meta.next_cursor` or
`next_href` until null, with `previous_href` always null. Offset/focus parameters
are reserved for native thread pages, which retain 30 rows and focused links. Private CLI JSON input is
limited to 1 MiB. Focused checks cover a full 50,000-character Unicode body and
current locked authority under real MariaDB repeatable-read snapshots.

For CLI continuation, `svc:collaboration comments.list` takes the parent UUID in
private JSON `id`, its `parent_type`, and optional `payload.limit` and
`payload.cursor`. It returns the same opaque `meta.next_cursor` as REST/MCP.

This checkpoint does not complete the later task graph or attachment-sharing
slices below. Add their shared readers and parent guards before their writers;
register no topic/comment file parents until attachment authorization is
integrated. Existing file disclosure and grants remain intact. Portal write
opt-in remains a separate unanswered product decision, and no new client write
grant or collaboration email delivery is inferred.

## Outcome

Make SVC useful for everyday project work: assign and organize tasks, hold
conversations, maintain notes, share selected content with clients, and operate
the same features through the website, REST, MCP and CLI. Extend the existing
**Visible to client** checkbox rather than introducing a general ACL editor.

Historical source records remain immutable history. This work neither converts
them into active tasks or collectible invoices nor reactivates people or grants.

## Existing seams

- `WorkspaceTaskMutationAction` already owns task creation, updates, completion,
  billing maintenance protection and task locking for website and agent writes.
- `ProjectAccess` owns internal project roles. `PortalAccess` owns the separate
  external company/project grants. Assignment and business contacts grant neither.
- `ClientContactRepository` and `ContactDetails` already support business contacts
  independently of authenticating users. Company CRUD already exists in the API;
  its newer profile fields and business-contact operations need a parity audit.
- `AttachmentStorageService` and `AttachmentAction` own staged publication,
  integrity checks, versioned mutations and crash recovery. Reuse them.
- `WorkspaceSearch` owns the command palette. Keep search in MariaDB, with
  authorization applied before counts, pagination, snippets and result limits.
- There is no production queue worker. Any notification work must use an explicit
  durable delivery record and synchronous execution after commit.

## Shared disclosure and permission contract

The checkbox answers whether content may be disclosed to an authorized client.
It does not create membership, add a project grant, permit editing, or send mail.

| Content                                               | Client disclosure rule                                                                                      |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Existing project/task/proposal/agreement/invoice/time | Preserve current behavior and documented lifecycle rules                                                    |
| Project note or discussion                            | Current company/project grant, visible project, own checkbox                                                |
| Company-wide note or discussion                       | Current company-wide portal grant and own checkbox; one project grant is insufficient                       |
| Task comment                                          | Current grant, visible project, task and its task/list ancestors, own checkbox                              |
| Topic comment                                         | Current grant, visible topic and its applicable ancestors, own checkbox                                     |
| New attachment                                        | Same parent authorization plus its own checkbox when file-level sharing is introduced                       |
| Task lists and subtasks                               | A private containing list or task ancestor hides its descendants from clients                               |
| Labels and assignee metadata                          | Present only fields authorized for the audience; never disclose a hidden related record's title or identity |

New notes, discussions, staff comments and ordinary task creation default to
internal. Omitted visibility on update means unchanged. Existing project creation,
proposal sending, agreement acceptance, invoice issuing and client-facing time
defaults retain their current semantics. In particular, approved visible time has
its existing project-grant rule; do not globally require project visibility in
`PortalAccess::constrainProjectQuery` and accidentally change billing/time reads.

All audience decisions are server-owned, shared by lists, detail, embedded views,
search, totals, capability metadata, file metadata and downloads. Hiding a parent
or revoking access must affect the next read, including a previously obtained URL.
Authorizing a parent cannot be replaced with generic workspace membership.

Internal project owners/managers manage tasks and topics; contributors can comment
where they currently have access. Authors can edit their own content while they
still have the required write access. Managers can moderate with an audit event,
without silently changing another person's authorship. Visibility changes require
publishing authority, not merely authorship. Assignment never grants access.
Only workspace owners/admins manage and publish company-wide topics. Project
membership alone grants no internal access to their bodies, comments or files.

Portal content remains read-only until an explicit collaboration grant is enabled.
The pending product choice is opt-in replies versus replies plus task updates.
Either choice preserves all existing grants by default. Client authors cannot
publish internal staff content or change visibility. Their own replies are visible
to the authorized client audience and require a currently visible parent.

The server selects and validates the audience in a shared read context. Portal
routes always use the portal audience. For REST/MCP dual internal/portal identities,
preserve the existing task API's portal disclosure restrictions. A caller-supplied
audience cannot grant internal authority. Operator routes require their own
internal policy; do not accidentally expose private tasks through portal/API reads.

## Delivery order

### 0. Close existing authorization gaps

Implement a small, independently reviewable fix before merging new parent types:

1. Generic web attachment downloads currently accept any workspace viewer before
   authorizing the parent. Resolve project/task/financial parents through the same
   policies as their detail endpoints. Company-wide files remain manager scope
   for internal members who only reach selected projects.
2. Portal invoice files must reuse `PortalInvoiceQuery`'s full attributed-project
   rule, including agreement attribution, instead of company membership alone.
3. Context capability metadata must expose only projects in the caller's actual
   portal grants, matching project list/get behavior.

These are code-path-confirmed findings; first add synthetic regression tests to
demonstrate each failure before changing it. Do not broaden the deliberately
manager-only generic agent attachment operations as an incidental part of the fix.

Exit: held-file URL, mixed-project invoice and context regressions pass; existing
authorized manager/member/portal controls still succeed on SQLite and MariaDB.

### 1. Freeze interfaces and start three independent domain lanes

Freeze DTO names, public identifiers, audience/access context, route names,
presented capabilities, version semantics, parent type names and migration order.
No agent independently edits shared registries or generated contracts.

**Task lane: foundation.** Keep native task IDs and the current lifecycle. Add
immutable create/patch DTOs and a scoped repository, nullable calendar start/due
dates, bounded assignees, and the task detail/editor needed to use them. Validate
dates after merging the patch: omission preserves a field and explicit null clears
it. Use current eligible account memberships/grants for account assignment;
business-contact responsibility remains a distinct typed association and cannot
activate an account. Archived contacts cannot receive new responsibilities.

Preserve billing maintenance and existing invoice claims. Metadata changes must
not complete tasks, change prices, generate invoices or rebill completed work.
Specify transition-only completion timestamps and test existing billing-period
behavior before changing the current completion action.
Task project/workspace ownership remains immutable, including tasks with time or
milestone links. List/subtask moves stay within that project. No new task hard
delete operation is introduced.

**Collaboration lane: foundation.** Add native topic and comment tables:

- `client_collaboration_topics`: immutable kind `note|discussion`, workspace,
  company, optional project, title/body, author identity and display snapshot,
  visibility, revision and edit/delete metadata.
- `client_comments`: validated workspace/company/project context, exactly one
  task or topic parent, body, author, visibility, revision and edit/delete metadata.

Notes are editable reference documents; discussions are titled conversations.
Both can use the same flat comment component, as can tasks. Avoid recursive reply
trees and unchecked polymorphic parent strings. Lineage and authorship are set by
the server and remain immutable. Use composite tenant keys and service checks for
the full project/company chain. Soft deletion leaves an authorized tombstone while
excluding body and file access from ordinary reads and search.

Start with plain text consistently in all transports, displayed as escaped text
with preserved whitespace and wrapping. Retain a usable full-body editor and
thread pagination. Rich-text parsing is a later explicit feature.

**Contacts/integration lane.** Reuse native contact DTOs/repositories for complete
business-contact list/get/create/update/archive operations. Audit company profile
field parity. Keep these operations distinct from authenticated portal contacts,
invitations and access grants. Match the current manager-only web scope for
sensitive contact fields; do not infer account links from equal email addresses.

Each lane supplies a runnable vertical slice and tests. Domain-only commits may
be stacked during development; a feature is ready only when its UI, authorization
and required transport contracts work together.

### 2. Organize tasks and apply sharing throughout collaboration

- Add project-owned task lists, workspace labels, same-project subtasks and
  directed dependencies. Task parenthood is a bounded tree; dependencies form a
  separately validated DAG. Reject self edges, duplicate edges and cycles.
- Preserve task statuses; derive a blocked condition from unfinished dependencies.
  Blocked and incomplete-child states are advisory initially: an authorized person
  can explicitly complete a task after seeing them. Never automatically complete
  or bill descendants or silently enforce a different rule in one transport.
- A subtask inherits its root task's list; it has no independent conflicting list.
  Moving a root between lists updates its tree's organization atomically. Bound
  depth, graph operations and reorder sizes in the shared validation contract.
- Reordering is bounded and atomic, with an expected container revision and an
  explicit complete sibling order. Reject duplicates, foreign or omitted IDs.
- Related-row changes advance the task/container revision, including pivot-only
  assignment, label and dependency changes. Require stale-version protection on
  web forms as well as external mutations.
- Serialize graph edits before reading edges for cycle checks. The existing lock
  order has task locks before project locks: choose a registered graph lock before
  sorted task IDs rather than adding an inverted project-then-task lock sequence.
  Prove opposite-edge races and assignment-versus-revocation with two MariaDB
  connections, not only static lock-order tests.
- Assignment mutations follow the reviewed order: receipt reservation, billing
  maintenance share, workspace access lock shared with grant revocation, registered
  graph/container lock when needed, ascending task IDs, current membership/grant
  rows. Allocate graph-lock registry order after Workspace and before ClientTask.
  Parent/list moves and reorder use the same graph serialization domain as edge
  edits. Compute effective visibility from current ancestors rather than persisting
  derived subtree flags; a simple flag toggle needs no artificial subtree rewrite.
- Integrate parent policies with notes, discussions, comments and task trees.
  Present an effective sharing state when a checked child is hidden by a parent;
  do not silently publish the parent or rewrite every child's stored flag.
- Reuse private attachment staging and recovery. Register topic/comment parent
  types in resolver/storage allowlists and the REST/MCP schemas, authorize all
  metadata and bytes, batch files for a page of comments, and bound listings.
  Prepared uploads recheck access and parent existence at publication.
- Introduce file-level sharing as a separate explicit slice: retain existing
  native attachments as `is_visible_to_client=true`, even under currently private
  parents, preserving their parent-inherited disclosure when a parent later changes.
  Historical blobs stay separate. Deliver the following reader release before
  enabling any private-file or new-parent writers:
    - **R1 reader release:** add the non-null column with database default **true**
      and backfill every existing lifecycle row true, including staged, deleting
      and corrupt rows. The database default must remain true while an older
      uploader binary can omit the column. Upgraded ordinary upload paths explicitly
      write true while the persistent privacy latch is absent and the optional
      `SVC_ATTACHMENT_PRIVATE_UPLOADS_ENABLED` check remains initially false. Reject a supplied
      private flag or topic/comment upload before staging bytes or inserting a
      recovery row. Separate readable parent types from uploadable parent types;
      synthetic reader coverage does not enable uploads. No sharing controls or
      new-parent upload operations are available in this release.
    - All R1 listing, metadata and download paths enforce the stored file flag and
      the full current parent/grant/ancestry policy, including held URLs. Readers
      enforce a stored false value even while the writer flag is off. Preserve
      manager-only generic agent file operations, separate expense restrictions
      and the historical blob library. Apply authorization before counts, limits,
      filenames or snippets; use the shared task ancestor/root-list predicate for
      task files and comments. A file checkbox only narrows its parent's audience.
    - **Verified R1 private defaults:** only after the deployed reader and its
      retained rollback artifact are verified, install/read back the private 0600
      marker `storage/app/private/attachment-private-default.enabled`. This fixed
      LOCAL marker lives in persistent storage and private backups. The upload
      factory reads it at runtime; a retained marker forces private defaults even
      with an old false config cache. Requests and rollback never remove it. The
      optional true `SVC_ATTACHMENT_PRIVATE_UPLOADS_ENABLED` consistency check
      refuses uploads without a marker before staging; ordinary reads stay up.
      Check before staging and again at recovery-row creation. Publication and
      repair preserve stored flags, and old rows stay unchanged.
    - **R2 writer release:** after that floor and private-default setting are verified,
      change the database default to **false**. Keep the persistent marker in
      every release, rollback and private backup. A separate domain flag enables explicit sharing
      controls and topic/comment uploads across native, first-party, agent and CLI
      writes; existing agent cutovers do not replace it. Turning those controls off
      preserves existing private rows and keeps future ordinary uploads private.
      The deployed reader/writer phase determines the upload default; toggling a
      controls flag never makes uploads more public. Do not roll back below R1 after
      private or new-parent
      rows exist; a schema rollback must refuse retained incompatible rows rather
      than publishing them. Prepared uploads reauthorize their current parent and
      actor at publication, and receipt replay rechecks current authority.

    - **Automatic rollback contract:** the verified capable R1 artifact reads the
      same retained marker, restores service and keeps new ordinary uploads private.
      Prove that factory with a false old config value; do not depend on cached
      environment/config state or leaving the service in maintenance. Record exact
      deployed/fallback identities and marker permissions/readback, and test native,
      generic Agent and expense upload/recovery paths. Never remove the marker or
      restore a pre-activation storage backup while serving retained private state.
      Marker installation and activation remain separate operator actions after
      reader verification; no deployment or activation is part of this code.

    Reader compatibility tests must exercise an older uploader that omits the
    column, all upgraded upload adapters throughout R1, and zero staged
    rows or objects after rejected private/new-parent uploads. Test current grants,
    hidden/deleted ancestors, exact portal grants for dual identities, cross-tenant
    lineage and held URLs across metadata and bytes. Prove bounded query counts and
    actual database defaults on SQLite and MariaDB before enabling R2 writers.

### 3. Finish workflows and all transports

Add task filters, editor/detail, list/subtask organization and dependency controls;
notes/discussions pages; task/topic comment threads and file controls. Render each
authenticated page through `WorkspaceShell`, consume server-generated URLs and
capabilities, and use the existing checkbox label. Client pages receive only
authorized presentation DTOs, not raw private fields.

For every delivered operation, website, REST, MCP and CLI call the same scoped
action. Agent writes retain mutation receipts, canonical request digests, current
authorization, expected versions and opaque public IDs. CLI writes follow existing
preview/`--apply` conventions. Root integrates the operation catalog, scopes,
routes, OpenAPI, generated types and conformance snapshots. Do not describe a
feature as complete while an advertised transport still lacks it.

Extend unified MariaDB search for notes, discussions and permitted comments,
landing on exact task/topic/comment URLs. Search filters authorization before
snippets/counts/pagination. Record metadata-only activity events for creation,
edits, moderation and sharing changes. Do not put bodies or private filenames
into an activity payload or turn historical delivery records into notifications.
Notification email is a separately tested slice if enabled: persist delivery
intent in the transaction, send after commit, recheck recipient access, deduplicate
retries and retain delivery failures without rolling back committed content.

## Worktrees and exclusive ownership

All initial worktrees start from the planning baseline. Rebase dependent slices
onto reviewed prerequisite commits; merge in dependency order, not completion order.

| Lane             | Worktree / branch                                                | Owned implementation                                                                                         |
| ---------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Coordinator      | `svc-collaboration-plan` / `plan/client-collaboration-execution` | Plan, shared routes/catalog/scopes, generated artifacts, integration, contacts parity initially              |
| Visibility/files | `svc-client-visibility` / `feat/client-visibility`               | Parent authorization, capability filtering, attachment resolver/controller/storage, focused permission tests |
| Tasks            | `svc-task-management` / `feat/task-management`                   | Task DTO/repository/action/model/migrations, organization, task components and focused tests                 |
| Collaboration    | `svc-project-collaboration` / `feat/project-collaboration`       | New topic/comment models/migrations/actions/controllers/components and focused tests                         |

Reserve `AgentReadService`, directory/portal controller integration,
`WorkspaceSearch`, activity registration, lock resource registry, tenant reference
inventory, route files, MCP tool adapters/schemas and operation/generated contracts
to the coordinator.
Workers supply narrow integration patches and conformance cases for these files.
Visibility owns the shared parent resolver; other lanes consume its agreed
interface. Migration timestamps and required migration order are allocated before
edits. Never change another worker's worktree or shared local database.

Wave A: visibility regressions, task foundation and native topic/comment foundation
can be developed concurrently after interfaces are fixed. The coordinator handles
contact parity and integration. Wave B: task graph/organization, collaboration UI,
and file/sharing integration. Wave C: search, transport conformance and integrated
browser checks. Reuse worker slots for later slices instead of starting more than
three workers alongside the coordinator.
Client-facing topic/comment routes, lists, embedded payloads and uploads cannot
merge before shared authorization and company-wide/dual-identity rules are wired.
Preparatory schema/domain work can develop concurrently without publishing those
new surfaces prematurely.

## Validation and release gates

Use synthetic fixtures only and an isolated local database/runtime per worktree.
Run focused tests while coding. Do not run three full suites simultaneously on the
same host: keep four ParaTest workers per PHP suite and at least 1 GiB per worker;
schedule full PHP/mutation runs centrally after checking actual host headroom.
Independent hosted lanes remain concurrent.

Required conformance matrix: manager, project manager, contributor, viewer,
company-wide portal, selected-project portal, zero grants, revoked grants, dual
identity, agent; two tenants/companies and visible/private/granted/ungranted
parents. Verify both positive and negative outcomes on detail, lists, counts,
search, capabilities, metadata and held download URLs.

Cover malformed/cross-tenant ancestry, hidden parent with checked child, author
spoofing, stale/replayed writes after revocation, optimistic conflicts, deletion,
prepared upload races, graph races, bounded queries and pagination completeness.
Pin milestone billing and file crash recovery. No historical row, source snapshot,
native billing amount or access grant changes as a feature-development side effect.

Before each publication, pass focused PHP/frontend tests, Pint, PHPStan, TypeScript,
ESLint/format, generated API checks and disclosure scan. Push the tested candidate
so hosted checks run while the complete local suite and mutation-diff finish.
Merge only after required local and hosted gates pass for that exact commit.

Exercise new operator and portal screens at 390, 820, 1440 and 1920 pixels with
oversized names/bodies/URLs/files, many labels/assignees, nested tasks, empty and
busy threads, hidden ancestors and error states. Assert one-row navigation/no
document overflow and read all screenshots. Refresh the main baseline before
final review; rerun mutation-diff for fixes.

Production rollout remains the normal guarded atomic deployment. After each
vertical slice, independently verify the served commit, sign-in, current billing,
historical search/files, scheduler and absence of maintenance/pending state. Test
new writes with synthetic fixtures locally, not production customer records.

Privacy-affecting schema changes require a compatible rollback reader before
enabling writers. Deploy additive task ancestry/file-visibility schema and all
read guards first, with UI/API writes to the new sharing states unavailable.
Existing file rows and ordinary legacy uploads initially retain inherited sharing.
Verify that release, then enable hierarchy/sharing mutations and new-upload defaults
in the next release. Its rollback target must already enforce private ancestors and
file flags. Reverting to code that ignores newly stored privacy state is not a safe
rollback. Prove this with negative fixtures against the prerequisite reader and
record the minimum compatible release in the implementation/release notes. Migrations
and backfills remain additive; normal application rollback must not drop new data.

## Done

Every promised slice has a usable UI, one shared domain action, scoped reads and
writes, version/receipt behavior, advertised transport parity, synthetic isolation
and concurrency tests, browser evidence and successful deployment readback.
History remains immutable, existing billing is correct, and sharing is enforced
across every way a record or file can be reached.
