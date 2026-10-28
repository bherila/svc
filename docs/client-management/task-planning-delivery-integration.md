# Delivered planning adapter integration

Implemented and integrated in PR544. Planning remains disabled by default through
`task_planning_details.enabled`; activation is an installation operation. The
route/registry/scope/OpenAPI/MCP factory wiring, native task page, ordinary task
read enrichment and shared completion advisory are integrated. The bindings and
props below describe the maintained compatibility contract.

## Handler bindings

`AgentTaskPlanningController` and `ClientTaskPlanningController` expose `show`
(and equivalent `options`) for reads, `mutate` for writes, and `archiveLabel`
for DELETE label routes. Every route default `task_planning_operation` is one
suffix from the approved fifteen-operation contract. The full public operation
ID is `task_planning.<suffix>`. Path identities come only from route parameters
named `task`, `label`, or `project`; no-target operations are `labels.list/create`.
Native receipt identity is `svc-web-task-planning`; REST uses its authenticated
OAuth client. REST/MCP always select Agent origin, including firstparty browser
credentials. Native authenticated users select Internal origin.

Native route names are `tasks.planning.<suffix>`. The catalog page route is
`tasks.planning.labels.page`, handled by `ClientTaskPlanningController::page`.
The native JSON catalog route must have a distinct URI (e.g. `/task-labels/data`)
from the Inertia page; register static/options paths before UUID paths. JSON
mutation envelopes are exactly `{data:{id:string}}`; only label creation is 201.

`AgentMcpTaskPlanningTools` method bindings, in contract order:

| Suffix | Method |
| --- | --- |
| get | get |
| labels.list | labelsList |
| labels.get | labelsGet |
| labels.create | labelsCreate |
| labels.update | labelsUpdate |
| labels.archive | labelsArchive |
| labels.restore | labelsRestore |
| labels.options | labelsOptions |
| labels.replace | labelsReplace |
| dependencies.get | dependenciesGet |
| dependencies.options | dependenciesOptions |
| dependencies.replace | dependenciesReplace |
| contacts.get | contactsGet |
| contacts.options | contactsOptions |
| contacts.replace | contactsReplace |

Every method dispatches through `AgentMcpRestCall`. Parameters use public UUIDs,
64-character versions, 1..255 retry keys, label names 1..80, explicit `confirm:
true`, and required distinct UUID arrays with maxima 20 labels / 25 prerequisites
/ 10 contacts. Options/catalog query defaults are `q:''`, `limit:25`, `cursor:null`;
`labels.list` additionally accepts `status:active|archived|all` (default active).
`dependencies.get` accepts only limit/cursor. Other detail reads accept no query.
The CLI is `svc:tasks:planning`; it is hidden while off and defaults writes to a
transactionally rolled-back preview. Use `--apply` to commit; mutation JSON is a
private input file or `--input=-`, limited to 1 MiB.

## Native props and draft protection

Call `TaskPlanningNativePresenter::present($actor, $workspace, $taskId)` **only
for the active task** and expose its nullable result as a separate top-level
`planning_details` Inertia prop. Off returns null before any planning reads.
Do not merge planning data into the original date/assignee `TaskPlanningPresenter`.

The panel receives `{details: TaskPlanningNativeDetails}` from the new independent
`TaskPlanningPanel` component. Its successful mutation refreshes only
`planning_details`. Preserve the existing task-body form's captured version and
unsaved draft (currently TaskEditor is keyed by task version). Do not reload that
body prop or key after a separate planning save. Other planning forms likewise
retain their captured versions and drafts; an explicit reconciliation reload is
required after a conflict. No automatic stale retry is permitted.

Native shape (the exact TypeScript definitions live in `types/task-planning.ts`):

- `task_id`, `version`, `dependency_summary` (blocked, incomplete prerequisite
  count, dependent count).
- `labels`: `selected` label facts; `options` bounded first NativePage or null;
  finished `options_href` and `replace_href` (or null for readers).
- `dependencies`: `data` detail or null (task_id, version, graph_version,
  prerequisites complete <=25, dependents first page <=25); `read_href`,
  `next_href`, `options` first NativePage or null, `options_href`, `replace_href`,
  `unsupported_notice` or null. An oversized graph disables this section only.
- `contacts` exists only for workspace owner/admin: `selected` minimal contact
  facts, `version`, `options` bounded first NativePage, `options_href`,
  `replace_href`. It is absent for project managers/readers. Never embed it in
  ordinary task DTOs or Portal output.
- `label_catalog_href` or null.

A NativePage is `{data: [...], next_href: string|null, previous_href:null}`.
Label facts are `{id,name,is_archived,version}`; contact facts are
`{id,display_name,is_archived,version}`; dependency task references are
`{id,title,status,version,web_url}`. All initial choices are seeded from authorized
25-row reads; no component performs a mount fetch. Later explicit search/page
requests use finished endpoint URLs and preserve the opaque cursor envelope.
The catalog page is `clients/task-labels`, with `workspace:{id,name}` and
`catalog:{labels,filters:{status,q},search_href,create_href,next_href,previous_href}`.
Each catalog label adds nullable `update_href`, `archive_href`, `restore_href`.

## Shared scope extraction

`TaskReadScope` is the shared extraction of the existing authorized task query.
`AgentReadService` delegates to it with explicit Internal versus Agent origin.
The planning loader batches at most 100 authorized task facts without a full DAG
read, and attaches transient metadata without making task columns dirty. Ordinary
Task responses include only labels and dependency summary; contact responsibility
is a separate authorized read. Preserve these scope and audience boundaries when
changing list, detail or project-embedded responses.

## Focused evidence

Local checks cover synthetic SQLite HTTP/native/CLI adapters, all fifteen MCP
route/body dispatches and schema bounds, closed route/query validation, and
native first-page seeding with role omissions. Heavy full suites, PHPStan,
mutation/coverage, and final candidate gating belong to CI.


Final exact-head CI passed on candidate `7096c634e41eba31fe04b9645eecf2d6a20d6bd6`.
Its actual Unit-selected diff mutation artifact killed 507/507 with no escapes,
timeouts or skips. The four-width browser proof includes the actual combined
native task page and preservation of unsaved task-body versions after a planning
save. Operational serving/activation evidence belongs to private installation
records, not generated application fixtures.
