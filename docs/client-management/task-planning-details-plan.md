# Task labels, dependencies and business-contact responsibility

Approved design, implemented in PR544 after the organization and combined-reader
prerequisites merged. The historical design baseline was organization writer
`87e6b69f`, containing main `0cd6`. The
[execution plan](collaboration-execution-plan.md) records delivered scope; this
file retains the design and its invariants.

## Existing boundaries

- `TaskOrganizationAction::lock()` and `TaskOrganizationRepository::load()` take
  billing maintenance, Workspace, GraphState, sorted tasks/lists, then current
  access facts. GraphState is the common serialization point for topology edits.
- `TaskGraph` bounds organization to 1,000 tasks, 100 retained lists and 100
  siblings. Dependencies are a separate graph; they do not change parenthood.
- `WorkspaceTaskMutationAction::update()` changes completion timestamps only on
  status transitions and advances task revisions on assignment-only updates.
- `TaskPlanningPresenter` is explicitly internal. Portal and dual-identity Agent
  callers do not receive its planning fields.
- `AgentClientContactReadService::company()` limits business contacts to workspace
  owners/admins. Responsibility must preserve that boundary, including names,
  identifiers and counts. Contacts are not authenticating users.
- Organization's additive `TaskCompletionAdvisory` work is owned by that lane.
  This lane extends its facts for dependencies after integration; it does not
  create another completion workflow.

## Product and permission contract

Labels are workspace-owned reusable names, with at most 20 labels per task.
Owners/admins create, rename, archive
and restore the catalog. Current internal project owners/managers attach active
labels to their tasks. Their project-scoped picker can use the active workspace
catalog; other authorized internal task readers see only labels already attached
to tasks they can read. Portal audiences receive no label metadata initially.

A directed edge `task -> prerequisite` means the task depends on that prerequisite.
Both endpoints must be existing tasks in the same workspace and project. Reject
self edges, duplicate inputs, missing/foreign endpoints and every cycle. Parent
edges and dependency edges are distinct: no dependency changes task/list ancestry,
visibility, dates, assignees or ordering. Cross-tree dependencies in one project
are valid if the dependency graph remains acyclic.

Use complete replacement of one task's outgoing prerequisite set: at most 25
prerequisites, at most 5,000 total project edges, and the existing 1,000-task/
100-list organization bounds for structural dependency writes. Reject excess
explicitly before truncation. Label/contact replacement remains available on
larger legacy projects because it needs only the target task, not the full graph.

`blocked` is derived from direct prerequisites whose status is not `completed`.
Incomplete prerequisites and children produce advisory text only. Native editor,
REST/MCP reads and trusted CLI preview use the same advisory facts. Explicitly
completing a task remains permitted; there is no new status, acknowledgment gate,
automatic completion, recursive update, invoice generation or rebilling.

Business-contact responsibility is a separate association from account assignees.
Allow at most 10 contacts from the task project's exact client company. Only
workspace owners/admins can inspect or replace it initially. New additions must
be active; already-recorded archived contacts may remain unchanged or be removed,
but cannot be re-added after removal. Contact archival neither removes retained
responsibilities nor changes tasks, history, accounts, invitations or grants.
Never infer account relationships from email equality. Portal account assignment
is outside this slice.

## Additive schema proposal

The isolated persistence checkpoint uses `180300`, `180310`, `180320`, avoiding
organization `180160` and files `180200`/`180210`. It is not a published feature.
`TASK_PLANNING_DETAILS_ENABLED=false` independently gates every new action and
the unadvertised receipt adapter; enabling organization alone does not enable
labels, dependencies or contact responsibility. Existing readers remain active.

| Table | Fields and enforced scope |
| --- | --- |
| `client_task_labels` | id, public UUID, workspace, name <=80 characters, normalized-name SHA-256 key, archived boolean, lock_version, timestamps; unique workspace/name-key and workspace/id |
| `client_task_label_assignments` | id, workspace, task, label, timestamps; unique workspace/task/label; composite workspace/task and workspace/label FKs |
| `client_task_dependencies` | id, workspace, project, task, prerequisite_task, timestamps; unique workspace/project/task/prerequisite; both endpoint FKs use workspace/project/task triples; self-edge CHECK and reverse endpoint index for advisory reads |
| `client_task_contact_responsibilities` | id, workspace, company, project, task, contact, timestamps; unique workspace/task/contact; task FK workspace/project/task, project FK workspace/company/project, contact FK workspace/company/contact |

