# Task planning presentation and adapter contract

This documentation checkpoint is based on frozen persistence `ef18e247` and the
approved [planning design](task-planning-details-plan.md). The coordinator has
accepted the fifteen operation names, separate contact authority, CLI prefix and
shared task-read scope below. Exact route/schema integration remains reviewable
here before source adapters or public registration are added. This commit adds
no routes, tools, UI, schema or enabled flags.

## Availability and audience

`TASK_PLANNING_DETAILS_ENABLED=false` remains an independent product gate through
`TaskPlanningDetailsGate`. Off means: no new discovery entries, native links,
planning fields, option queries or CLI help entry; every direct new endpoint and
command refuses before reading planning records. Existing dates, account
assignees, task reads/writes and all privacy readers keep their existing behavior.
Enabling `TASK_ORGANIZATION_WRITES_ENABLED` alone does not enable this feature.
Planning does not require that organization controls be enabled, but it does
require their integrated schema/shared revision helper and verified reader floor.

Agent operations require the declared existing OAuth scopes, MCP read/write
availability and existing global Agent write cutover where applicable. The
planning product gate has no first-party-session bypass. Native and trusted CLI
select `TaskMutationOrigin::Internal` in server code; REST/MCP select `Agent` even
for browser-session requests through Agent routes. No request field, CLI JSON,
route selector or MCP argument selects origin or audience.

| Reader | Labels/dependency summary | Label catalog/options | Contact responsibility/options |
| --- | --- | --- | --- |
| Authorized internal task reader | Attached labels and direct dependency summary for that task | No full catalog; no picker without task-management authority | Absent, including IDs, names and counts |
| Current internal project owner/manager | Same, plus task label/dependency controls | Active options for a manageable project; no workspace catalog management | Absent |
| Current workspace owner/admin | Same, plus task controls | Workspace catalog and active project options | Dedicated minimal DTO only |
| Portal or any dual-identity Agent principal | Entire new planning field absent; dedicated reads/writes denied | Denied | Denied |
| Native/CLI dual account with legitimate staff authority | Existing trusted Internal task policy | According to actual staff role | Only actual workspace owner/admin |

Agent contact reads/options require both `tasks:read` and `clients:read`; contact
replacement requires `tasks:write` and `clients:read`. It changes a task's business
responsibility, not a contact profile, so it does not require `clients:write` or
expose that profile. Ordinary Task responses never embed contacts, even for a
manager token with both read scopes. No new OAuth scopes are introduced.

The root-owned `App\Services\Tasks\TaskReadScope::query(User|AgentPrincipal,
Workspace, TaskMutationOrigin): Builder<ClientTask>` is the shared read seam. Its
origin argument is required and server-owned. Extract the existing
`AgentReadService::taskQuery()` body without widening it, then delegate that
method and new planning readers to the scope. Preserve all workspace/project/
company lineage conditions, the Internal workspace-role prerequisite, qualified
membership pivot workspace, manager/member scope and Portal-first Agent branch.
It does not replace `TaskAccess::canManage()` or locked write authorization.

Dedicated readers scope the source task first, then scope every association and
reference to the exact workspace/project/company. Options apply authorization,
lineage and eligibility before search, counts, row limits or byte limits. Invalid
ancestry produces a generic invalid-organization state/error with no foreign
IDs/names. A label, dependency or contact association grants no access, changes
no sharing flag and never widens a parent/task audience.

## Owned classes and integration seams

These are proposed exact PHP names under the existing namespaces; do not rename
the established `App\Services\Tasks\TaskPlanningPresenter`, which continues to
present dates, account assignees and the shared completion advisory.

