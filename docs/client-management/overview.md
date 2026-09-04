# Client Management System Documentation

## Overview
The Client Management system is an admin-only feature for managing client companies and their associated users. It enables tracking of client information, billing rates, and user assignments.

## Architecture

### Authorization
- **Admin Gate**: Located in `AppServiceProvider.php`, defines who can access admin client management features
  - Returns `true` if user ID is 1 (first user)
  - Returns `true` if user has `user_role = 'Admin'`
  - All Client Management admin routes and API endpoints check this gate

- **ClientCompanyMember Gate**: Located in `AppServiceProvider.php`, defines who can access client portal features
  - Returns `true` if user ID is 1 (first user)
  - Returns `true` if user has `user_role = 'Admin'`
  - Returns `true` if user is a member of the specified client company
  - Grants the baseline portal surface (company info, assigned projects, own time)

- **ClientCompanyClient Gate**: Located in `AppServiceProvider.php`, restricts client-only portal resources
  - Returns `true` for admins, or members whose `client_company_user.role` is `client`
  - Denies members whose role is `subcontractor`
  - Guards tasks, agreements, invoices, proposals, expenses, and invoice payments
  - See [Subcontractors](#subcontractors) for the role model

### Database Schema

#### `users` table
- Added `user_role` column (string, default: 'User')
- Values: 'User' or 'Admin'
- Indexed for performance

#### `client_companies` table
- `id`: Primary key (auto-increment)
- `company_name`: Company name (required, indexed)
- `slug`: URL-friendly identifier (unique, indexed, auto-generated from name)
- `address`: Full address (text, nullable)
- `website`: Company website URL (nullable)
- `phone_number`: Contact phone (nullable)
- `default_hourly_rate`: Default billing rate (decimal 8,2, nullable)
- `additional_notes`: Free-form notes (text, nullable)
- `is_active`: Active status (boolean, default true, indexed)
- `last_activity`: Timestamp of last update (auto-updated on save)
- `created_at`: Creation timestamp
- `updated_at`: Last modification timestamp
- `deleted_at`: Soft delete timestamp (nullable)

Stripe billing adds separate customer/payment-method tables so the client company row remains the business entity record. See [Stripe billing](stripe-billing.md).

**Features:**
- Soft deletes enabled via Eloquent `SoftDeletes` trait
- Automatically maintains `last_activity` via `touchLastActivity()` method
- Slug auto-generated from company name on creation

#### `client_projects` table
- `id`: Primary key
- `client_company_id`: Foreign key to `client_companies` (cascade on delete)
- `name`: Project name (required)
- `slug`: URL-friendly identifier (unique per company)
- `description`: Project description (text, nullable)
- `creator_user_id`: Foreign key to `users` (set null on delete)
- `created_at`, `updated_at`: Timestamps

#### `client_tasks` table
- `id`: Primary key
- `project_id`: Foreign key to `client_projects` (cascade on delete)
- `name`: Task name (required)
- `description`: Task description (text, nullable)
- `due_date`: Optional due date
- `completed_at`: When task was completed (nullable)
- `assignee_user_id`: Foreign key to `users` (set null on delete)
- `is_high_priority`: High priority flag (boolean, default false)
- `is_hidden_from_clients`: Hidden from client view (boolean, default false)
- `milestone_price`: Flat-fee billing amount for this task (decimal 10,2, default 0.00). Non-zero marks the task as a billable milestone.
- `client_invoice_line_id`: Foreign key to `client_invoice_lines` (set null on delete). Populated when the task has been billed on an invoice.
- `creator_user_id`: Foreign key to `users` (set null on delete)
- `created_at`, `updated_at`: Timestamps

#### `client_time_entries` table
- `id`: Primary key
- `client_company_id`: Foreign key to `client_companies` (cascade on delete)
- `project_id`: Foreign key to `client_projects` (set null on delete)
- `task_id`: Foreign key to `client_tasks` (set null on delete)
- `user_id`: Foreign key to `users` (cascade on delete)
- `minutes`: Time tracked in minutes (integer, required)
- `description`: Work description (text, nullable)
- `job_type`: Type of work performed (string, nullable)
- `is_billable`: Whether time is billable (boolean, default true)
- `entry_date`: Date of work (required)
- `creator_user_id`: Foreign key to `users` (set null on delete)
- `approval_status`: `approved` | `pending` | `rejected` (default `approved`; subcontractor self-logs start `pending`)
- `approved_by_user_id`, `approved_at`: Who/when an entry was approved or rejected
- `subcontractor_billing_mode`: Snapshot of the author's billing mode at log time (`flat_hourly` | `retainer` | `direct`, or null for the consultant)
- `subcontractor_hourly_rate`: Snapshot of the flat-hourly rate so later rate edits never re-price logged work
- `created_at`, `updated_at`: Timestamps

#### `client_company_user` pivot table
- `id`: Primary key
- `client_company_id`: Foreign key to `client_companies` (cascade on delete)
- `user_id`: Foreign key to `users` (cascade on delete)
- `role`: `client` (default) | `subcontractor` — distinguishes the portal experience
- `created_at`, `updated_at`: Timestamps
- Unique constraint on `[client_company_id, user_id]` pair

#### `client_subcontractor_engagements` table
The subcontractor's tenure (engagement) with a company — the period we work with
them and the default terms. Mirrors `ClientAgreement`'s lifecycle but is kept out
of client invoicing.
- `id`: Primary key
- `client_company_id`: Foreign key to `client_companies` (cascade on delete)
- `user_id`: Foreign key to `users` (cascade on delete)
- `active_date`: Onboarding date (the engagement starts)
- `termination_date`: Offboarding date, nullable. The **first inactive day**, so
  terminating "today" takes effect immediately (matches `ClientAgreement`)
- `default_billing_mode`, `default_hourly_rate`: Optional defaults (nullable)
- `created_at`, `updated_at`, `deleted_at`: Timestamps + soft deletes

#### `client_subcontractors` table
A per-project assignment under one engagement.
- `id`: Primary key
- `engagement_id`: Foreign key to `client_subcontractor_engagements` (cascade on delete)
- `project_id`: Foreign key to `client_projects` (cascade on delete)
- `user_id`: Foreign key to `users` (cascade on delete)
- `billing_mode`: `flat_hourly` | `retainer` | `direct` (default `retainer`)
- `hourly_rate`: Per-contractor rate, required for `flat_hourly` (nullable otherwise)
- `created_at`, `updated_at`: Timestamps
- Unique constraint on `[engagement_id, project_id]` pair (a person can be assigned
  to a project once per engagement; successive engagements = reactivation history)
- An assignment is **active** when its engagement is active (derived from dates, not
  a stored flag); the serialized `is_active` attribute reflects this

### Models

#### `App\Models\ClientManagement\ClientCompany`
Location: `app/Models/ClientManagement/ClientCompany.php`

**Relationships:**
- `users()`: Many-to-many relationship with `User` model via `client_company_user` pivot table
- `projects()`: One-to-many relationship with `ClientProject` model
- `timeEntries()`: One-to-many relationship with `ClientTimeEntry` model

**Methods:**
- `touchLastActivity()`: Updates `last_activity` to current timestamp
- `generateSlug(string $name)`: Static method that converts name to URL-friendly slug

**Traits:**
- `SoftDeletes`: Enables soft deletion

#### `App\Models\ClientManagement\ClientProject`
Location: `app/Models/ClientManagement/ClientProject.php`

**Relationships:**
- `clientCompany()`: Belongs to `ClientCompany`
- `tasks()`: One-to-many relationship with `ClientTask`
- `timeEntries()`: One-to-many relationship with `ClientTimeEntry`
- `creator()`: Belongs to `User` (creator_user_id)

**Methods:**
- `generateSlug(string $name)`: Static method that converts name to URL-friendly slug

#### `App\Models\ClientManagement\ClientTask`
Location: `app/Models/ClientManagement/ClientTask.php`

**Relationships:**
- `project()`: Belongs to `ClientProject`
- `assignee()`: Belongs to `User` (assignee_user_id)
- `creator()`: Belongs to `User` (creator_user_id)

**Methods:**
- `markCompleted()`: Sets `completed_at` to now
- `markNotCompleted()`: Sets `completed_at` to null
- `isCompleted()`: Returns boolean if task is complete

#### `App\Models\ClientManagement\ClientTimeEntry`
Location: `app/Models/ClientManagement/ClientTimeEntry.php`

**Relationships:**
- `clientCompany()`: Belongs to `ClientCompany`
- `project()`: Belongs to `ClientProject`
- `task()`: Belongs to `ClientTask`
- `user()`: Belongs to `User`
- `creator()`: Belongs to `User` (creator_user_id)

**Methods:**
- `parseTimeToMinutes(string $timeString)`: Static method that parses "h:mm", decimal hours, or hours with 'h' suffix (e.g., "1.5h") to minutes. Case-insensitive.

#### `App\Models\User` (extended)
Added relationship:
- `clientCompanies()`: Many-to-many relationship with `ClientCompany` model

### Controllers

#### `App\Http\Controllers\ClientManagement\ClientCompanyController`
Location: `app/Http/Controllers/ClientManagement/ClientCompanyController.php`

Web routes controller for Blade views:
- `index()`: List all client companies
- `create()`: Show new company form
- `store()`: Create new company (auto-generates slug from company_name)
- `show($id)`: Display company details
- `update($id)`: Update company information (automatically updates `last_activity`)
- `destroy($id)`: Soft delete company

All methods use `Gate::authorize('Admin')` for authorization.

#### `App\Http\Controllers\ClientManagement\ClientCompanyApiController`
Location: `app/Http/Controllers/ClientManagement/ClientCompanyApiController.php`

API endpoints for React components:
- `index()`: Get all companies with eager-loaded users
- `getUsers()`: Get all users (for invite modal)
- `update()`: Update company (validates slug uniqueness)

#### `App\Http\Controllers\ClientManagement\ClientCompanyUserController`
Location: `app/Http/Controllers/ClientManagement/ClientCompanyUserController.php`

User assignment API:
- `store()`: Attach user to company (checks for existing assignment)
- `destroy($companyId, $userId)`: Detach user from company

#### `App\Http\Controllers\ClientManagement\ClientPortalController`
Location: `app/Http/Controllers/ClientManagement/ClientPortalController.php`

Web routes controller for Client Portal:
- `index($slug)`: Portal main page (lists projects and tasks)
- `time($slug)`: Time tracking page
- `project($slug, $projectSlug)`: Project detail page

All methods use `Gate::authorize('ClientCompanyMember', $company->id)` for authorization.

#### `App\Http\Controllers\ClientManagement\ClientPortalApiController`
Location: `app/Http/Controllers/ClientManagement/ClientPortalApiController.php`

API endpoints for Client Portal:
- `getProjects($slug)`: Get projects for company
- `createProject($slug)`: Create new project
- `getTasks($slug)`: Get tasks for company (filterable by project)
- `createTask($slug)`: Create new task
- `updateTask($slug, $taskId)`: Update task (toggle completion, update fields)
- `getTimeEntries($slug)`: Get time entries for company
- `createTimeEntry($slug)`: Create new time entry

All methods use `Gate::authorize('ClientCompanyMember', $company->id)` for authorization.

### Routes

#### Web Routes (`routes/web.php`)
All protected by `auth` middleware:

See `routes/web.php` for the authoritative list (it carries more agreement/proposal/invoice routes than shown here). Key routes:

**Admin Routes:**
- `GET /client/mgmt` → List page
- `GET /client/mgmt/new` → New company form
- `GET /client/mgmt/invoices` → All-invoices page
- `POST /client/mgmt` → Create company
- `GET /client/mgmt/{id}` → Company details
- `DELETE /client/mgmt/{id}` → Delete company

(Company **updates** are API-only via `PUT /api/client/mgmt/companies/{id}`.)

**Portal Routes:**
- `GET /client/portal/{slug}` → Portal main page (projects/tasks)
- `GET /client/portal/{slug}/time` → Time tracking page
- `GET /client/portal/{slug}/project/{projectSlug}` → Project detail page
- `GET /client/portal/{slug}/invoices`, `/billing`, `/invoice/{invoiceId}`, `/expenses`, `/proposals`, `/proposal/{proposalId}`, `/agreement/{agreementId}` → billing, proposal, and agreement views

#### API Routes (`routes/api.php`)
All protected by `['web', 'auth']` middleware:

**Admin API:**
- `GET /api/client/mgmt/companies` → Get all companies
- `GET /api/client/mgmt/users` → Get all users
- `PUT /api/client/mgmt/companies/{id}` → Update company
- `POST /api/client/mgmt/assign-user` → Assign user to company
- `DELETE /api/client/mgmt/{companyId}/users/{userId}` → Remove user from company

**Portal API:**
- `GET /api/client/portal/{slug}/projects` → Get projects
- `POST /api/client/portal/{slug}/projects` → Create project
- `GET /api/client/portal/{slug}/tasks` → Get tasks
- `POST /api/client/portal/{slug}/tasks` → Create task
- `PUT /api/client/portal/{slug}/tasks/{taskId}` → Update task
- `GET /api/client/portal/{slug}/time-entries` → Get time entries
- `POST /api/client/portal/{slug}/time-entries` → Create time entry

### Views

#### Blade Templates
Location: `resources/views/client-management/`

**Admin Views:**
- `index.blade.php`: Mounts `ClientManagementIndexPage` React component
- `create.blade.php`: Mounts `ClientManagementCreatePage` React component
- `show.blade.php`: Mounts `ClientManagementShowPage` React component with `data-company-id`

**Portal Views:**
Location: `resources/views/client-management/portal/`
- `index.blade.php`: Mounts `ClientPortalIndexPage` with `data-company-slug` and `data-company-name`
- `time.blade.php`: Mounts `ClientPortalTimePage` with `data-company-slug` and `data-company-name`
- `project.blade.php`: Mounts `ClientPortalProjectPage` with project data attributes

Vite entry points: one file per page under
- Admin: `resources/js/client-management/admin/` (`index`, `create`, `show`, `agreement`, `proposal`, `all-invoices`)
- Portal: `resources/js/client-management/portal/` (`index`, `time`, `project`, `invoices`, `invoice`, `billing`, `expenses`, `proposals`, `proposal`, `agreement`)

### TypeScript Typings
Shared TypeScript interfaces are generated within the `@/client-management/types/` directory to ensure type consistency across components. When adding new interfaces, create or update files in this directory and import them using type-only imports (`import type { InterfaceName } from '@/client-management/types/file'`).

Hydration vs runtime schemas
- We use two schema flavors for some payloads (notably invoices/payments): a **strict runtime schema** (`InvoiceSchema`, `ClientInvoicePaymentSchema`) that represents the app's authoritative contract, and a **relaxed hydration schema** (`InvoiceHydrationSchema`, `ClientInvoicePaymentHydrationSchema`) that tolerates real-world server payload shapes (missing keys, numeric totals, omitted timestamps).
- The client will attempt a strict parse first and fall back to the relaxed schema + normalization when necessary — keeping UI fast and safe while surfacing backend mismatches during development.


#### React Components
Location: `resources/js/client-management/components/`

**Admin Components:**

**ClientManagementIndexPage.tsx**
- Lists all active companies with their users
- Shows inactive companies in collapsible section
- "Invite People" button opens modal
- "New Company" button navigates to create page
- Search, cadence badges, cycle-progress mini bar, and "needs attention" filtering help prioritize companies
- Uses shadcn/ui Card, Badge, Button components

**ClientManagementCreatePage.tsx**
- Simple form with only company name (required)
- Creates company and redirects to details page
- Handles slug conflict errors with Alert
- Uses shadcn/ui Card, Input, Button, Alert components

**ClientManagementShowPage.tsx**
- Tabbed company workspace with Overview, Agreements, Invoices, Time & Expenses, Recurring Items, and Activity Log sections
- Company details remain editable from the overview surface, including slug and portal link
- Agreement tab embeds cadence-aware agreement cards and recurring item management
- Invoice tab embeds the admin invoice list with status/kind/date/agreement filters and invoice actions
- Activity Log tab renders both imported and SVC-native `client_company_activity` entries. Native agreement, invoice, payment, Stripe, and saved-payment-method events are recorded inside the owning state transaction; notable events are visible by default and system-level generation/update events remain available on demand.
- Uses shadcn/ui Tabs, Card, Input, Textarea, Checkbox, Badge components

**ClientAgreementShowPage.tsx**
- Agreement detail workspace with status header, financial terms, signing/lifecycle controls, cycle preview, and recurring item cards
- "Change cadence..." opens the cadence transition modal for preview/confirm flow
- Cycle preview summarizes current cycle window, logged hours, retainer remaining, projected overage, and next-invoice preview
- Recurring items editor supports create/update/delete with charge cadence, anchor month/day, taxable, and summarized fields

**AdminInvoiceList.tsx**
- Admin-side invoice surface with filters for status, kind, date range, and agreement
- Supports issue, mark paid, void, regenerate row action, and "Generate all invoices"

**Shared admin controls**
- `CurrencyInput`, `DateInput`, `ClientBadges`, `useToast`, and AlertDialog-based confirmations are shared by the cadence/invoice workspace

**InvitePeopleModal.tsx**
- Modal for assigning users to companies
- Dropdowns for user and company selection
- Prevents duplicate assignments
- Uses shadcn/ui Dialog, Button, Label components

**Portal Components:**
Location: `resources/js/client-management/components/portal/`

**ClientPortalIndexPage.tsx**
- Main portal page showing projects, recent time entries, and active agreement
- **Layout**: 
  - Header with company name and action buttons (New Time Entry, New Project, Upload File)
  - Two-column grid layout:
    - **Left column (2/3 width)**: Projects cards with statistics, Recent Time Entries table
    - **Right column (1/3 width)**: Company Files list
  - **Bottom section**: Compact active agreement display (single line)
- **Recent Time Entries**: Shows last 5 time entries in a clean table format
  - Columns: Date, Description (with job type, badges, project), User (abbreviated), Time, Edit button (admins only)
  - "View All →" button links to full time tracking page
  - Inline editing for admins via click-to-edit functionality
- **Active Agreement Display**: Compact single-line view at bottom showing retainer hours/month with "View Agreement" button
- **Project Cards**: Grid of project cards showing tasks count and time entries count
- **File Management**: Integrated file upload, download, and management for company files
- **Performance Fix**: Resolved infinite loop issue by properly managing useEffect dependencies for file manager
- New Project and New Time Entry buttons for quick access
- Uses shadcn/ui Card, Button, Badge, Table, ExternalLink icon components

**ClientPortalProjectPage.tsx**
- Project detail page
- Tasks filtered to specific project
- New Task button for project
- Uses shadcn/ui Card, Button, Checkbox components

**ClientPortalTimePage.tsx**
- Time tracking interface with monthly groupings
- Shows time entries in a clean tabular format within each month
- Each month displays:
  - **Hours Available This Month**: Retainer hours + cumulative rollover - previous overages. Updated from "Positive Balance" for clarity.
  - **Table of entries**: Date, Description (including job type, status badges, and project badge), User (abbreviated), and Time
  - **Closing Balance**: Unused hours available to roll over, excess hours to be invoiced
- **UI Features**:
    - Abbreviated user names (e.g., "Jordan Rivera" → "Jordan R.")
    - Consolidation of repeated dates (only shows date on first row of the day)
    - Action column with Edit button (Pencil icon) for admins
    - Clean, borderless card layout with lightened table borders
- **Color-coded balance indicators**: Green for positive availability, red for negative deficit
- **Validation**: Blocks time entry edits/deletes for entries on Issued/Paid invoices. Entries on Draft (upcoming) invoices can be freely edited — they are unlinked and the draft invoice is regenerated automatically.
- **Badge display**: "Upcoming" (blue) for entries on draft invoices, "Invoiced" (green) for entries on issued/paid invoices. Both link to the invoice.
- Collapsible month sections for better navigation
- Uses shadcn/ui Card, Button, Table, Badge, Tooltip, Skeleton components

**NewProjectModal.tsx**
- Modal for creating new projects
- Name and description fields
- Uses shadcn/ui Dialog, Input, Textarea components

**NewTaskModal.tsx**
- Modal for creating new tasks
- Name, description, priority, assignee fields
- Uses shadcn/ui Dialog, Input, Textarea, Select components

**NewTimeEntryModal.tsx**
- Unified modal for logging and editing time
- Fields: Project, Date, Time, Description, User, Job Type, Billable status
- **Smart Defaults**: 
    - Date defaults to the local computer's current date
    - Remembers last used project ID
- **Integrated Actions**: Includes a "Delete Record" button when in edit mode
- **Quick Buttons**: Provides +/- 5 and 15 minute increment buttons for easy time adjustment
- Time input accepts "h:mm", decimal hours, or hours with 'h' suffix (e.g., "1:30", "1.5", or "1.5h")
- Uses shadcn/ui Dialog, Input, Textarea, Select, Checkbox components

**TimeEntryListItem.tsx**
- Reusable component for displaying individual time entry rows
- Used in both ClientPortalIndexPage (recent entries) and ClientPortalTimePage (full list)
- **Features**:
  - Displays time entry with date, description, user, and time
  - Shows job type, billable/invoiced status badges, and project badge
  - Abbreviated user names (e.g., "Jordan Rivera" → "Jordan R.")
  - Click-to-edit functionality for admins (non-invoiced entries only)
  - Edit button with pencil icon (visible on hover)
  - Invoiced entries link to their invoice page
- Props: `entry`, `slug`, `isAdmin`, `showDate`, `onEdit`
- Uses shadcn/ui TableRow, TableCell, Badge, Button, Pencil icon components

**ClientPortalAgreementPage.tsx**
- Service agreement details and signing interface
- **Agreement Summary section** with help tooltips:
  - Effective Date - tooltip explains when billing begins
  - Monthly Retainer - tooltip explains the fixed monthly fee
  - Hours Included - tooltip explains hours covered by retainer
  - Hourly Rate (Additional) - tooltip explains rate for overage hours
  - Rollover Period - tooltip explains rollover window (hidden if 0 or null)
- **Agreement Terms section** is hidden if empty or set to "TBA".
- Help icons (?) use HelpCircle from lucide-react with Tooltip component
- **Signed badge** displayed in green (`bg-green-600`) when agreement is signed
- **Invoices section** below Agreement Files showing all invoices for this agreement
  - Lists invoice number, period dates, total amount, and status badge
  - Status badges use green color for paid invoices
  - Clickable links to individual invoice pages
- **Agreement Files section** uses a clean Table layout with download/history/delete actions
- Signing interface with name and title fields
- Uses shadcn/ui Card, Badge, Tooltip, Skeleton components

**ClientPortalInvoicePage.tsx**
- Invoice detail page with line items and payments
- **Time Formatting**: Displays quantities and time entries in `hh:mm` format using the `formatHours` utility.
- **Show Detail toggle switch** in top-right corner above line items table
  - When enabled, displays time entry descriptions as indented bullet list below each line item
  - Shows description and hours for each time entry (e.g., "Meeting with client (2:30)")
  - Uses muted background color for detail rows
- Invoice actions (Issue, Void, Add Payment, etc.) for admins
- Line item editing capabilities for draft invoices
- Payment history with edit/delete actions
- Hourly summary section showing hour balances (includes `hours_billed_at_rate` and `unused_hours_balance`; Catch‑up Hours Billed and Remaining Balance tiles are shown on the invoice even when zero)
- Uses shadcn/ui Table, Badge, Button, Switch, Label components

### Styling
- Uses shadcn/ui components with Tailwind CSS
- Follows the conventions already used elsewhere in the application
- Responsive design with container max-width
- Consistent with mockup layout:
  - Company cards with name and user badges
  - Details button on each card
  - Collapsible inactive section at bottom

## Subcontractors

Subcontractors are additional people assigned to a specific `ClientProject`. They get
scoped portal access, self-report their own hours (subject to admin approval), and can be
billed to the client in three ways.

### Engagements (onboarding / offboarding)

- A subcontractor's tenure with a company is a `client_subcontractor_engagements` row
  with an `active_date` and a nullable `termination_date`. Project assignments
  (`client_subcontractors`) hang under one engagement. "Active" is derived from the
  engagement's dates, not a stored flag.
- **Onboarding** opens (or reuses) the active engagement for `(company, user)`; the start
  date defaults to today and can be set explicitly.
- **Offboarding (terminate)** sets `termination_date` on the engagement, ending the
  relationship across every project under it and revoking portal access — while leaving all
  logged work, snapshots, and invoice lines untouched. **Work done earlier in the current
  period before termination still bills**, because billing reads time-entry snapshots, never
  the engagement state.
- **Reactivation** opens a *new* engagement (a fresh tenure) rather than reopening the old
  one, so the full history of stints and rate changes is preserved.

### Roles and access

- Portal membership lives in `client_company_user`; the new `role` column distinguishes
  `client` (default) from `subcontractor`.
- A subcontractor satisfies `ClientCompanyMember` (portal entry) but **not**
  `ClientCompanyClient`, so they cannot see tasks, invoices, agreements, proposals, or
  expenses. They see only the projects they are assigned to and only **their own** hours
  (any approval state). Client users see all subcontractor hours.
- Adding a subcontractor finds-or-creates a passwordless `User` by email, attaches the
  company pivot with `role = subcontractor`, opens or reuses their active engagement, and
  creates a `client_subcontractors` row under it. A given email cannot be both a `client`
  contact and a subcontractor on the same company. Removing the last active assignment (or
  terminating the engagement) detaches portal access.

### Self-logging and approval

- Subcontractors log/edit/delete only their **own** entries, which start
  `approval_status = pending` and are neither billable nor client-visible until an admin
  approves them. Admin-entered subcontractor hours are pre-approved.
- Each entry snapshots the assignment's `subcontractor_billing_mode` (and the flat-hourly
  rate) at log time, so later assignment edits never re-price already-logged work.

