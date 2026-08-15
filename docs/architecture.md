# Architecture

## Product boundary

SVC owns the service-business workflow: workspaces, people, clients, projects, tasks, time, proposals, agreements, invoices, payments, files, and subcontractors.

It does not own banking transactions, tax calculations, or personal-finance accounts. Those systems may link to SVC records through stable public identifiers and narrow APIs. A finance application can, for example, record that a bank transaction reconciles an SVC invoice payment without either application sharing database tables.

## Tenancy

`Workspace` is the tenant boundary. Users enter a workspace through `WorkspaceMembership`, which carries their role. Every business record belongs directly or transitively to one workspace. Routes expose UUIDs instead of sequential database identifiers.

Tenant isolation is enforced at authorization and query boundaries rather than through a mutable global "current workspace" scope. This keeps commands, jobs, and APIs explicit and testable.

## Replaceable adapters

- **Identity:** OAuth 2.0 authorization code flow with PKCE. The initial deployment uses the Bherila identity provider, but domain records only know local users.
- **Files:** private object storage behind a storage service; no OneDrive or Bherila path assumptions in models.
- **Payments:** Stripe is an optional adapter. Manual payments remain a first-class domain capability.
- **Finance reconciliation:** optional references to external transaction UUIDs, never foreign keys into another application's database.
- **Mail and documents:** generated messages and documents use provider-neutral Laravel contracts.

## Public identifiers

Cross-system and URL references use immutable UUIDs. Integer primary keys remain internal implementation details and must not be sent to external integrations.
