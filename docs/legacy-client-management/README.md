# Legacy client management (reference)

> **Legacy reference.** This directory captures the pre-extraction "Client Management" feature as it existed in [`bherila/2025-website`](https://github.com/bherila/2025-website) (the "bwh-php" app) before its application code was removed there in PR #2123 (2026-08-18). It is preserved here for domain continuity while SVC finishes the Stage 5 cutover described in issue [#14](https://github.com/bherila/svc/issues/14), and as a cross-check against SVC's own reimplementation. File paths, artisan commands, route prefixes, and model/table names throughout this directory refer to the **legacy bwh-php app**, not to SVC's own code — see [`../domain-contract.md`](../domain-contract.md) and [`../architecture.md`](../architecture.md) for SVC's current schema and structure.

Admin-only feature for managing client companies, their users, agreements, time tracking, expenses, milestones, and invoicing. Included both an admin UI and a client-facing portal in the legacy app.

## Quick links

- **[Overview](overview.md)** — architecture, schema, models, controllers, routes, and workflows.
- **[Setup](setup.md)** — one-time bootstrap: migrations to run, how to mark the first admin, how to test the feature end-to-end.
- **[Billing](billing.md)** — billing hub: prior-period model, cadence/cycle fields, rollover, minimum-availability (catch-up) rule, line items, balance fields, recurring items, agreement transitions.
- **[Cadence billing & regeneration](cadence-billing.md)** — invoice period (`period_*` vs `cycle_*`), one-cycle offset, numbering, regeneration rules + legacy `period == cycle` migration, interim overage invoices.
- **[Milestone billing](milestone-billing.md)** — flat-fee deliverable billing via `milestone_price`.
- **[Payments](payments.md)** — payment methods, validation, status transitions, and the payments UI.
- **[CLI](cli.md)** — admin Artisan commands for invoice listing, manual payments, and time-entry creation.
- **[Stripe billing](stripe-billing.md)** — online invoice payments, saved payment methods, payment cap, and webhook behavior.
- **[Deferred billing](deferred-billing.md)** — per-entry flag that lets admins complete work now and bill for it only when retainer capacity exists.
- **[Overpayment credits](overpayment-credits.md)** — any overpaid amount carries forward as a credit on the next invoice(s) and never expires.
- **[Subcontractors](overview.md#subcontractors)** — project-scoped subcontractors with scoped portal access, self-logged + admin-approved hours, and flat-hourly / retainer / direct billing modes.

## Code locations (legacy app, now removed)

**Backend** (`app/`):

- Models: `app/Models/ClientManagement/`
- Controllers: `app/Http/Controllers/ClientManagement/`
- Services: `app/Services/ClientManagement/` (invoicing, cadence cycles, transitions, recurring items, rollover, allocation, deferred billing, overpayment credits)
- DTOs: `app/Services/ClientManagement/DataTransferObjects/`
- Enums: `app/Enums/ClientManagement/` (invoice kinds, line types, billing/charge cadences, proration policies)

**Frontend** (`resources/js/client-management/`):

- Entry points: `admin.tsx`, `portal.tsx`
- Components: `components/` (admin) and `components/portal/` (client portal)
- Types + Zod schemas: `types/`
- Jest tests: `__tests__/`, plus co-located `components/**/__tests__/` and `types/__tests__/`

**Views**: `resources/views/client-management/` (admin + `portal/` subfolder).

**Tests**: `tests/Feature/ClientManagement/`, `tests/Unit/ClientManagement/`.

## High-level flow

1. Admin creates a **client company**, invites users, and signs an **agreement** (retainer, hourly rate, billing cadence, rollover months, catch-up threshold).
2. Team members log **time entries** against company projects/tasks through the portal. Entries may be flagged `is_deferred_billing` to defer billing until capacity exists.
3. Admin configures optional **recurring items** on the agreement for fixed-fee monthly, quarterly, semi-annual, annual, or one-time charges.
4. Admin "Generates Invoices" → **draft** invoices are created for each monthly, quarterly, or annual cadence cycle. Drafts auto-regenerate when time entries change. Issued/Paid/Void invoices are immutable.
5. For non-monthly agreements with `bill_overage_interim = true`, interim overage invoices can be emitted at completed month boundaries inside the current cadence cycle.
6. Payments are recorded against invoices. Overpayments automatically become **credits** applied to the next draft invoice.
7. On **agreement transition**, the outgoing agreement is terminated, a successor agreement is created, rollover can be carried forward, and activity log rows record the change.
8. On **agreement termination**, outstanding deferred entries are force-billed at the hourly rate on the final invoice.

## Conventions (legacy app)

- Dates: all models used the `SerializesDatesAsLocal` trait.
- Monetary math: frontend used `currency.js`; backend used decimal casts.
- Authorization: Admin gate on admin routes; `ClientCompanyMember` gate on portal routes; `ClientCompanyClient` gate on client-only portal resources (denies subcontractors). All defined in `AppServiceProvider`.
- Testing: PHPUnit (SQLite in-memory) for backend, Jest for frontend.