### Billing modes

- **`flat_hourly`** — billed separately from the retainer at the contractor's own rate,
  as one `subcontractor` invoice line per (subcontractor, project, rate) per period. These
  hours never consume the retainer pool.
- **`retainer`** — folded into the project retainer at the project/agreement rate, with the
  same carry-forward/back overage behavior as consultant hours.
- **`direct`** — never billed by us (the subcontractor invoices the client directly), but
  hours are still tracked and visible to client users.

See [Billing overview](billing.md) for how these interact with the retainer ledger.

### API endpoints

- `GET|POST /api/client/portal/{slug}/projects/{projectSlug}/subcontractors` — list / add (add is admin-only; `POST` accepts an optional `active_date`)
- `PUT|DELETE /api/client/portal/{slug}/projects/{projectSlug}/subcontractors/{id}` — update terms / remove from project (admin-only)
- `POST /api/client/portal/{slug}/projects/{projectSlug}/subcontractors/{id}/terminate` — offboard the engagement, optional `termination_date` (admin-only)
- `POST /api/client/portal/{slug}/projects/{projectSlug}/subcontractors/{id}/reactivate` — open a new engagement and re-assign, optional `active_date` (admin-only)
- `POST /api/client/portal/{slug}/time-entries/{id}/approve` and `/reject` — admin approval (admin-only)