| File/class | Contract |
| --- | --- |
| `Services/Tasks/Planning/TaskPlanningReadRepository` | Explicit scoped catalog, association, dependency and minimal-contact queries; authorized task builder input; bounded SQL/batch reads; no policy selected by request |
| `Services/Tasks/Planning/TaskPlanningDetailsReadService` | Scoped read orchestration through the shared scope, existing TaskAccess and owner/admin gate; no new receipt or persistence implementation |
| `Services/Tasks/Planning/TaskPlanningDetailsSnapshot` | Immutable authorized public facts for the bounded task planning response; no Eloquent models or lazy relations in presentation |
| `Services/Tasks/Planning/TaskPlanningDetailsPresenter` | Database-free projection of authorized snapshots; labels and dependency summary only for ordinary task fields |
| `Services/Tasks/Planning/TaskPlanningNativePresenter` | Finished native page/action/next URLs and capability-null controls over authorized facts |
| `Http/Controllers/ClientTaskPlanningController` | Native `page`, `show`, `options`, `mutate`, separate `archiveLabel`; chooses Internal origin and fixed web receipt client |
| `Http/Controllers/Api/V1/AgentTaskPlanningController` | Agent `show`, `options`, `mutate`, separate `archiveLabel`; route defaults select operation and route UUID selects target |
| `Services/Mcp/AgentMcpTaskPlanningTools` | Exact registry REST dispatch, including argument presence, schemas and retry key; no independent action/receipt implementation |
| `Console/Commands/Tasks/TaskPlanningCommand` | Primary `svc:tasks:planning`; trusted actor, shared reads, common receipt action and rollback preview |
| `Services/AgentApi/Operations/TaskPlanningOperations` | Root-owned exact route, scope, handler, request/response, error and availability metadata |

Reuse unchanged `TaskPlanningCommand::fromInput()`,
`TaskPlanningMutationAction::perform()/authorizeReplay()`,
`AgentTaskPlanningMutationAction::execute()/executeInternal()` and
`TaskGraphRevisionRepository`. Their seven mutation suffixes remain
`labels.create/update/archive/restore/replace`, `dependencies.replace` and
`contacts.replace`. Preserve receipt namespace `task_planning.<suffix>` and
fingerprint `target_id` plus complete raw validated body. Do not migrate or alias
existing receipt keys.

The read service exposes named methods rather than a caller-controlled policy:
`get`, `labels`, `label`, `labelOptions`, `dependencies`, `dependencyOptions`,
`contacts`, `contactOptions`, and `summariesForAuthorizedTasks`. Public reads take
actor/workspace, required server-owned origin and relevant public target UUID;
paged methods take validated `limit/cursor/q` as applicable. The bulk method
accepts already-authorized Internal tasks in batches of at most 100 and never
initializes graph state. `TaskPlanningDetailsPresenter::fields()/response()` and
`TaskPlanningNativePresenter::present()` consume authorized immutable facts; they cannot load relations or grant access.
Read-only snapshots never create a GraphState row.

## Exact public operations and routes

All paths below are relative to the existing Agent API prefix. `{workspace}`,
`{project}`, `{task}` and `{label}` are public UUIDs validated in the route. Native
JSON mutation/read routes use the same suffixes outside the Agent prefix and
native CSRF/session middleware. All request objects reject unknown keys.

| Operation ID | REST | Scope | Target / MCP method |
| --- | --- | --- | --- |
| `task_planning.get` | GET `/workspaces/{workspace}/tasks/{task}/planning` | `tasks:read` | task / `get` |
| `task_planning.labels.list` | GET `/workspaces/{workspace}/task-labels` | `tasks:read` | workspace manager / `labelsList` |
| `task_planning.labels.get` | GET `/workspaces/{workspace}/task-labels/{label}` | `tasks:read` | label / `labelsGet` |
| `task_planning.labels.create` | POST `/workspaces/{workspace}/task-labels` | `tasks:write` | no target / `labelsCreate` |
| `task_planning.labels.update` | PATCH `/workspaces/{workspace}/task-labels/{label}` | `tasks:write` | label / `labelsUpdate` |
| `task_planning.labels.archive` | DELETE `/workspaces/{workspace}/task-labels/{label}` | `tasks:write` | label / `labelsArchive` |
| `task_planning.labels.restore` | POST `/workspaces/{workspace}/task-labels/{label}/restore` | `tasks:write` | label / `labelsRestore` |
| `task_planning.labels.options` | GET `/workspaces/{workspace}/projects/{project}/task-label-options` | `tasks:read` | manageable project / `labelsOptions` |
| `task_planning.labels.replace` | PUT `/workspaces/{workspace}/tasks/{task}/labels` | `tasks:write` | task / `labelsReplace` |
| `task_planning.dependencies.get` | GET `/workspaces/{workspace}/tasks/{task}/dependencies` | `tasks:read` | task / `dependenciesGet` |
| `task_planning.dependencies.options` | GET `/workspaces/{workspace}/tasks/{task}/dependency-options` | `tasks:read` | manageable task / `dependenciesOptions` |
| `task_planning.dependencies.replace` | PUT `/workspaces/{workspace}/tasks/{task}/dependencies` | `tasks:write` | task / `dependenciesReplace` |
| `task_planning.contacts.get` | GET `/workspaces/{workspace}/tasks/{task}/contact-responsibilities` | `tasks:read`, `clients:read` | manager task / `contactsGet` |
| `task_planning.contacts.options` | GET `/workspaces/{workspace}/tasks/{task}/contact-responsibility-options` | `tasks:read`, `clients:read` | manager task / `contactsOptions` |
| `task_planning.contacts.replace` | PUT `/workspaces/{workspace}/tasks/{task}/contact-responsibilities` | `tasks:write`, `clients:read` | manager task / `contactsReplace` |