Names are trimmed and lowercased with the same shared UTF-8 function before
hashing; the stored name preserves display case. The ASCII digest makes catalog
uniqueness identical on SQLite and MariaDB without relying on different database
collations. No rich text, arbitrary CSS colors, per-label audience, or hard-delete
operation is added.

Reuse the existing tasks `(workspace_id, client_project_id, id)` unique key.
Add only missing supporting project `(workspace_id, client_company_id, id)` and
contact `(workspace_id, client_company_id, id)` unique keys for the contact triple
FKs. Keep project/company ownership server-derived and immutable. Use the existing
TenantReference additional-scope metadata for exact composite inventories; add no
weaker duplicate FKs. Domain/pivot columns stay out of generic mass assignment.

These tables carry no new disclosure states. Application rollback retains schema
and data; explicit schema rollback refuses while catalog/association/edge data
remains. The combined graph/file reader floor is still mandatory before any
organization writers are enabled. This slice does not relax that floor.
The self-edge guard uses a CHECK on MariaDB and equivalent insert/update triggers
on SQLite. Empty contact-schema rollback restores an owner-FK supporting index
when InnoDB reused the longer new unique index; it never disables existing FKs.

## Immutable domain and locking contract

New owned files live under `app/Services/Tasks/Planning/`, with database-free
`TaskDependencyGraph`, `TaskLabelName`, replacement commands and responsibility
eligibility facts; `TaskPlanningRepository` owns scoped reads/persistence and
`TaskPlanningMutationAction` owns current authorization, versions and transactions.
Framework validation stays at the shared command boundary, including raw CLI
shape checks: omitted fields preserve state; explicit empty lists clear; null,
empty strings, sparse lists, duplicate UUIDs and unknown keys are rejected.

Reuse GraphState, not a second project mutex. Structural dependency actions take:
receipt reservation -> billing-maintenance share -> Workspace exclusive ->
GraphState -> ascending task rows -> ascending list rows -> dependency rows ->
current memberships. Read all edges only after serialization; immutable cycle
validation runs over these current locked facts. Existing malformed ancestry or
cycles fail generically without foreign IDs/names. Workspace serialization also
coordinates metadata/status writers that do not acquire GraphState.

Label/contact replacements take the same Workspace/GraphState order, then just
the target task, catalog/association rows and candidate contact rows, then current
access. Register new catalog/association lock resources after existing task/list/
assignment resources and before Company/Project/Contact/current memberships.
Contact additions use direct scoped locking reads, so archival committed after an
outer repeatable-read snapshot is observed. No ordinary `whereHas` subquery
decides current authority. Catalog writes take Workspace, catalog row, then
current manager facts. Every path stays forward in the common registry.

Each successful version-checked replacement advances the task lock_version and
project GraphState once atomically, including a new-key no-op like existing task
updates. Exact receipt replay does not advance either. Dependency replacement
advances the source task and every
changed prerequisite endpoint: both outgoing and incoming views become stale.
Existing task/container tokens already include task/state revisions. Catalog
rename/archive advances the label's own revision; association writes require
current label facts and never overwrite catalog metadata. No-op replacement
creates no duplicate associations or unnecessary pivot writes.

The organization owner agreed the future seam: a scoped
`TaskGraphRevisionRepository::read(workspace, project, current=false)` returns an
unsaved zero state on absence; `lockForMutation(workspace, project)` initializes
only after Workspace-exclusive and keeps the current-read/createOrFirst fallback;
`advance(workspace, project, state)` validates lineage/existence and increments.
This lane implemented the helper in `5dcacf52`; the organization owner separately
landed its narrow delegation in `4490409e`. The actual integrated prerequisite
must be merged before adapter implementation. Do not call its private `advance()` or
duplicate initialization logic. Reads/preview never retain new state. Dependency writes
require both expected task version and expected graph version; label/contact
replacement requires expected task version. Native forms require the same stale
protection. Replays recheck current access, scope and server-owned Internal/Agent
origin before returning a result; new Portal identity denies Agent writes even
inside an old caller snapshot. CLI/native Internal remains deliberate.