## File Storage System

### Overview
The file storage system enables uploading, downloading, and managing files associated with client management entities (companies, projects, agreements, tasks). Files are stored in S3-compatible storage with signed URLs for secure access.

### Database Schema

#### `uploaded_files` table
- `id`: Primary key (auto-increment)
- `fileable_type`: Polymorphic type (e.g., 'client_companies', 'client_projects')
- `fileable_id`: ID of the associated entity
- `original_filename`: Original filename as uploaded
- `stored_filename`: UUID-based filename with date prefix (e.g., "2025.01.15 report.pdf")
- `mime_type`: File MIME type
- `file_size`: Size in bytes
- `storage_path`: Full S3 path to file
- `uploaded_by_user_id`: Foreign key to users (set null on delete)
- `created_at`, `updated_at`: Timestamps
- `deleted_at`: Soft delete timestamp

#### `file_download_history` table
- `id`: Primary key
- `uploaded_file_id`: Foreign key to uploaded_files (cascade on delete)
- `downloaded_by_user_id`: Foreign key to users (set null on delete)
- `downloaded_at`: Timestamp of download
- `ip_address`: IP of requester (nullable)

### Supported Entity Types
Files can be attached to:
- **Client Companies**: General company documents (`/api/client/portal/{slug}/files`)
- **Projects**: Project-specific documents (`/api/client/portal/{slug}/projects/{projectSlug}/files`)
- **Agreements**: Agreement documents (`/api/files/agreements/{id}`)
- **Tasks**: Task attachments (`/api/files/tasks/{id}`)