Catalog manager means workspace owner/admin. Label replacement/dependency writes
and both project/task option pickers require current TaskAccess management
within that project. `get` and dependency reads allow ordinary authorized
Internal task readers. Contact endpoints require workspace owner/admin in
addition to task scope. No endpoint accepts company/workspace/project ownership
in the body; derive these from the target's validated lineage.

Register logical route names `task-planning.<suffix>` for Agent operations.
Native JSON names are `tasks.planning.<suffix>`. Additional native catalog page:
GET `/workspaces/{workspace}/task-labels`, name `tasks.planning.labels.page`,
Inertia `clients/task-labels`. Its JSON catalog listing uses
`/workspaces/{workspace}/task-labels/data`, name `tasks.planning.labels.list`.
The task section uses existing `tasks.show`; no duplicate task detail page is
introduced. Fixed separate archive handlers preserve generated PATCH/DELETE
bindings. Register static option/data routes before UUID target routes.

MCP tool names are the exact operation IDs. Method names in the table are on
`AgentMcpTaskPlanningTools`; UUID parameters are `workspace_id`, then relevant
`task_id`, `project_id` or `label_id`. Writes include required
`idempotency_key` of 1–255 characters and the request fields below. Call the
registry-bound REST route, preserving explicit `[]` and every nullable value.
There are no `origin`, `audience`, `company_id`, `include_contacts` or ownership
arguments. Archive is an explicit-confirmation destructive operation; other
writes are local writes. Discovery/REST docs/CLI description use the same
availability and authority contract.

## Request and response schemas

Version tokens are opaque strings of exactly 64 characters. UUID lists must be
JSON arrays with consecutive list indexes, unique nonempty UUID strings; `[]`
clears associations, while omission, null and raw empty strings fail validation.
Every mutation uses the existing common idempotency contract.

| Request schema | Required body |
| --- | --- |
| `TaskLabelCreateRequest` | `name` (normalized single-line Unicode text, 1–80 characters) |
| `TaskLabelUpdateRequest` | `name`, `expected_version` (label version) |
| `TaskLabelArchiveRequest` | `expected_version`, `confirm: true` |
| `TaskLabelRestoreRequest` | `expected_version` |
| `TaskLabelReplacementRequest` | `expected_version` (task), `label_ids` (0–20) |
| `TaskDependencyReplacementRequest` | `expected_version` (source task), `expected_graph_version`, `prerequisite_ids` (0–25) |
| `TaskContactResponsibilityReplacementRequest` | `expected_version` (task), `contact_ids` (0–10) |

Label names use `TaskLabelName`; surrounding Unicode whitespace is normalized,
blank Unicode-space names and embedded CR/LF/NEL/line/paragraph separators fail.
Do not substitute a different ASCII trim or database collation rule in adapters.
Catalog archival preserves assignments; archived labels/contacts already attached
may remain or be removed, but new additions require active records. Restore does
not recreate removed associations. Contacts are from the target project's exact
company and never inferred from email/account equality.

