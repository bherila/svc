# Client Management — Setup

## Prepare a local instance

Follow [Local development](../../README.md#local-development) for dependencies,
application configuration, migrations and the development server. See [the architecture](../architecture.md) for the identity adapter and
[.env.example](../../.env.example) for local configuration keys. Use a
local disposable database and synthetic client records when developing or testing.

## Create a workspace and client

1. Sign in and open `/app`, the workspace selector.
2. Choose **New workspace**, enter a name, and create it. The
   [CreateWorkspace action](../../app/Actions/CreateWorkspace.php) creates an
   `owner` membership for the signed-in user.
3. Enter the workspace. When it has no clients, the entry screen directs a
   manager to add the first client from the client switcher.
4. Select the client to work inside it. Available modules and actions depend on
   the viewer's access; use the links the application presents.

The entry route is `/workspaces/{workspace}`. Its parameter is the workspace's
public identifier. [WorkspaceEntryController](../../app/Http/Controllers/WorkspaceEntryController.php)
rechecks a remembered client, opens the only accessible client when there is one,
or asks the viewer to choose. The selector remains available at `/app`.

## Access comes from memberships

There is no setup step that assigns a global administrator to user 1. The
relevant authorization boundaries are:

- [WorkspacePolicy](../../app/Policies/WorkspacePolicy.php): `view` checks workspace
  membership; `manage` requires an `owner` or `admin` membership in that workspace.
- [ProjectAccess](../../app/Services/Authorization/ProjectAccess.php): resolves
  internal project access from workspace and project memberships, with separate
  decisions for viewing, managing tasks, logging time and approving time.
- [ClientCompanyPolicy](../../app/Policies/ClientCompanyPolicy.php): `viewPortal`
  admits workspace owners/admins or a user attached to that client's portal.
- [PortalAccess](../../app/Services/Authorization/PortalAccess.php): narrows portal
  visibility for memberships whose `access_scope` is `projects`. An empty grant
  list gives no project access; it does not mean unrestricted access.

These are references to the authorization implementations, not a claim that every
route automatically enforces each rule. When adding a screen or write path,
verify its policy calls and tenant predicates and add isolation coverage as
required by [AGENTS.md](../../AGENTS.md).

## Where to inspect a feature

Start with [routes/web.php](../../routes/web.php) for workspace and client
navigation. Billing and engagement actions are declared in
[routes/billing.php](../../routes/billing.php) and
[routes/engagement.php](../../routes/engagement.php); the agent API is in
[routes/api.php](../../routes/api.php).

The operator client pages live under `resources/js/pages/clients/`, portal pages
under `resources/js/pages/portal/`, and workspace entry/selection under
`resources/js/pages/workspaces/`. Their controllers are in
`app/Http/Controllers/`. See [the interface guide](../ui.md) for layout rules and
[the billing hub](billing.md) for invoice behavior.