### Frontend Components

#### Location: `resources/js/components/shared/FileManager.tsx`

**Components:**
- `FileList`: Displays list of files in a Table layout with download, history, and delete actions
- `FileUploadButton`: Upload button with progress indicator
- `FileHistoryModal`: Shows download history for a file
- `DeleteFileModal`: Confirmation dialog for file deletion

**Hooks:**
- `useFileOperations(options)`: Low-level hook for file CRUD operations
- `useFileManagement(options)`: Higher-level hook that includes modal state management

**Usage Example:**
```tsx
const fileManager = useFileManagement({
  listUrl: `/api/client/portal/${slug}/files`,
  uploadUrl: `/api/client/portal/${slug}/files`,
  uploadUrlEndpoint: `/api/client/portal/${slug}/files/upload-url`,
  downloadUrlPattern: (fileId) => `/api/client/portal/${slug}/files/${fileId}/download`,
  deleteUrlPattern: (fileId) => `/api/client/portal/${slug}/files/${fileId}`,
  historyUrlPattern: (fileId) => `/api/client/portal/${slug}/files/${fileId}/history`,
})

// Use in JSX:
<FileUploadButton onUpload={fileManager.uploadFile} />
<FileList
  files={fileManager.files}
  loading={fileManager.loading}
  isAdmin={isAdmin}
  onDownload={fileManager.downloadFile}
  onDelete={fileManager.handleDeleteRequest}
  title="Files"
/>
<DeleteFileModal
  file={fileManager.deleteFile}
  isOpen={fileManager.deleteModalOpen}
  isDeleting={fileManager.isDeleting}
  onClose={fileManager.closeDeleteModal}
  onConfirm={fileManager.handleDeleteConfirm}
/>
```