| Public schema | Exact public facts |
| --- | --- |
| `TaskLabel` | `id`, `name`, `is_archived`, `version` |
| `TaskDependencySummary` | `blocked`, `incomplete_prerequisite_count`, `dependent_count` |
| `TaskPlanningDetails` | `task_id`, `version` (source task), `labels` (complete <=20), `dependency_summary` |
| `TaskDependencyReference` | `id`, `title`, `status`, `version`, `web_url` |
| `TaskDependencies` | `task_id`, `version`, `graph_version`, `prerequisites` (complete <=25), `dependents` (current page <=25) |
| `TaskContactResponsibility` | `id`, `display_name`, `is_archived`, `version` |
| `TaskContactResponsibilities` | `task_id`, `version`, `contacts` (complete <=10) |
| `TaskPlanningMutationResponse` | `{data: {id: public_uuid}}` of created/edited label or source task |

Contact `display_name` joins stored first/last names with surrounding whitespace
removed; empty names display `Unnamed contact`, never an email fallback. No
email, phone, notes, title, account identity, invitations, grants or contact count
is included in ordinary Task output. Reference `web_url` is derived by the
existing server route policy, not assembled by consumers from IDs.

`TaskPlanningDetailsResponse`, `TaskLabelResponse` and
`TaskContactResponsibilitiesResponse` wrap the corresponding object as `data`.
`TaskLabelListResponse`, `TaskDependencyOptionsResponse` and
`TaskContactResponsibilityOptionsResponse` use `{data: [...], meta:
{next_cursor: string|null}}`. `TaskDependenciesResponse` wraps `TaskDependencies`
as `data` and adds the same top-level `meta`; its cursor pages only `dependents`
and its full outgoing set/versions are repeated on each page. Consumers can edit
the complete outgoing set without fetching every incoming page. Writes return
201 only for label creation, 200 otherwise; callers re-read to get current tokens.
The ID response does not claim it enumerates every revised dependency endpoint.

Every collection page defaults to 25 rows, accepts `limit` 1–25, and uses strict
opaque cursors up to 2,048 characters. Catalog list adds `status=active|archived|all`
(default active) and optional `q` up to 100 characters. Options support `q`,
`limit`, `cursor`; options return active labels/contacts only. Selected archived
choices come from the dedicated complete attached set, not from widened pickers.
Dependency `get` accepts only `limit/cursor` for its incoming page. Dependency
options exclude self and candidates that would cycle using one bounded authorized
DAG snapshot, then apply the eligible-ID predicate before SQL search/page limits.

Use stable ascending numeric row ID internally and public UUIDs externally.
Cursor query keys bind operation, workspace, actor, server origin, target project/
task, normalized search/status and order; they reject replay in another scope.
Reauthorize on each page. Use current shared `CursorPage` strict-cursor mode and
`AgentPageBudget`; do not copy the older helper from the persistence branch.
Native next/previous URLs are finished server values. Byte budgets may shorten
pages but must not truncate a name/title or silently omit a collection member.

Ordinary Task list/get/project-embed output adds optional `planning_details`
containing only `{labels, dependency_summary}` when the independent gate is on
and the audience is authorized Internal. Omit the entire property for off/Portal/
dual Agent audiences; do not send null, empty contact fields or hidden counts.
Load authorized task batches of at most 100 with fixed-query label and dependency
summary reads. Scope joins before aggregate counts. Detect malformed over-limit
retained sets explicitly instead of silently taking 20. Do not load a full project
DAG per task/page. Serialized planning fields consume the existing task-page and
whole-project response allowance before cursor selection; existing source/base
project fields and task continuation stay unchanged. Measure one-versus-many
query counts and real Unicode REST/MCP/JSON-RPC envelope bytes.

Dependency detail/options may analyze at most 1,000 tasks, 100 retained lists and
5,000 edges, using `limit + 1` to detect excess. Return a documented 422 unsupported
organization-size error rather than a partial DAG/token. Label/contact get,
options and replacement must remain available on oversized legacy projects.
Their implementation must not transitively call a full-DAG reader.

