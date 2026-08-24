# Product roadmap

SVC is built in layers so each one is independently useful and testable.

1. **Foundation:** standalone application, workspace tenancy, configurable
   identity, privacy-safe CI, and the client-company vertical slice.
2. **Engagement workflow:** projects, tasks, time entries, proposals, and
   agreements.
3. **Billing:** invoices, line items, recurring billing, manual payments,
   generated PDFs, email delivery, and the optional Stripe adapter.
4. **Files and integrations:** private attachments, external finance-
   reconciliation references, and a narrow authenticated API.
5. **Onboarding import:** a dry-run-first, idempotent, provenance-ledgered
   path for a new workspace to bring in its existing client, project, and
   billing data from an external source instead of starting from zero.

No production data belongs in this repository. Import tooling must be dry-run
by default, workspace-scoped, idempotent, provenance-aware, and independently
verifiable before any customer is authorized to run it against a live source.

See the [external data import contract](external-data-import.md) and [private
file storage plan](file-storage-plan.md) for the implementation and safety
contracts.