### Upload Flow
1. **Small files (≤50MB)**: Direct upload via POST to the upload URL
2. **Large files (>50MB)**: 
   - Request signed S3 URL via POST to upload-url endpoint
   - Upload directly to S3 using PUT with the signed URL
   - Backend creates the file record after S3 upload

### API Endpoints (per entity type)
- `GET /api/.../files` - List files for entity
- `POST /api/.../files` - Upload file directly
- `POST /api/.../files/upload-url` - Get signed URL for large file upload
- `GET /api/.../files/{id}/download` - Get signed download URL
- `GET /api/.../files/{id}/history` - Get download history
- `DELETE /api/.../files/{id}` - Soft delete file

## Billing & Invoicing System

> **Note:** Detailed documentation for the Billing & Invoicing system has been moved to [billing.md](billing.md).

### Quick Summary
- **Model**: Prior-period billing ("Give and Take") with monthly, quarterly, and annual agreement cadences
- **Key Features**:
  - Retainer-based pricing
  - Rollover hours
  - **Minimum Availability Rule** (Catch-up billing)
  - Recurring fixed-fee agreement items
  - Optional interim overage invoices for non-monthly cadences
  - Reimbursable expense tracking

### Database Schema


#### `client_agreements` table
Stores service agreement terms between the admin and client companies.
- `client_agreement_id`: Primary key
- `client_company_id`: Foreign key to `client_companies`
- `active_date`: When the agreement becomes active (required)
- `termination_date`: When the agreement ends (nullable)
- `agreement_text`: Full agreement content (text, nullable)
- `agreement_link`: URL to external agreement document (nullable)
- `client_company_signed_date`: When client signed (nullable)
- `client_company_signed_name`: Name of client signatory (nullable)
- `client_company_signed_title`: Title of client signatory (nullable)
- `client_company_signed_user_id`: User who signed for client (nullable)
- `monthly_retainer_hours`: Hours included per month (decimal 8,2)
- `catch_up_threshold_hours`: Minimum availability hours after retainer allocation (decimal 8,2, default 1.0)
- `rollover_months`: Number of months unused hours can roll over (integer, default 1)
- `hourly_rate`: Rate for hours beyond retainer (decimal 8,2)
- `monthly_retainer_fee`: Fixed monthly fee (decimal 10,2)
- `billing_cadence`: Agreement billing cadence (`monthly`, `quarterly`, `annual`; default `monthly`)
- `bill_overage_interim`: Whether non-monthly agreements generate interim overage invoices at completed month boundaries
- `first_cycle_proration`: First-cycle behavior (`prorate_hours`, `full_period`, `align_next_cycle`; default `prorate_hours`)
- `initial_rollover_hours`: Rollover hours carried into this agreement from a transition (decimal 8,4)
- `created_at`, `updated_at`: Timestamps

**Catch-up Threshold Hours:**
- **Purpose**: Ensures minimum availability for future work by billing catch-up hours when needed
- **Default**: 1.0 hour
- **Valid Range**: 0 to `monthly_retainer_hours` (inclusive)
- **Validation**: Enforced at model level (on save) and API level (on create/update)
- **Behavior**: When prior period work consumes retainer capacity, the system bills catch-up hours to maintain at least `catch_up_threshold_hours` of availability for the next billing period
- **Example**: If retainer is 10 hours and 9 hours of prior month work is allocated, 1 hour of catch-up is billed to restore minimum availability (assuming threshold = 1.0)

**Rollover Months Semantics:**
- **`rollover_months = 0`**: No rollover - unused hours from a month expire immediately (must be used in that month)
- **`rollover_months = 1`**: Hours can roll over to the next month only (N+1), then expire
- **`rollover_months = 2`**: Hours can roll over for one additional month after being earned (available in N, N+1, N+2)
- **`rollover_months = N`**: Hours can roll over for N-1 additional months (available for N total months)
- **FIFO Consumption**: When multiple months of rollover are available, oldest hours are consumed first
- **FIFO Expiry**: Oldest unused hours expire first when they exceed the rollover window
- **Catch-up placement**: Charged catch-up hours settle debt in the invoice's service month. Any surplus restores that month's available capacity and expires under the same rollover window; an old charge is never reapplied as new capacity in later months. A negative correction reverses unused capacity in its service month first, then restores debt for any remainder.

#### `client_invoices` table
Stores invoices generated for clients.
- `client_invoice_id`: Primary key
- `client_company_id`: Foreign key to `client_companies`
- `client_agreement_id`: Foreign key to `client_agreements`
- `invoice_number`: Unique invoice number (string, nullable)
- `period_start`: Invoice period start date. For monthly invoices this is the work month; for cadence-period invoices this is the cadence cycle start; for interim overage invoices this is the completed monthly slice start.
- `period_end`: Invoice period end date. For cadence-period invoices this is the cadence cycle end; for interim overage invoices this is the completed monthly slice end.
- `invoice_kind`: `cadence_period`, `interim_overage`, or `terminal` (default `cadence_period`)
- `cycle_start`: First day of the cadence cycle this invoice belongs to
- `cycle_end`: Last day of the cadence cycle this invoice belongs to
- `retainer_hours_included`: Hours included in the invoice period/cadence cycle (decimal 8,2)
- `hours_worked`: Total hours worked in the invoice period (decimal 8,2)
- `rollover_hours_used`: Hours from rollover applied to the invoice period (decimal 8,2)
- `unused_hours_balance`: Hours remaining at the end of the invoice period (decimal 8,2)
- `negative_hours_balance`: Debt hours at the end of the invoice period (decimal 8,2)
- `hours_billed_at_rate`: Hours charged at hourly rate via catch-up billing (decimal 8,2)
- `invoice_total`: Total invoice amount (decimal 10,2)
- `status`: draft, issued, paid, void (enum, default draft)
- `issue_date`: When invoice was issued (nullable)
- `due_date`: Payment due date (nullable)
- `paid_date`: When payment received (nullable)
- `notes`: Internal or customer-facing notes (text, nullable)
- `created_at`, `updated_at`: Timestamps
- `deleted_at`: Soft delete timestamp (nullable)