Use existing 401/403/404/409/422/429 error conventions: missing authentication;
missing scopes/current authority or prohibited Agent identity; unavailable scoped
record or product off; stale version/reused key; invalid shape/name/confirmation/
cycle/size; rate limit. Generic malformed-organization messages reveal no foreign
keys or names. Do not use dependency errors as completion blockers.

## Revision, status and advisory behavior

The persistence checkpoint owns write revisions: label/contact replacement
advances the source task and shared GraphState once; dependency replacement also
advances every changed prerequisite endpoint. A successful new-key no-op still
advances source/state once. Exact receipt replay rechecks current locked authority
and scope, returns its existing ID, and advances nothing. Catalog edits advance
the label revision only. Read/preview leaves no graph state or receipts behind.

`version` is an optimistic mutation token, not an ETag for an enriched response.
A prerequisite can change status while its dependent source task version remains
unchanged. Every fresh read recomputes `blocked`/incomplete counts from current
same-project prerequisite statuses, and attached label names/archival come from
current catalog rows. Do not cache these summaries only by source version or
GraphState revision, or return 304 based on that token. No read cache is added in
this slice. Pagination is a current authorized view, not a promise to freeze
future prerequisite statuses.

Extend the existing single `TaskCompletionAdvisory` and repository after actual
organization integration; do not create a second completion workflow. Unfinished
children remain controlled by the organization flag, unfinished prerequisites by
the planning flag, and either may produce the same optional Internal advisory.
`blocked` concerns direct incomplete prerequisites only; descendants and transitive
ancestors are not silently reclassified. Native editor, REST/MCP task reads and
trusted CLI completion preview use the shared facts. A status change remains an
explicit permitted action regardless of either advisory. No automatic propagation,
new status, acknowledgment requirement, billing or grant effect is introduced.

## Native and trusted CLI behavior

The existing task editor receives a server-provided `planning_details` section
with complete attached labels, summary, finished detail/options/action URLs and
required task token. Label chips, prerequisite picker and manager-only business
responsibility are separate forms; account assignees retain their separate name
and account policy. Catalog actions live on `clients/task-labels` in
`WorkspaceShell`. Missing capabilities arrive as null URLs; project managers do
not receive contact sections/links or label-catalog management URLs.

Prerequisite detail shows the outgoing set plus paginated incoming references,
status labels and advisory text. Contact section appears only for workspace
owner/admin, contains at most ten minimal current/retained archived choices, and
states that responsibility grants no account access. No Portal page gains these
controls or fields. On oversized DAGs show a supported-size notice only in the
dependency panel; preserve label/contact editing.

On save, refetch authorized current facts; do not guess new revisions. A 409
preserves local selection/draft and offers explicit refresh, with no automatic
retry or silently updated stale form token. Separate unsaved task-body drafts
retain their original base version until refreshed/reconciled, even after another
planning form succeeds. Long Unicode chips, filenames, references and contact
names wrap; client name counters must not reject valid Unicode names merely
because JavaScript/HTML counts UTF-16 code units differently from the server.

Primary CLI class/signature: `App\Console\Commands\Tasks\TaskPlanningCommand`,
`svc:tasks:planning {operation} {workspace} {target?}`. `operation` is one of the
fifteen suffixes in the table; target is the public task/label UUID required by
that operation. `labels.options` uses `--project=<uuid>` and no target. Options:
`--actor`, `--input=<JSON file|->`, `--key`, `--q`, `--status`, `--limit=25`,
`--cursor`; mutation output uses inherited `--apply` and JSON conventions.
Reject selector/options irrelevant to the chosen operation rather than ignoring
ambiguous intent. No aliases outside `svc:tasks:...` are primary commands.

