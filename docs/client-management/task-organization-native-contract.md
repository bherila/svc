# Native task organization contract

The page component is `clients/task-organization`. It renders through
`WorkspaceShell activeModule="tasks"`. Existing web and
`ResolveWorkspaceNavigation` middleware supply the shared auth/name/applications
and `workspaceNavigation` props. The page route includes a model-bound
`clientCompany`; the controller verifies workspace/project/company lineage.

Native route names:

| Name suffix after `tasks.organization.` | Method | Path identities | Domain command |
| --- | --- | --- | --- |
| `show` | GET | workspace, clientCompany, project | Native page |
| `snapshot` | GET | workspace, project | Shared JSON snapshot |
| `lists.store` | POST | workspace, project | lists.create |
| `lists.update` | PATCH | workspace, project, list | lists.update |
| `lists.archive` | DELETE | workspace, project, list | lists.archive |
| `lists.restore` | POST | workspace, project, list | lists.restore |
| `lists.reorder` | PUT | workspace, project | lists.reorder |
| `tasks.store` | POST | workspace, project | tasks.create |
| `tasks.move` | PATCH | workspace, project, task | tasks.move |
| `tasks.reorder` | PUT | workspace, project | tasks.reorder |

Mutations use the server route default `task_organization_operation`. Path record
identities cannot also appear in the body. All mutation requests accept an
`Idempotency-Key` header and return `{data:{affected_ids:[UUID]}}`; creations return
201 and other mutations return 200. Native form adapters may alternatively supply
`idempotency_key` in the body. Preserve the key and exact payload when retrying an
uncertain result. A changed payload needs a new key after a successful response.

Native page query: `parent_id` or `list_id`, otherwise the unlisted root;
optional `moving_task_id`. Every supplied navigation URL already includes the
required query. Browse links and breadcrumbs preserve moving context; the
moving card's `cancel_href` clears it. The UI never assembles a URL from an ID.

Page props:

```ts
type Target = { parent_id: string | null; list_id: string | null };
type OrganizationPage = {
  company: { name: string; href: string };
  project: { id: string; name: string; href: string };
  organization: {
    graph_version: string;
    lists_version: string;
    create_list_href: string | null;
    reorder_lists_href: string;
    ordered_list_ids: string[]; // Complete active list order.
    assignees_href: string;
    unlisted: { browse_href: string; move_target: Target | null };
    lists: Array<{
      id: string; name: string; version: string;
      is_visible_to_client: boolean; archived: boolean;
      browse_href: string; update_href: string | null;
      archive_href: string | null; restore_href: string | null;
      move_target: Target | null;
    }>;
    moving: null | {
      id: string; title: string; version: string;
      move_href: string; cancel_href: string;
    };
    container: {
      title: string; target: Target; move_target: Target | null;
      version: string; create_task_href: string | null; reorder_href: string;
      sharing_note: string | null;
      breadcrumbs: Array<{ label: string; href: string }>;
      tasks: Array<{
        id: string; title: string; status_label: string; version: string;
        is_visible_to_client: boolean; effective_visible_to_client: boolean;
        task_href: string; browse_href: string; create_child_href: string | null;
        child_target: Target; move_picker_href: string; move_target: Target | null;
      }>;
    };
  };
};
```

Only nullable `move_target` objects advertise valid destinations. The always
present container `target` identifies create/reorder scope. Creation hrefs are
null for archived/full containers or depth/task limits. Display `status_label`
directly. Effective visibility is publication eligibility through project,
ancestor and root-list flags; actual Portal access additionally requires current
company/project grants. `sharing_note` explains a blocking containing flag.

The native DTO contains at most 100 retained lists, 100 complete sibling tasks,
and the bounded ancestor breadcrumb. It contains no descriptions or full task
tree. Assignment and full metadata editing remain available through `task_href`.

Mutation bodies (`g` is `organization.graph_version`):

```js
// POST organization.create_list_href
{ name, is_visible_to_client: false, expected_graph_version: g }

// PATCH list.update_href (omit unchanged optional fields; name must be nonblank)
{ name, is_visible_to_client, expected_version: list.version, expected_graph_version: g }

// DELETE list.archive_href
{ confirm: true, expected_version: list.version, expected_graph_version: g }

// POST list.restore_href
{ expected_version: list.version, expected_graph_version: g }

// PUT organization.reorder_lists_href; include every active list exactly once
{ ordered_ids, expected_container_version: organization.lists_version }

// POST container.create_task_href using container.target,
// or POST row.create_child_href using row.child_target
{
  ...target,
  expected_graph_version: g,
  task: { title, description: null, is_visible_to_client: false,
          starts_on: null, due_on: null }
}

// PATCH moving.move_href; take target from a nullable server move_target
{ ...target, expected_version: moving.version, expected_graph_version: g }

// PUT container.reorder_href; include every current sibling exactly once
{ ...container.target, ordered_ids, expected_container_version: container.version }
```

Successful native mutations return JSON; refresh the current page props or visit
a supplied navigation URL. Validation failures are 422, stale versions are 409,
and current authority is checked again when applying or replaying a request.
All new management routes/discovery remain disabled until reader-floor rollout.