For cadence-period invoices, `period_start` / `period_end` match `cycle_start` / `cycle_end`. Interim overage invoices use the completed monthly slice in `period_start` / `period_end` while retaining the parent quarterly or annual cycle in `cycle_start` / `cycle_end`.

#### `client_agreement_recurring_items` table
Stores fixed-fee items attached to an agreement.
- `id`: Primary key
- `client_agreement_id`: Foreign key to `client_agreements`
- `description`: Line-item description
- `amount`: Fixed charge amount (decimal 10,2)
- `charge_cadence`: `monthly`, `quarterly`, `semi_annual`, `annual`, or `one_time`
- `anchor_month`: Optional 1-12 anchor month for non-monthly charges
- `anchor_day`: Optional 1-28 anchor day (clamped during billing)
- `start_date`: First eligible billing date
- `end_date`: Last eligible billing date (nullable)
- `is_taxable`: Whether the item is taxable
- `is_summarized`: Whether display can summarize multiple incidences
- `notes`: Internal notes (nullable)
- `deleted_at`: Soft delete timestamp (nullable)

#### `client_company_activity` table
Stores audit/activity log entries for a company.
- `id`: Primary key
- `client_company_id`: Foreign key to `client_companies`
- `actor_user_id`: Nullable foreign key to `users`
- `action`: Action key such as `agreement.transitioned`, `invoice.generated`, `invoice.issued`, `invoice.marked_paid`, `invoice.voided`, `invoice.payment_received`, `invoice.payment_failed`, `invoice.payment_canceled`, `invoice.payment_disputed`, `invoice.payment_refunded`, `payment_method.added`, `payment_method.removed`, or `payment_method.default_changed`
- `subject_type`: Stable native subject kind, or the imported predecessor class name for preserved rows
- `subject_public_id`: Public UUID of a native agreement, invoice, payment, or saved payment method
- `external_subject_id`: Nullable predecessor numeric reference retained only for imported history
- `deduplication_key`: Hashed native occurrence identity, unique inside a workspace so exact retries cannot append duplicates
- `payload`: Whitelisted JSON display metadata. Native writers reject raw provider payloads, credentials, secrets, tokens, and document contents.
- `created_at`, `updated_at`: Timestamps

#### `client_invoice_payments` table
Stores payments made against invoices.
- `client_invoice_payment_id`: Primary key
- `client_invoice_id`: Foreign key to `client_invoices`
- `amount`: Payment amount (decimal 10,2)
- `payment_date`: Date payment was received
- `payment_method`: Credit Card, ACH, Wire, Check, Other, stripe_card, stripe_ach, stripe_refund (string)
- `notes`: Payment notes (text, nullable)
- `created_at`, `updated_at`: Timestamps

#### `client_invoice_lines` table
Individual line items on invoices.
- `client_invoice_line_id`: Primary key
- `client_invoice_id`: Foreign key to `client_invoices`
- `description`: Line item description (required)
- `quantity`: Quantity as string (varchar 20) - formatted as "h:mm" for time-based lines or "1" for flat items
- `unit_price`: Price per unit (decimal 10,2)
- `line_total`: Calculated total (decimal 10,2)
- `line_type`: retainer, additional_hours, prior_month_retainer, prior_month_billable, recurring_item, expense, adjustment, credit, reconciliation (string)
- `line_date`: Date associated with the line item (date, nullable) - e.g., the work/charge date for time, recurring items, expenses, or retainer lines
- `hours`: Hours if applicable (decimal 8,2, nullable)
- `sort_order`: Display order (integer)
- `client_agreement_recurring_item_id`: Nullable reference to the recurring item that generated this line
- `created_at`, `updated_at`: Timestamps

### Services

- **`TimeEntrySplitter`**: Deterministic allocation and fragment creation
- **`AllocationService`**: Fragment recombination and allocation tracking
- **`RolloverCalculator`**: Opening/closing balances with FIFO rollover (`app/Services/ClientManagement/RolloverCalculator.php`)
- **`BillingCycleResolver`**: Resolves monthly, quarterly, and annual cycle windows with first-cycle proration
- **`RecurringItemBiller`**: Computes recurring item incidences and invoice lines for a cycle
- **`AgreementTransitionService`**: Terminates outgoing agreements and creates successor agreements for cadence/terms changes
- **`ClientInvoicingService`**: Orchestrates invoice generation

See [billing.md](billing.md) for full billing logic documentation.

### Time Entry Splitting & Allocation

When a single time entry spans multiple allocation types, it is split into fragments.

**Allocation Order (Deterministic):**

1. **Prior Month Retainer**: Hours allocated against the prior month's retainer capacity
2. **Current Period Retainer**: Hours allocated against the current billing period's retainer capacity
3. **Catch-up Threshold**: Hours billed to maintain minimum availability (based on `catch_up_threshold_hours`)
4. **Billable Catch-up**: Remaining hours billed at hourly rate

**Time Entry Fragment Splitting:**

When a time entry needs to be split:
- The system creates new `ClientTimeEntry` records for each fragment
- Each fragment is linked to exactly one invoice line via `client_invoice_line_id`
- Fragments maintain original metadata (date, user, description, project, task)
- Splitting is deterministic (same inputs always produce same outputs)
- Chronological ordering (by date + ID) ensures stable allocation

**Fragment Recombination:**

When invoices are deleted or regenerated:
- Unlinked fragments (where `client_invoice_line_id` is NULL) can be recombined
- Recombination only occurs when ALL fragments with matching merge keys are unlinked
- **Merge Keys**: date_worked, user_id, name (description), project_id, task_id
- Fragments still linked to other invoices are NOT recombined
- Recombination sums the minutes from matching fragments into a single entry

**Example Splitting Scenario:**

```
Time Entry: 10 hours on Jan 15, 2024
Agreement: retainer = 2h, catch_up_threshold = 1h

Splits into 4 fragments:
- Fragment A: 2.0h → Prior month retainer allocation (line_type: prior_month_retainer)
- Fragment B: 2.0h → Current month retainer allocation (line_type: prior_month_retainer)  
- Fragment C: 1.0h → Catch-up threshold allocation (line_type: additional_hours)
- Fragment D: 5.0h → Billable catch-up allocation (line_type: additional_hours)
```

**Invoice Line Types:**