Reads are read-only. Mutations preview by default via inherited rollback and
`executeInternal`, fixed client ID `svc-cli-task-planning`; only `--apply` persists.
Use `svc-web-task-planning` for native receipt clients. Input is a JSON object up
to 1 MiB, with the same request keys, required versions and explicit arrays as
REST; no inferred current version or silent clear. Preview rolls back every
association, endpoint/graph revision, catalog row and receipt. Label/contact
preview output must obey the same authority as final reads. REST/MCP share their
OAuth client receipt identity; native and CLI intentionally use distinct fixed
clients, not caller-supplied impersonation IDs.

## Reader inheritance and phased implementation

The combined task/file R1 reader floor remains the prerequisite for retained
private ancestry. Task labels, dependency edges and contact responsibility do
not change containment, file flags, held URLs or parent authorization. Existing
`TaskVisibility::constrainPortal` remains authoritative for fixed Portal/Agent
consumers; structure-only native preview still requires its separate staff gate.
No planner picker can select the native preview context to widen Agent reads.

The coordinator separately reported the deployed R1 reader and retained 0600
private-default marker verified. This planning checkpoint performs no deployment,
marker installation/readback or production write and does not independently
re-certify that operational evidence. Preserve that marker and R1 rollback
capability across planning releases. File R2's database-default change, sharing
controls and topic/comment uploads remain separate; planning activation does not
activate them or depend on enabling those controls. Existing false file flags
and hidden/deleted ancestors remain enforced with every writer flag off.

Application rollback keeps all additive planning tables and historical
associations. The persistence migration guards refuse retained catalog/pivot/edge
rows on explicit down; older capable readers may omit Internal planning metadata
but must retain task/list/file disclosure. Neither contact archival nor catalog
archival rewrites task history, account links, invitations, grants, milestone
claims or invoice records.

Implement subsequent reviewable commits only after this route/schema contract
is frozen:

1. Shared scope extraction (root) and immutable read snapshots/scoped repository,
   summary batching, strict option/incoming pages and no-create reads (planning
   lane). Add scope, malformed-lineage, current-status and Unicode/query-budget
   tests first.
2. New native/Agent controllers, MCP REST dispatcher and `svc:tasks:planning`
   adapter using existing command/action/receipt APIs. Root retains shared
   catalog/routes/deployment flags/OAuth capability/schema/generated snapshots,
   types and current page/UI integration; coordinate new component ownership.
3. Root-owned native editor/catalog integration and extension of the single
   advisory loader. Add REST→MCP shared-receipt parity, trusted CLI preview/apply/
   replay, manager/non-manager/dual/flag-off omissions, and stale cross-form
   behavior. Attribute mutation tests to invoked target bodies as well as public
   metadata; reflection-only assertions do not prove Infection coverage mapping.
4. After focused SQLite/MariaDB/static/disclosure, schedule four-width synthetic
   browser review and publish only a complete candidate. Actual dependency
   squashes, capped mutation and exact full/hosted gates remain coordinated later.
   No full/mutation/build or production work is authorized by this document.

Required focused regressions include label catalog rename/archive with unchanged
source token; prerequisite completion/reopen with unchanged dependent token;
source/changed-endpoint/shared-graph invalidation and new-key no-op versus replay;
manager authority and `clients:read` missing from contact metadata; exact-company
active/retained archived contacts; no account/grant/invitation/history/billing
changes; labels/contact editing above DAG caps; dependency incoming traversal and
cycle-safe options; cursor cross-actor/target/search rejection; one-versus-100
summary query bounds; and full large-Unicode REST/MCP/SDK traversal. Refresh
browser fixtures for twenty long labels, twenty-five prerequisites, paginated
incoming refs and ten long/archived contacts at 390/820/1440/1920, including empty,
forbidden, unsupported-size and stale states.

## Isolated reader checkpoint

The first implementation checkpoint adds only `TaskPlanningReadRepository`,
`TaskPlanningDetailsReadService`, `TaskPlanningDetailsSnapshot` and
`TaskPlanningDetailsPresenter`, plus focused tests. It merges the exact reviewed
organization prerequisite `88d1ce36` and the coordinator's shared-scope extraction
`ec0af1cc`; the shared GraphRevision helper is unchanged. No public adapter,
registration, native control or advisory-loader integration is added yet.