## Transport and native contract

The exact fifteen operation IDs, routes, public schemas, presenters, native and
`svc:tasks:planning` contract are now specified in the
[adapter contract](task-planning-adapter-contract.md). That document supersedes
the earlier proposed `tasks.labels.*` operation names; mutation receipt suffixes
remain `task_planning.labels.*`, `task_planning.dependencies.replace` and
`task_planning.contacts.replace`. Shared registration remains coordinator-owned.

Writes use the common idempotency receipt executor, public UUIDs, version tokens
and a complete `label_ids`, `prerequisite_ids` or `contact_ids` list. Label archival
requires explicit `confirm: true`, matching retained-contact/list archival. MCP
mirrors the same operations and schemas; no caller controls origin or audience.
No new OAuth scopes are needed. Contacts use separate owner/admin endpoints with
both task scope and `clients:read`; ordinary task responses never embed contacts.
CLI uses these actions with preview-by-rollback and explicit --apply; it accepts
the same JSON shapes and has no independent persistence or policy.

Task pages receive at most 20 attached labels and a small dependency summary;
dependency detail/candidate pickers are separate bounded pages with finished
server URLs. Label options and dependency/contact candidate search are 25-row
pages with opaque query-bound cursors and the shared byte budget, filtering
workspace/company/project/actor before limits. Bulk task reads do not embed
prerequisite descriptions or full contact profiles. Rich dependency detail fits
the same task result envelope and existing tasks.list/project-embed byte budgets.
Manager contact DTOs contain only id, display name, archived state and version;
email, phone, notes and account information are excluded.

Extend the existing native task editor with label chips/picker, prerequisite
picker and derived blocked/incomplete warnings. Show contact responsibility in
its own manager-only section, clearly distinct from account assignees. Retained
archived choices remain visible and removable. Add a manager label-catalog page
or existing project management panel as a separate component. Use WorkspaceShell,
server-generated action/next URLs and capability-null controls. Native task lists
can filter an attached label within their existing authorized paginated query.
No Portal controls or metadata are added initially.

## Phased reviewable commits and ownership

1. Plan plus pure immutable graph/name/eligibility facts and focused Unit tests.
   No schema, routing, discovery or current domain adapters change here.
2. Additive schema/models, exact FK/inventory/rollback tests and scoped repository.
   Coordinator owns approval of migration times and shared lock/tenant inventory
   integration; organization owner retains Organization/*.
3. Shared mutation actions/current reads and focused SQLite/MariaDB concurrency,
   receipt/version and no-access/no-billing-effect tests. Agree and reuse the
   narrow GraphState revision seam; dependency replacement is atomic.
4. Native components and REST/MCP/trusted CLI adapters together, then one reviewed
   shared registry/generated contract integration. Coordinator retains route,
   catalog, OAuth/context/schema/type/snapshot ownership until explicitly handed
   off. Extend organization-owned advisory facts after merging its checkpoint.
5. Focus/static/disclosure, coordinated four-width browser evidence, candidate
   publication, then scheduled capped mutation and exact full-suite/hosted gates.
   Integrate actual main/reader/organization dependency squashes before final
   gates. No current full/mutation/build or production writes are authorized.

Meaningful focused tests include chain/diamond/transitive/self/duplicate/cycle
graphs and exact bounds; deterministic small-DAG replacement invariants; no-op,
empty-list clears and malformed CLI shapes; FK cross-workspace/project/company
rejection on both databases; generic malformed-state responses; 1-versus-100 task
read query bounds; maximum-Unicode byte-aware traversal; stale task/graph tokens;
two-connection opposite-edge races and edge edit versus task completion/move;
contact archive/restore/revocation and new-Portal-grant after old RR snapshot;
retained archived associations; unauthorized metadata omissions; shared REST/MCP
receipts and CLI preview rollback. Pin that metadata-only edits leave completion
timestamps, milestone claims, invoice totals, historical records, accounts,
invitations and grant rows unchanged.

Browser fixtures cover 20 long labels, 25 long prerequisites, 10 retained/archived
contacts, private ancestors, empty/error/stale states, authorized project manager,
read-only viewer and absent Portal controls at 390/820/1440/1920. Review every
screenshot and refresh the inherited shell after prerequisite integration.