| Line Type | Description | Price | When Used |
|-----------|-------------|-------|-----------|
| `prior_month_retainer` | Prior month work covered by retainer | $0 | Work from M-1 covered by retainer pool |
| `additional_hours` | Catch-up or billable overage | Hourly rate | When work exceeds retainer capacity or threshold enforcement |
| `retainer` | Cadence retainer fee | Fixed fee | Every cadence-period invoice; scaled for quarterly/annual cycles |
| `recurring_item` | Recurring agreement charge | Fixed fee | When a recurring item incidence falls in the invoice cycle |
| `reconciliation` | Cadence-period summary of interim-billed hours | Zero-priced | Cadence-period invoice for a cycle that has sibling `interim_overage` invoices |
| `expense` | Reimbursable expenses | Actual cost | Client expenses needing reimbursement |
| `adjustment` | Manual adjustments | Variable | Admin-added corrections or special charges |
| `credit` | Informational credits | $0 | Balance adjustments or credits |

**Services Architecture:**

- **`TimeEntrySplitter`**: Handles deterministic allocation and fragment creation
- **`AllocationService`**: Manages fragment recombination and allocation tracking
- **`RolloverCalculator`**: Calculates opening/closing balances with FIFO rollover
- **`BillingCycleResolver`**: Resolves cadence cycle windows and proration
- **`RecurringItemBiller`**: Computes recurring item invoice lines for a cycle
- **`AgreementTransitionService`**: Handles terminate-and-create-successor cadence transitions
- **`ClientInvoicingService`**: Orchestrates invoice generation using above services

### Delayed Billing

Delayed billing allows billable time entries from periods without an active agreement to be tracked and eventually applied to the future retainer pool.

**How It Works:**

1. **Time Entry Creation Without Agreement**: Billable time entries created during a period without an active agreement are treated as having 0 retainer hours.

2. **UI Warning**: The Time Records page displays an amber warning for months where there is no active agreement, showing the unbilled hours that are being carried forward.

3. **Invoicing**: When an agreement becomes active, these hours are automatically included in the chronological balance calculation as a negative starting balance (offset by future retainer hours).

4. **Audit Trail**: The invoice includes a $0 line item for prior-month work, and an informational line item showing how much negative balance was carried forward.

**Example Scenario:**

| Month | Agreement Status | Hours Worked | Result |
|-------|-----------------|--------------|--------|
| January | None | 5h | 5h unused balance carried forward (tracked for future) |
| February | Active (10h retainer) | 8h | Pool: 10h retainer. Work: 5h (Jan) + 8h (Feb) = 13h. 10h used, 3h negative balance carried forward (or billed if no rollover). |

**API Response:**

The time entries API includes delayed billing information:

```json
{
  "months": [
    {
      "year_month": "2024-01",
      "total_hours": 5.0,
      "has_active_agreement": false,
      "unbilled_hours": 5.0
    },
    {
      "year_month": "2024-02",
      "total_hours": 8.0,
      "has_active_agreement": true,
      "unbilled_hours": 0
    }
  ],
  "total_unbilled_hours": 5.0
}
```

**Invoice Line Types:**

| Line Type | Description |
|-----------|-------------|
| `retainer` | Monthly retainer fee |
| `additional_hours` | Hours exceeding retainer in current period |
| `delayed_billing` | Prior period hours billed to current invoice |
| `credit` | Rollover hours applied (informational, $0) |

### Portal API Enhancements

The `getTimeEntries()` API now returns enhanced data for the monthly grouping UI:

```json
{
  "time_entries": [...],
  "monthly_data": {
    "2025-01": {
      "retainer_hours": 20,
      "rollover_months": 3,
      "hours_worked": 18.5,
      "opening_balance": {
        "retainer_hours": 20,
        "rollover_hours": 5,
        "expired_hours": 0,
        "total_available": 25
      },
      "closing_balance": {
        "unused_hours": 6.5,
        "excess_hours": 0,
        "status": "under"
      }
    },
    ...
  }
}
```

## Invoice Generation and Management

### Automated Invoice Generation

The system provides automated invoice generation for all active agreement cadence windows via the "Run Invoicing" feature. Monthly agreements generate monthly invoices; quarterly and annual agreements generate cadence-period invoices and, when enabled, interim overage invoices for completed month slices inside the current cycle.

**Admin Page Enhancements:**

The Client Management admin page (`/client/mgmt`) now displays key metrics for each company:

- **Invoice Balance Due**: Total outstanding balance across all unpaid/issued invoices (orange badge with $ icon)
- **Uninvoiced Hours**: Total billable hours not yet linked to any invoice (blue badge with clock icon)
- **Uninvoiced Tasks**: Total value of completed and incomplete milestone tasks not yet billed (purple badge with package icon)
  - Shows breakdown: `($X.XX complete, $Y.YY incomplete)`
- **Lifetime Value**: Sum of all paid invoices for the client (green badge with trending up icon)
- **Run Invoicing Button**: Per-company button to auto-generate invoices for all agreement cadence windows
- **+Add User Button**: Quick access button inline with the user badges on each company card to add users to that specific company (opens Invite People dialog pre-selected)
- **Manage Button**: (wrench icon) navigates to the company's detail/management page
- **Portal Button**: navigates to the client portal for the company

**Workflow:**

1. Admin clicks "Run Invoicing" for a company
2. System automatically generates invoices from agreement start date through the current cadence window. Monthly agreements use calendar months; quarterly and annual agreements use calendar-aligned cycles.
3. Results summary shows:
   - **Generated**: New invoices created (draft status)
   - **Updated**: Existing draft invoices regenerated with latest data
   - **Skipped**: Issued/paid/void invoices left untouched
   - **Cadence / interim counts**: Summary counts distinguish cadence-period invoices from interim overage invoices

**Key Features:**

- **No manual date selection**: Automatically uses the agreement cadence boundaries
- **Upcoming period preview**: Generates a draft invoice for the current cadence window so current-period time entries, recurring items, expenses, and milestone tasks can be previewed before the period closes
- **Interim overage support**: For non-monthly agreements with `bill_overage_interim = true`, completed month slices can generate `interim_overage` invoices without double-billing on the final cadence-period invoice
- **Smart detection**: Skips periods without billable activity or where invoice already finalized
- **Draft regeneration**: Updates existing draft invoices with latest time entry data
- **Protected invoices**: Never modifies issued, paid, or voided invoices

**API Endpoint:**
```
POST /api/client/mgmt/companies/{company}/invoices/generate-all
```

**Response Format:**
```json
{
  "message": "Invoice generation completed",
  "results": {
    "generated": [
      {"period": "2024-Q1", "invoice_id": 1, "invoice_number": "INV-001", "invoice_kind": "cadence_period"},
      {"period": "2024-01", "invoice_id": 2, "invoice_number": "INV-002", "invoice_kind": "interim_overage"}
    ],
    "updated": [
      {"period": "2024-Q2", "invoice_id": 3, "invoice_number": "INV-003", "invoice_kind": "cadence_period"}
    ],
    "skipped": [
      {"period": "2024-Q3", "invoice_id": 4, "status": "paid", "reason": "Invoice already exists with status: paid"}
    ],
    "summary": {
      "generated_count": 2,
      "updated_count": 1,
      "skipped_count": 1,
      "cadence_period_invoices_created": 1,
      "interim_invoices_created": 1
    }
  }
}
```

### Manual Line Item Preservation