Focused SQLite checks passed 124 tests / 1,738 assertions (118 passed, six expected
MariaDB-only skips), including the planning persistence/schema/revision suite,
existing task-read integration and tenant inventories. The final serial MariaDB
reader selection passed 16 tests / 542 assertions; an earlier intersecting
reader/schema/revision selection passed 22 / 450. Unicode fixtures respect the
existing contact name limits of 100 characters each. Coverage includes fresh
status/catalog facts with unchanged source tokens, exact scopes/omissions,
archived choices, strict cursor traversal, combined outgoing/incoming byte
budgets and real SDK envelopes, legacy malformed references, depth eight/nine,
large-project label/contact availability and constant one-versus-100 query count.
These are focused results, not full-suite, mutation, browser or release evidence.

## Native interface checkpoint

The adapter stack now includes the new `TaskPlanningPanel`, a reusable seeded
`PlanningPicker`, and the native `clients/task-labels` catalog page. These are
presentation components awaiting the coordinator-owned production route,
registry/schema, existing task-page, ordinary Task metadata and single completion
advisory integrations. Their presence does not enable the independent product
flag or establish deployment completion.

Pass the nullable `TaskPlanningNativePresenter::present()` result as a separate
page prop named `planning_details`, and render
`<TaskPlanningPanel details={planning_details} />` beside the existing task body
form. The panel refreshes only that prop. Each dirty planning form retains its
captured token and selections across other forms' saves; a successful form reads
fresh authorized props before clearing its own draft. A refusal retains choices
and offers an explicit section reload. The task body form is not reset or given
a newer token by a planning save. Catalog mutation refreshes only `catalog`.

Native JSON option/dependency endpoints convert their public cursor response to
`{data, next_href, previous_href: null}` using the current authorized server route
and query. REST/MCP keep their unchanged public `{data, meta:{next_cursor}}`
contract. Browser requests use finished continuations and typed search against
the supplied options URL. Seeded empty pages cause no mount request. Ordinary
readers receive a read-only label/dependency presentation without controls;
contact sections are omitted entirely unless the presenter authorizes them.

Focused verification passed 19 React tests, TypeScript checking, targeted ESLint
and formatting, and the native/Agent/CLI plus MCP selection (7 PHP tests / 256
assertions). `node scripts/check-task-planning-layout.mjs` serves synthetic props
with Vite development transforms, without a production build or database. Its
24 states cover full/empty/ordinary-reader/unsupported-dependency/stale/catalog
at 390, 820, 1440 and 1920 pixels. All passed document-overflow, one-row navbar and
browser-error assertions; every original screenshot was inspected for clipping
and overlap. Fixtures include twenty retained long labels, twenty-five outgoing
prerequisites, paginated incoming references and ten long archived/current
contacts. This proves the isolated components' presentation, not integrated
production-route behavior, hosted full-suite/static/mutation results or feature
activation. Those remain exact-candidate CI and coordinator deployment gates.

The integrated follow-up also exercises the actual `clients/task` page and its
existing `TaskEditor` alongside planning. Run the focused development harness
with `--integrated`: a new disposable SQLite database supplies props through the
current native task/catalog controllers and shared navigation presenter; Vite
renders the current page without a production build. Four-width checks verify
zero mount requests, an explicit planning save followed by a partial
`planning_details` refresh, preservation of an unsaved task title, and a later
task PATCH that still carries its originally captured task-body token. Synthetic
mutation/refresh responses are intercepted in the browser; actual route,
receipt, authorization and CAS behavior are independently covered by the
registered-route PHP tests. All eight integrated screenshots were inspected.

`TaskPlanningPageBudgetTest` closes ordinary Task list/project-embed verification:
one-versus-twenty-five enriched tasks use the same bounded total query count;
seven hostile-Unicode tasks each retain all twenty full label IDs/names while
byte limits, rather than the default row cap, require continuation. Actual REST,
MCP, SDK `CallToolResult` and JSON-RPC envelopes remain within their configured
ceilings, decoded encrypted continuation boundaries agree, every task is returned
once, and these reads create no GraphState or mutation receipts. These focused
checks do not replace exact-head hosted full-suite/static/mutation gates.
