# Client Management System

Client companies, portal access, projects, tasks, time entries, attachments, and
the surfaces that reach them. The billing rules live in the focused documents
listed under [Quick links](README.md#quick-links); this page describes the
structure those rules run on.

## What this document used to say

Most of this file was inherited from the predecessor application and described
that system's schema, namespaces, routes, and screens rather than this one. It
named an `Admin` gate keyed on `users.user_role` and on user id 1, a
`client_company_user` pivot, `uploaded_files` and `file_download_history`
tables, `client_subcontractor_engagements` and `client_subcontractors` tables,
an `App\Models\ClientManagement\*` namespace, a `/client/mgmt` and
`/api/client/portal/{slug}` route family, Blade templates mounting per-page
React bundles, and an invoice `status` column with four values. None of those
exist here.

They have been corrected or deleted. Where a rule already has a document of its
own, this page points at it rather than keeping a second copy that can drift
independently — which is how the four-value status list survived here after
`App\Support\Billing\InvoiceStatus` was written to stop exactly that.

The column-level differences between the two schemas, and the defects each one
has already caused, are catalogued in
[schema drift from the predecessor](schema-drift.md). The authoritative list of
tables and their invariants is [the domain contract](../domain-contract.md).

## Architecture

### Tenancy

Every business row belongs to a `workspace` and carries both a direct
`workspace_id` and an immutable UUID `public_id`. Tenant-owned models use
`App\Models\Concerns\BelongsToWorkspace`, which names `workspace_id` in the
`UPDATE`/`DELETE` statement itself rather than relying on the read that found
the row. External surfaces address rows by `public_id`; the integer key is
hidden from serialization. See [tenant foreign keys](tenant-foreign-keys.md) for
the composite `(workspace_id, parent_id)` keys that make a cross-tenant
reference unstorable.

### Authorization

There is no global admin flag. Authority comes from a workspace membership role
and, below that, from a project membership.

- **`WorkspacePolicy::view`** — any `workspace_memberships` row for the viewer.
- **`WorkspacePolicy::manage`** — a membership whose `role` is `owner` or
  `admin`. Every operator write route requires it.
- **`ClientCompanyPolicy::viewPortal`** — a workspace `owner` or `admin`, or a
  `client_company_memberships` row for that company. Any workspace membership
  used to admit a viewer here, which made the portal a way around project
  scoping entirely.
- **`ClientProjectPolicy`** delegates to `App\Services\Authorization\ProjectAccess`.
  A workspace `owner` or `admin` is treated as project `owner`; otherwise the
  role comes from `client_project_memberships.role`, which is one of `owner`,
  `manager`, `contributor`, `viewer` (`App\Support\AgentApi\ProjectRole`).
  Owners and managers may manage tasks and approve time; everyone but a viewer
  may log time.

`ProjectAccess::viewableProjectIds()` and `reachableCompanyIds()` resolve "which
projects" and "which clients" in one query each. They exist because the client
directory, the company switcher and the workspace invoice list all needed the
same answer, and two copies of it is how the directory and the time sheet came
to disagree.

**Portal narrowing.** `App\Services\Authorization\PortalAccess` decides what a
portal user sees inside one company. A membership's `access_scope` is `company`
by default; set to `projects`, the viewer sees only the projects listed in
`client_portal_project_access`. `visibleProjectIds()` returns `null` for
unrestricted and an empty list for a scoped user granted nothing — those are
different answers. The narrowing is applied on the portal page, the read API,
attachments, proposals and agreements alike, not only while rendering a project
list. `svc:portal:project-access` sets and clears it.

`client_company_memberships.role` defaults to `client`. Nothing in this
application reads it, and there is no client/subcontractor distinction at the
membership level — see [Subcontractors](#subcontractors).

### Where this lives

- Models: `app/Models/` — flat, not namespaced by feature.
- Services: `app/Services/Billing/`, `app/Services/Engagement/`,
  `app/Services/Authorization/`, `app/Services/Activity/`, `app/Services/Files/`.
- Enums and value objects: `app/Support/Billing/`, `app/Support/Expenses/`.
- Controllers: `app/Http/Controllers/` (client directory, portal, companies,
  projects, tasks), plus `Billing/`, `Engagement/`, `Expenses/`, `Files/`, and
  `Api/V1/` for the agent and MCP surface.
- Frontend: `resources/js/pages/` served through Inertia. There is one Blade
  template, `resources/views/app.blade.php`; `resources/views/invoices/` holds
  PDF and email templates only.
- Tests: `tests/Feature/Billing/`, `tests/Feature/Engagement/`.

## Database schema

[The domain contract](../domain-contract.md) is authoritative for every table
below and states the invariants each one carries. What follows is the column
detail for the tables this page owns; billing tables are summarised in the
contract and their behaviour is in [billing.md](billing.md).

Money is stored as integer minor units in a `*_amount` column beside an ISO 4217
`currency`. Durations are stored as integer `minutes`. Only
`client_invoices`' restored hour columns and `client_invoice_lines.quantity` /
`hours` are decimals, and only because they are a historical record that cannot
be recomputed.

#### `users`

No client-management columns beyond `last_workspace_id` and
`last_client_company_id`, which remember where a person was last working across
sessions and are revalidated against that viewer's current options on every
read. `oauth_provider` / `oauth_subject` carry the sign-in identity. There is no
`user_role` column.

#### `client_companies`

- `id`, `public_id` (UUID), `workspace_id`
- `name`, `slug` — unique per workspace, not globally
- `billing_email` (nullable)
- `is_active` (boolean, default true)
- `created_at`, `updated_at`

No soft deletes, no `last_activity`, no `default_hourly_rate` (the rate lives on
the agreement), no address, website, phone or notes columns. Stripe billing adds
separate customer and payment-method tables so this row stays the business
entity record; see [Stripe billing](stripe-billing.md).

#### `client_company_memberships`

- `id`, `public_id`, `workspace_id` (derived from the company on create, never
  supplied), `client_company_id`, `user_id`
- `role` (default `client`)
- `access_scope` — `company` (default) or `projects`
- Unique on `[client_company_id, user_id]`

#### `client_portal_project_access`

- `id`, `workspace_id`, `client_company_membership_id`, `client_project_id`
- Unique on `[client_company_membership_id, client_project_id]`

`client_project_memberships` cannot express portal scoping: it carries a
composite foreign key into `workspace_memberships`, so a row there requires the
user to be a workspace member, and an external portal user never is.

#### `client_projects`

- `id`, `public_id`, `workspace_id`, `client_company_id`
- `name` — unique per company; there is no `slug`
- `description` (nullable)
- `repository` (nullable) — the canonical `host/owner/name` a project's work
  happens in, normalized on the way in by `App\Support\RepositoryReference`.
  Not unique and not indexed; `null` means nobody has said.
- `status` (default `active`), `is_visible_to_client` (default true)
- `lock_version`, `created_at`, `updated_at`

#### `client_tasks`

- `id`, `public_id`, `workspace_id`, `client_project_id`
- `title` — not `name`; `description` (nullable)
- `status` (default `open`), `is_visible_to_client` (default true)
- `completed_at` (nullable)
- `milestone_price_amount` (unsigned bigint minor units, nullable) — non-null
  marks the task a billable milestone
- `client_invoice_line_id` (nullable) — set when the milestone has been billed.
  A single column rather than a pivot, because a deliverable cannot be split
  across lines. See [milestone billing](milestone-billing.md).
- `lock_version`, `created_at`, `updated_at`

There is no `due_date`, `assignee_user_id`, `is_high_priority`, or
`creator_user_id`.

#### `client_time_entries`

- `id`, `public_id`, `workspace_id`, `client_company_id`, `client_project_id`
  (required), `client_task_id` (nullable), `user_id`
- `split_from_time_entry_id` (nullable) — the *root* entry a fragment was split
  from, never an intermediate fragment
- `worked_on` (date), `minutes` (unsigned integer)
- `description`, `client_visible_description` (nullable),
  `is_visible_to_client` — client visibility requires an explicit client-facing
  description; the internal one is never used as a fallback
- `job_type` (nullable)
- `is_billable` (default true), `is_deferred` (default false)
- `billing_rate_amount` + `currency` — the immutable price snapshot;
  `billing_rate_source` records whether it was `agreement`-resolved or
  `explicit`
- `status` — `draft`, `approved`, or `invoiced`. This schema collapsed the
  predecessor's separate `approval_status` into one column, so issuing an
  invoice rewrites approved work to `invoiced`. Code matching the literal
  `approved` forgets everything it has already billed; ask
  `ClientTimeEntry::scopeApproved()` instead.
- `approved_by_user_id`, `approved_at`
- `subcontractor_billing_mode` (nullable) — `flat_hourly`, `retainer`, or
  `direct`, snapshotted at log time
- `subcontractor_cost_amount`, `subcontractor_cost_currency`,
  `subcontractor_cost_metadata`
- `lock_version`, timestamps, `deleted_at` (soft deletes)

The link to an invoice line is the `client_invoice_line_time_entries` pivot, not
a column. A unique index on `(workspace_id, client_time_entry_id)` is what makes
"no pivot row" and "not billed" the same statement, and is why an entry spanning
two lines has to become two rows.

#### `client_company_activity`

- `id`, `public_id`, `workspace_id`, `client_company_id`, `actor_user_id`
- `action` — a key such as `agreement.created`, `agreement.activated`,
  `agreement.signed`, `agreement.updated`, `invoice.generated`,
  `invoice.updated`, `invoice.issued`, `invoice.marked_paid`, `invoice.voided`,
  `invoice.payment_received`, `invoice.payment_failed`,
  `invoice.payment_canceled`, `invoice.payment_disputed`,
  `invoice.payment_refunded`, `payment_method.attached`,
  `payment_method.detached`, or `payment_method.default_changed`
- `subject_type` — a stable native subject kind, or the imported predecessor
  class name on preserved rows
- `subject_public_id` — the UUID of a native agreement, invoice, payment or
  saved payment method
- `external_subject_id` — a predecessor numeric reference, retained only for
  imported history
- `deduplication_key` — unique inside a workspace, so an exact retry returns the
  existing row instead of appending a duplicate
- `payload` — whitelisted JSON display metadata. `ClientActivityRecorder`
  rejects raw provider payloads, credentials, secrets, tokens and document
  contents, and refuses a subject that does not belong to the named company and
  workspace.

## Routes

Route files are `routes/web.php`, `billing.php`, `engagement.php`,
`expenses.php`, `files.php` and `api.php`. They are the authoritative list; the
shapes below are the families, not every route.

**Operator (web, `auth`)** — reads require `view` on the workspace, writes
require `manage`:

```
GET    /app                                                  workspace selector
GET    /workspaces/{workspace}                               entry
GET    /workspaces/{workspace}/operations
GET    /workspaces/{workspace}/clients
GET    /workspaces/{workspace}/clients/{clientCompany}
GET    /workspaces/{workspace}/clients/{clientCompany}/{invoices,tasks,settings}
GET    /workspaces/{workspace}/clients/{clientCompany}/agreements/{clientAgreement}
GET    /workspaces/{workspace}/clients/{clientCompany}/proposals/{clientProposal}
GET    /workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}
GET    /workspaces/{workspace}/clients/{clientCompany}/invoices/{clientInvoice}
POST   /workspaces/{workspace}/clients
PATCH  /workspaces/{workspace}/clients/{clientCompany}
POST   /workspaces/{workspace}/clients/{clientCompany}/projects
PATCH  /workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}
PUT    /workspaces/{workspace}/clients/{clientCompany}/projects/{clientProject}/access
POST   /workspaces/{workspace}/projects/{clientProject}/tasks
PATCH  /workspaces/{workspace}/tasks/{clientTask}
```

**Portal** — authorized by `viewPortal` and then narrowed by `PortalAccess`:

```
GET    /portal/{clientCompany}
GET    /portal/{clientCompany}/{invoices,time,tasks}
GET    /portal/{clientCompany}/invoices/{clientInvoice}
GET    /portal/{clientCompany}/agreements/{clientAgreement}
GET    /portal/{clientCompany}/proposals/{clientProposal}
POST   /portal/{clientCompany}/proposals/{clientProposal}/accept
```

**Engagement**:

```
GET    /workspaces/{workspace}/clients/{clientCompany}/time      time sheet
POST   /workspaces/{workspace}/projects/{clientProject}/time-entries
PATCH  /workspaces/{workspace}/time-entries/{timeEntry}
DELETE /workspaces/{workspace}/time-entries/{timeEntry}
POST   /workspaces/{workspace}/time-entries/approve
POST   /workspaces/{workspace}/clients/{clientCompany}/proposals
POST   /workspaces/{workspace}/proposals/{clientProposal}/send
POST   /workspaces/{workspace}/clients/{clientCompany}/agreements
PATCH  /workspaces/{workspace}/agreements/{clientAgreement}
POST   /workspaces/{workspace}/agreements/{clientAgreement}/{activate,sign}
```

**Billing**:

```
GET    /workspaces/{workspace}/invoices
POST   /workspaces/{workspace}/clients/{clientCompany}/invoices
GET    /workspaces/{workspace}/invoices/{clientInvoice}
POST   /workspaces/{workspace}/invoices/{clientInvoice}/{issue,send,void}
POST   /workspaces/{workspace}/invoices/{clientInvoice}/payments
POST   /workspaces/{workspace}/invoices/{clientInvoice}/stripe-payment-intent
GET    /workspaces/{workspace}/invoices/{clientInvoice}/pdf
POST   /workspaces/{workspace}/clients/{clientCompany}/billing-schedules
GET    /workspaces/{workspace}/billing-schedules/{schedule}
POST   /workspaces/{workspace}/billing-schedules/{schedule}/generate
```

**Expenses** and **files** are listed under [Client expenses](#client-expenses)
and [File storage](#file-storage).

**Agent API** (`/api/v1`, OAuth bearer) mirrors the read surface and carries the
task, time-entry and invoice writes: `POST|PATCH|DELETE
/api/v1/workspaces/{workspace}/time-entries`, `POST
/api/v1/workspaces/{workspace}/invoices` and its `discard`, `issue`, `send` and
`void` transitions. `POST|DELETE /api/v1/mcp` is the MCP endpoint. See
[the MCP contract](../mcp-product-contract.md).

## Frontend

Pages are React under `resources/js/pages/`, rendered through Inertia from a
single Blade shell. There is no per-page Vite entry point and no
`resources/js/client-management/` tree.

- `pages/clients/` — the operator surface: `index`, `home`, `invoices`,
  `invoice`, `tasks`, `project`, `agreement`, `proposal`, `expenses`,
  `settings`.
- `pages/portal.tsx` and `pages/portal/` — the client-facing surface.
- `pages/operations.tsx`, `pages/time.tsx`, `pages/workspaces/`.
- Shared components in `resources/js/components/` (`billing/`, `clients/`,
  `agreements/`, `time/`, `expenses/`, `navigation/`, `ui/`).
- Shared types in `resources/js/types/`.

Every authenticated screen renders through `WorkspaceShell`; the layout rules
and the checks they have to pass are in [the interface guide](../ui.md). There
are no strict/relaxed hydration schema pairs — the server sends finished URLs,
capabilities and labels rather than values for the browser to interpret.

## Subcontractors

A subcontractor is a person whose time is logged against a project and priced
differently from consultant time. **The billing mode is a snapshot on the time
entry and nothing else.** There is no engagement record, no per-project
assignment table, no onboarding or termination date, and no reactivation
history: `client_subcontractor_engagements` and `client_subcontractors` are
predecessor tables that were not ported.

`client_time_entries.subcontractor_billing_mode` is one of
`App\Support\Billing\SubcontractorBillingMode`:

- **`flat_hourly`** — billed on its own `subcontractor` line at the snapshotted
  `subcontractor_cost_amount` / `subcontractor_cost_currency`. These hours never
  draw on the retainer.
- **`retainer`** — drawn from the agreement pool at the agreement rate, exactly
  like consultant time.
- **`direct`** — tracked and visible, never invoiced by us.

`null` means consultant time. A row carrying a cost but no mode is a malformed
combination, and both scopes that could act on it fail closed:
`scopeRetainerBillable()` will not let it consume retainer capacity, and
`scopePricedForInvoicing()` will not let it be priced. The migration that added
the column labelled every existing cost-bearing row `flat_hourly`, because a
non-null cost was the old schema's only flat-hourly signal.

Approval is the ordinary time-entry lifecycle — `draft`, `approved`,
`invoiced` — not a separate `approval_status`, and it is not specific to
subcontractors. See [billing.md § Subcontractor billing](billing.md#subcontractor-billing)
for how each mode reaches an invoice.

## File storage

Attachments live in one table, `client_attachments`, which belongs directly to a
workspace and identifies its owner by `record_type` plus an immutable
`record_public_id`. `record_type` is one of `company`, `project`, `task`,
`proposal`, `agreement`, `invoice`. There is no polymorphic
`fileable_type`/`fileable_id` pair, and there is no download-history table.

- `object_key` (unique) and `staged_object_key` (unique, nullable) — opaque
  keys shaped `workspaces/{workspace_uuid}/{record_type}/{record_uuid}/{blob_uuid}`,
  never containing a name, address, invoice number or filename
- `original_filename` — stored encrypted
- `media_type`, `bytes`, `sha256`
- `uploader_id`
- `lifecycle_state` — `staged`, `available`, `deleting`, `deleted`, or `corrupt`
- `available_at`, `deleted_at`, timestamps

Routes:

```
POST   /workspaces/{workspace}/attachments/{recordType}/{recordPublicId}
GET    /workspaces/{workspace}/attachments/{clientAttachment}
DELETE /workspaces/{workspace}/attachments/{clientAttachment}
```

Uploads are two-phase because a database write and a blob write cannot share a
transaction: stream to a staged object while hashing, create the row, promote
the staged object to its immutable key, mark it available. Deletion is two-phase
in the same way. `svc:attachments:repair` finds abandoned staged objects and flags
rows whose blobs are missing or mis-digested. The full contract, including the
disk selection and the backup requirements, is in
[the private file storage plan](../file-storage-plan.md).

## Billing and invoicing

The rules are in [billing.md](billing.md) and the documents it links.
[The domain contract](../domain-contract.md) states the invariants for
`client_invoices`, `client_invoice_lines`, `client_invoice_payments`,
`client_billing_schedules`, `client_invoice_email_deliveries` and the Stripe
adapter tables. What follows is only what this page still needs to say.

### Statuses and kinds are enums, and the lists are not four elements long

- **`client_invoices.status`** is a `varchar(32)` carrying one of **five**
  values: `draft`, `issued`, `partially_paid`, `paid`, `void`. The predecessor's
  column was `enum('draft','issued','paid','void')`, and every exhaustive
  four-element list ported from that world silently omitted `partially_paid` —
  which let a partially paid invoice be reset to draft and rebuilt, failed to
  block interim generation, and sold a retainer period a second time. Ask
  `App\Support\Billing\InvoiceStatus` rather than writing a list:
  `settled()`, `charged()`, `collectible()`, `live()`, and the two fail-closed
  readers `isSettledValue()` / `hasChargedValue()`, which answer *yes* for a
  status this application does not recognise.
- **`client_invoices.invoice_kind`** has **four** values —
  `cadence_period`, `interim_overage`, `terminal`, `ad_hoc` — plus a null on
  migrated rows, which reads as legacy cadence. `App\Support\Billing\InvoiceKind`
  owns the questions asked of it.
- **`client_invoice_payments.status`** has **six** values — `pending`,
  `succeeded`, `failed`, `refunded`, `disputed`, `canceled`.
  `App\Support\Billing\InvoicePaymentStatus` is the whole vocabulary, enforced at
  the service boundary rather than only the HTTP one, and reads fail closed: a
  stored status outside it stops the balance recomputation rather than being
  counted as zero. See [payments.md § Payment status](payments.md#payment-status).
- **`client_invoice_lines.type`** — the column is `type`, a `varchar(40)`, not
  `line_type` and not a database enum.
  `App\Support\Billing\InvoiceLineType` carries eleven cases: `retainer`,
  `prior_month_retainer`, `prior_month_billable`, `additional_hours`, `credit`,
  `expense`, `milestone`, `adjustment`, `recurring_item`, `reconciliation`,
  `subcontractor`. There is no `delayed_billing` type; deferred work is billed
  as ordinary lines, see [deferred billing](deferred-billing.md).
- **`client_agreements.billing_cadence`** — `BillingCadence` has four cases:
  `monthly`, `quarterly`, `semi_annual`, `annual`. The column *defaults* to
  `one_time`, which the enum has no case for, so an agreement created without an
  explicit cadence generates no cycle invoices at all.

### Services

Under `app/Services/Billing/`:

- **`TimeEntrySplitter`** — deterministic allocation and fragment creation.
- **`AllocationService`** — recombining fragments once nothing bills them.
- **`RolloverCalculator`** — opening and closing balances with FIFO rollover.
- **`RetainerCalculator`** — the one definition of a month's retainer hours.
- **`BillingCycleResolver`** — cadence cycle windows and first-cycle proration.
- **`RecurringItemBiller`** — recurring item incidences for a cycle.
- **`InvoiceLineComposer`** — replaces the system-generated lines on a draft.
- **`ClientInvoicingService`** — orchestrates cadence generation.
- **`BillingScheduleService`** — generates the periods a schedule is due.
- **`InvoiceLifecycleService`** — draft create/update/discard, issue, void,
  payments, and the derived status.
- **`OverpaymentCreditService`**, **`DeferredBillingAllocator`**,
  **`InterimOverageGenerator`**, **`BillingPeriodCollisionResolver`**,
  **`InvoiceNumberAllocator`**, **`MoneyService`**.

Engagement services (proposals, agreements, time) are in
`app/Services/Engagement/`.

### Time entry splitting and allocation

When one entry spans several capacity pools it is split into fragments, each a
real `client_time_entries` row carrying `split_from_time_entry_id`.

`TimeEntrySplitter::allocateTimeEntries()` allocates in this order, over entries
sorted by work date and then id so the result is stable:

1. **Prior-month retainer capacity**
2. **Current-month retainer capacity**
3. **Catch-up billing**, up to the agreement's
   `catch_up_threshold_minutes` (read as `catch_up_threshold_hours`)
4. **Billable catch-up**, everything beyond the threshold

The first two categories become `prior_month_retainer` lines — one per pool,
described by the month whose pool it drew on, priced at zero. Categories 3 and 4
are billed together on a **single** `additional_hours` line, because to the
client they are the same charge.

**Recombination is by lineage, not by matching fields.**
`AllocationService::recombineUnlinkedFragments()` groups a root entry with the
fragments that record it, locks the group, and merges only when no member is
billed and the members still agree on what made them one entry. The predecessor
matched on date, user, description, project and task, which folds together two
genuinely separate entries whenever someone logs the same description twice
against one project on one day.

### Deferred billing

Work can be logged with `is_deferred = true` and billed only once retainer
capacity exists. It does **not** draw on the pool until the allocator actually
bills it — counting it up front consumed capacity nothing had taken and produced
catch-up charges to restore it. Once allocated, the flag stays set and the hours
still count as billed; `scopeDeferredOnlyOnceAllocated()` is the one place that
decides this. See [deferred billing](deferred-billing.md).

### Generating invoices

There are three write paths, and they are not the same one.

1. **A billing schedule.** `POST
   /workspaces/{workspace}/billing-schedules/{schedule}/generate` runs
   `BillingScheduleService::generateDue()`, which is idempotent per schedule and
   service period. `svc:billing:preflight-schedule-generation` classifies every
   period a schedule is due to bill and reports how many would halt and why.
2. **An operator or agent draft.** `POST
   /workspaces/{workspace}/clients/{clientCompany}/invoices` and the agent
   equivalent create a draft through `InvoiceLifecycleService::createDraft()`.
   The kind defaults to `ad_hoc` — but only when the row names no billing
   schedule, because classifying a schedule's machine-generated invoice as ad
   hoc took it out of the cadence overlap guard and let the same agreement and
   period be billed twice. Such a draft may be built from selected
   `time_entry_ids` (public UUIDs) through `InvoiceFromTimeService`, which marks
   the time allocated so it cannot be billed again. No operator screen sends
   them yet.
3. **Draft regeneration.** Editing or deleting time a draft has claimed rebuilds
   that draft in the same transaction, through
   `DraftInvoiceTimeRegenerator` → `ClientInvoicingService::regenerateDraftInvoice()`
   (or `InvoiceFromTimeService` for an ad-hoc selection). A move across periods
   also rebuilds the companion draft that now covers the new date. Editing time
   on an **issued, paid or void** invoice is refused.

`ClientInvoicingService::generateAllInvoices()` — the whole-company cadence
sweep — has no HTTP route and no button. Its only callers are
`svc:billing:replay` and `svc:billing:rehearse-generation`, both of which run
inside a rolled-back transaction. The predecessor's "Run Invoicing" control and
its `POST /api/client/mgmt/companies/{company}/invoices/generate-all` endpoint
do not exist here.

Generation never touches a settled invoice. `InvoiceStatus::isSettledValue()`
guards it, and `SettledInvoicesUntouchedTest` holds the property for every
settled status including void.

### What regeneration preserves

`InvoiceLineComposer` deletes and rebuilds only the line types in
`InvoiceLineType::systemGeneratedValues()`: `retainer`,
`prior_month_retainer`, `prior_month_billable`, `additional_hours`, `credit`,
`milestone`, `recurring_item`, `reconciliation`, `subcontractor`.

`adjustment` and `expense` lines are **not** in that list and survive
regeneration. That is the whole rule; there is no separate manual-versus-system
flag on the row.

### Payments

Recording a payment is the one write the invoice screen offers, and there is no
payment-deletion path: a payment is corrected by transitioning its status or its
refunded amount, so history is preserved rather than rewritten.

Paid and balance amounts are **derived**. `InvoiceLifecycleService::refreshStatus()`
recomputes them from the invoice's succeeded, non-refunded payments and settles
the status: `paid` at or above the total, `partially_paid` between, `issued` at
zero, and `void` stays `void`. Draft → issued is never a payment transition; it
happens only through `issue()`.

> `client_invoices.paid_on` exists in the schema and **nothing in this
> application writes it**. Documentation inherited from the predecessor
> describes a `paid_date` set to the date of the latest payment; it is not
> maintained here. Read `paid_amount` and `balance_amount` instead.

The methods, the validation, the two shapes that may not be recomputed at all,
and the console equivalent are in [payments.md](payments.md). Overpayment
becomes a credit rather than a negative balance — `balance_amount` is unsigned
and would refuse the write — see [overpayment credits](overpayment-credits.md).

## Security

- Every route is behind the `auth` middleware; the agent API is behind OAuth
  bearer tokens with scoped abilities.
- Reads require `view` on the workspace, writes require `manage`, and project
  work is narrowed further by `ProjectAccess`.
- The portal requires `viewPortal` and is then narrowed by `PortalAccess` on
  every surface, not only on the project list.
- Every tenant-owned update and delete names `workspace_id` in its own
  statement.
- Composite `(workspace_id, parent_id)` foreign keys make a cross-tenant
  reference unstorable rather than merely unwritten by the application; see
  [tenant foreign keys](tenant-foreign-keys.md).
- Pessimistic locks are taken in one recorded order, enforced by a conformance
  test and a static rule; see [lock order and check-then-act](concurrency.md).
- CSRF protection covers every browser state change.
- Attachments are streamed through an authorized controller. Blobs never live
  under `public/` and object keys carry no client-identifying text.

## Client expenses

`client_expenses` records a reimbursable expense against a workspace and client
company, optionally attributed to one of that company's projects.

- `id`, `public_id`, `workspace_id`, `client_company_id`, `client_project_id`
  (nullable), `created_by_user_id`
- `spent_on` (date), `amount` (integer minor units), `currency`, `description`
- `status` — `draft`, `approved`, or `invoiced`
- `approved_by_user_id`, `approved_at`
- timestamps and `deleted_at`

The lifecycle edges are `draft` → `approved`, `approved` → `draft`, and
`approved` → `invoiced`. Nothing leaves `invoiced`, no status moves to itself,
and a status the vocabulary does not recognise refuses every move. Only a
draft's facts may be rewritten. Withdrawal of an approval clears the approver
and the timestamp rather than keeping them as history.

Reads and writes go through `App\Queries\Expenses\WorkspaceExpenses`, the only
place that resolves a company or project for a workspace. Every transition locks
the row and re-reads its status under that lock, through the lock-order
registry.

Routes:

```
GET    /workspaces/{workspace}/clients/{clientCompany}/expenses
POST   /workspaces/{workspace}/clients/{clientCompany}/expenses
PATCH  /workspaces/{workspace}/expenses/{expense}
POST   /workspaces/{workspace}/expenses/{expense}/approve
POST   /workspaces/{workspace}/expenses/{expense}/unapprove
DELETE /workspaces/{workspace}/expenses/{expense}
```

**Nothing bills an expense yet.** The `approved` → `invoiced` edge has no
caller: there is no generator hook, no receipt attachments, and no recurrence.
Tracked as #75.

This schema diverges from the predecessor's on purpose. Money is integer minor
units plus a `currency` rather than `decimal(12,2)`; the date is `spent_on` and
the project link `client_project_id`, matching every sibling table; and manager
approval replaces `is_reimbursable` / `is_reimbursed` / `reimbursed_date`,
because an expense reaching an invoice is the decision those flags stood in for.
There is no `category`, no `notes`, no `client_invoice_line_id`, and no
`external_finance_transaction_uuid` column. A reimbursable expense will reach an
invoice at cost: there is no markup column and no generator applies one.

## External finance reconciliation

The reconciliation boundary is on the **payment**, not the expense.
`client_invoice_payments.external_finance_transaction_uuid` holds the opaque
identifier of a transaction in a separate finance application, and
`payment_reconciliations` records the allocations — external system slug,
external transaction UUID, allocated amount and currency — so one finance
transaction can cover several payments and one payment can draw on several
finance transactions. Active allocations cannot exceed a successful payment
amount net of refunds, and deactivation preserves history instead of deleting
it.

SVC stores identifiers and nothing else: no banking data, no transaction
records, no ledger, and no foreign key into another application's database. The
coupling is deliberately one-directional and opaque so the finance side stays a
replaceable adapter. See
[the finance reconciliation API](../finance-api.md) and the `finance.read` /
`finance.reconcile` abilities.

## Time entry invoiced status

An entry is invoiced when a `client_invoice_line_time_entries` row links it to a
line, and its `status` is rewritten to `invoiced` when the invoice is issued.
Voiding an unpaid invoice restores linked `invoiced` time to `approved` and
releases the pivot rows.

There is no `is_invoiced` appended attribute and no `client_invoice_line_id`
column on the entry. `ClientTimeEntry::scopeUnbilled()` asks the question —
`whereDoesntHave('invoiceLines')` — and the model exposes a
`client_invoice_line_id` *accessor* that reads the pivot, kept only so ported
arithmetic can still read the name it expects. Eager-load `invoiceLines` when
allocating in bulk, or it costs a query per entry.