When regenerating invoices (e.g., via "Run Invoicing" or manual re-generation), the system intelligently preserves manual adjustments:

**System-Generated Line Items** (auto-deleted and regenerated):
- `retainer`: Monthly retainer fee
- `additional_hours`: Hours exceeding retainer + rollover
- `credit`: Informational rollover hours applied (zero amount)

**Manual Line Items** (preserved during regeneration):
- `expense`: Manual expenses or fees added by admin
- `adjustment`: Price adjustments or credits

**Example Scenario:**

1. Draft invoice created automatically with retainer line
2. Admin manually adds $500 "Consulting fee" (expense type)
3. Admin clicks "Run Invoicing" to refresh with latest time entries
4. Result: System line items regenerated, $500 consulting fee preserved

**Line Type Enum Values:**
```php
enum('retainer', 'additional_hours', 'expense', 'adjustment', 'credit')
```

**Note on Delayed Billing:**
Prior period hours (delayed billing) are created as `additional_hours` type with description containing "Prior Period" to distinguish them from current period additional hours.

### Invoice Line Item Management

**Adding Manual Line Items:**

Via API:
```
POST /api/client/mgmt/companies/{company}/invoices/{invoice}/line-items
```

Body:
```json
{
  "description": "Manual consulting fee",
  "quantity": 1,
  "unit_price": 500.00,
  "line_type": "expense"
}
```

**Editing Line Items:**

- System-generated line items (retainer, additional_hours, credit) cannot be edited directly
- Manual line items (expense, adjustment) can be updated via API
- All line items recalculate invoice total automatically

**Deleting Line Items:**

- System-generated line items are automatically regenerated if invoice is refreshed
- Manual line items persist across regeneration unless explicitly deleted
- Deleting a line item unlinks any associated time entries

**Time Entry Linking:**

System automatically links time entries to invoice lines:
1. Up to retainer hours → linked to retainer line
2. Additional hours → linked to additional_hours line
3. Prior period hours → linked to delayed billing line (additional_hours with "Prior Period" description)

Unlinking occurs when:
- Invoice is voided
- Invoice is deleted
- Draft invoice is regenerated (system lines only)

### Invoice Payment Tracking

**Adding Payments:**

When adding a payment to an invoice:
- `amount`: Payment amount (supports partial payments)
- `payment_date`: Date payment received
- `payment_method`: Credit Card, ACH, Wire Transfer, Check, Other. Stripe-created ledger rows use `stripe_card`, `stripe_ach`, or `stripe_refund`; admins cannot manually create those synthetic Stripe methods.
- `notes`: Optional payment notes

**Auto-Paid Status:**

When `payments_total >= invoice_total`:
- Invoice status automatically changes to 'paid'
- `paid_date` set to the date of the latest payment (not current date)

**Payment Deletion:**

- Deleting a payment recalculates remaining balance
- If invoice was marked paid, status reverts to 'issued' or 'draft'
- Cannot void an invoice with payments (must delete payments first)

**Payment Default Amount:**

When adding a payment via UI, the amount field defaults to the remaining balance, simplifying full-payment entry.

## Security
- All routes protected by authentication middleware
- Admin gate enforced on admin endpoints
- ClientCompanyMember gate enforced on portal endpoints
- CSRF protection on all state-changing operations
- Cascade deletes maintain referential integrity
- Soft deletes prevent accidental data loss
- Slug uniqueness validated on create and update

## Client Expenses

### Overview
The Expenses feature allows admin users to track reimbursable and non-reimbursable expenses for client companies. Expenses can be optionally linked to:
- A specific project within the client company
- An external finance transaction, through the reconciliation adapter

### Database Schema

#### `client_expenses` table
- `id`: Primary key (auto-increment)
- `client_company_id`: Foreign key to `client_companies` (cascade on delete)
- `project_id`: Foreign key to `client_projects` (set null on delete, nullable)
- `external_finance_transaction_uuid`: Opaque identifier of the reconciled
  transaction in the external finance application (nullable). SVC stores the
  identifier only; it never holds the transaction itself. See
  [the finance reconciliation API](../finance-api.md).
- `description`: Expense description (required)
- `amount`: Expense amount (decimal 12,2)
- `expense_date`: Date of expense (required)
- `is_reimbursable`: Whether expense is reimbursable (boolean, default false)
- `is_reimbursed`: Whether expense has been reimbursed (boolean, default false)
- `reimbursed_date`: Date of reimbursement (nullable)
- `category`: Expense category (string, nullable)
- `notes`: Additional notes (text, nullable)
- `creator_user_id`: Foreign key to `users` (set null on delete)
- `client_invoice_line_id`: Foreign key to invoice lines (nullable, for future invoicing)
- `created_at`, `updated_at`: Timestamps
- `deleted_at`: Soft delete timestamp (nullable)

### API Routes for Expenses

All protected by `['web', 'auth']` middleware and Admin gate:

```
GET    /api/client/mgmt/companies/{company}/expenses              → List all expenses
POST   /api/client/mgmt/companies/{company}/expenses              → Create expense
GET    /api/client/mgmt/companies/{company}/expenses/{expense}    → Get single expense
PUT    /api/client/mgmt/companies/{company}/expenses/{expense}    → Update expense
DELETE /api/client/mgmt/companies/{company}/expenses/{expense}    → Delete expense
POST   /api/client/mgmt/companies/{company}/expenses/{expense}/mark-reimbursed  → Mark as reimbursed
POST   .../expenses/{expense}/reconcile   → Attach an external finance transaction UUID
DELETE .../expenses/{expense}/reconcile   → Detach it
```

### Web Routes for Expenses

```
GET /client/portal/{slug}/expenses → ClientPortalExpensesPage (Admin only)
```

### Components

#### ClientPortalExpensesPage
Location: `resources/js/client-management/components/portal/ClientPortalExpensesPage.tsx`

Features:
- Summary cards showing total, reimbursable, pending, and non-reimbursable amounts
- Sortable table of all expenses
- Reconciliation state when an external finance transaction is attached (internal users only)
- Mark expense as reimbursed action
- Click row to edit expense

#### NewExpenseModal
Location: `resources/js/client-management/components/portal/NewExpenseModal.tsx`

Features:
- Create and edit expenses
- Select project from dropdown
- Attach an external finance transaction UUID
- Toggle reimbursable/reimbursed status
- Category selection from preset list

### External finance reconciliation

An expense may carry the UUID of a transaction in a separate finance
application. SVC stores that identifier and nothing else — no banking data, no
transaction records, no ledger. The coupling is deliberately one-directional
and opaque so the finance side stays a replaceable adapter.

This is the same boundary the payment path already uses:
`client_invoice_payments.external_finance_transaction_uuid`, reconciled through
[the finance reconciliation API](../finance-api.md) under the `finance.read` and
`finance.reconcile` abilities.

Holding the identifier is enough to answer "which client was this expense
billed to?" from either side, without either system reading the other's
database.

### Time Entry Invoiced Status

Time entries now display an "Invoiced" status badge when:
- The entry is billable (`is_billable = true`)
- The entry is linked to an invoice line (`client_invoice_line_id IS NOT NULL`)

The `ClientTimeEntry` model has an `is_invoiced` appended attribute that is automatically included in API responses.
