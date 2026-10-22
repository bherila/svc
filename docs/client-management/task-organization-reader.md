# Task organization reader prerequisite

This release installs disclosure readers before enabling task lists or subtasks.
There are no list or graph mutation routes, catalog operations, request fields or
editing controls. Existing tasks remain unlisted roots. Task status, account
assignees, completion timestamps, milestone prices and invoice claims keep their
existing behavior.

`client_task_lists` belongs to one workspace and project. New lists default to
private. A task's nullable `parent_task_id` and `client_task_list_id` are excluded
from mass assignment and public serialization. Both references have real
same-project composite foreign keys. Only a root may carry a list reference;
descendants inherit that root's list. List deletion and task-parent deletion are
restricted rather than clearing references and changing disclosure. A deleted
list remains a disclosure barrier. This slice introduces no task soft-delete
writer; a missing task ancestor is always invalid.

`App\Services\Tasks\TaskVisibility` is the shared SQL boundary:

- `constrainPortal($tasks, $workspace, $actor, $company = null)` includes exact
  Portal project grants, full tenant/company/project lineage and disclosure.
  A dual staff/portal identity receives the same Portal restrictions.
- `constrainVisibleStructure($tasks, $workspace, $company = null)` checks
  disclosure **only**. It is not actor authorization. Native owner/admin portal
  preview must authorize its existing project scope first, then use this method.
- `portalVisibleIds($workspace, $actor, $taskIds, $company = null)` filters a
  bounded list of internal IDs in one query and returns an `id => true` map.

Each query is over the normal `client_tasks` table, not a caller-selected alias.
Apply the predicate before matching search text, counting, taking a preview limit
or paginating. Detail, embed, task comment, search, activity, attachment metadata
and attachment byte readers must use this boundary. Filtering the final page in
PHP cannot protect counts, snippets or cursor behavior.

The predicate validates at most eight parent edges using fixed scoped joins.
Every present ancestor and the project must be client-visible. A listed root
also requires its same-project list to exist, be live and be client-visible.
Missing, foreign, cyclic or deeper ancestry and a non-root list reference deny
disclosure. Effective visibility is derived; no subtree flags are copied.

`TaskAncestryRepository::factsBatch($workspace, $taskIds)` returns a map of
immutable `TaskAncestryFacts` for already-authorized internal task IDs. It loads
initial tasks and at most eight parent batches, plus one list batch. The maximum
input batch is 100 positive integer IDs; oversized batches fail rather than
truncate. Classification is database-free. Facts expose only organization
validity and effective visibility. Invalid organization returns the generic
`invalid` state, with no foreign ancestor/list IDs or names and no repair UI.
These are read snapshots, not the current locked facts required by future writers.

The schema provides `(workspace_id, client_project_id, id)` parent keys and
scoped parent/list child indexes. Focused tests measure SQLite and MariaDB
`EXPLAIN` plans and require a single key lookup per ancestor. Batch tests prove
query count does not grow with the number of task rows.

The disclosure probe uses a correlated scalar subquery returning `1`, with a
limit of one, rather than a plain `EXISTS`. MariaDB's measured plan for `EXISTS`
materialized workspace-wide ancestry before the outer page limit; the scalar
form uses a unique-key lookup for the task and primary-key lookups for each of
its eight ancestors.

Before enabling any structural writer, deploy and verify this reader and every
collaboration/files/search adapter against synthetic hidden-ancestor fixtures.
Record that verified reader release as the minimum rollback-compatible version.
Do not roll back to an own-flag-only reader after structural data can be written.
The additive migration by itself is not evidence that this deployment floor has
been established.
Its rollback refuses any retained list (including deleted lists) or populated
task organization reference, so schema rollback cannot strip a disclosure barrier.

The following writer release must lock the existing workspace access mutex,
then a registered project graph state, sorted task IDs, graph pivots and current
access rows. It must validate current topology rather than an old repeatable-read
snapshot, reauthorize Agent retries, enforce depth and same-project lineage, and
version complete sibling reorder sets. Lists/subtasks, workspace labels and the
separate dependency DAG remain later releases. Child/dependency completion stays
advisory and cannot silently change task completion or billing.
