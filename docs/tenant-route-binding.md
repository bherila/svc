# Tenant route binding

Implicit bindings on models implementing `WorkspaceOwned` and using `HasPublicId`
resolve through `TenantRouteBinding`. This is an HTTP boundary, not a global
Eloquent scope: domain repositories and console commands still declare their own
workspace predicates.

A workspace URL resolves its workspace first, checks membership, then reads the
child under that workspace. A bound company further constrains a nested record's
company; a bound invoice constrains its payment. Controller policies still decide
which operations and project-visible records the member may access.

The invoice show, PDF and Stripe payment-intent routes also serve external portal
members despite their workspace URL. For those readers, the invoice binding
requires a membership for that invoice's company and workspace in SQL. The
controller retains its visibility, payment-status and operation checks.

Portal URLs have no workspace segment. Their company binding derives accessible
workspaces in SQL from owner/admin membership or membership in that exact client
company. It never loads the company first to discover its workspace. Subsequent
bindings use that company's workspace and company key. An inaccessible company
or invoice now returns 404 instead of loading it and returning 403; ordinary
operation-policy refusals remain 403.

A tenant-owned implicit binding without one of these contexts fails closed before
reading the tenant table. New route families need an explicit context rule and a
request-level isolation test. Soft-deleted binding uses the same tenant resolver.
String parameters resolved explicitly by controllers, including the agent API,
continue to require their own scoped repository lookups.

`TenantRouteBindingTest` drives real HTTP routes across the current implicit
binding model types and checks the emitted queries with either SQL quoting style.
It also covers missing workspace membership and same-workspace company mismatch.
Existing portal, billing, engagement and reconciliation tests cover the policies
that run after binding. This mechanism does not certify downstream relation
queries as scoped; those remain separate service-level work.
