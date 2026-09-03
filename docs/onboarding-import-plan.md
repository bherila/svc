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
5. **Onboarding import:** *complete and retired.* A dry-run-first, idempotent,
   provenance-ledgered path brought one workspace's existing client, project and
   billing data in from a predecessor system. It ran, the workspace it served no
   longer needs it, and the tooling has been removed; its ledger tables remain as
   read-only history.

No production data belongs in this repository. Should a second onboarding ever be
needed, the import tooling that serves it must be dry-run by default,
workspace-scoped, idempotent, provenance-aware, and independently verifiable
before any customer is authorized to run it against a live source — and it must
reconcile row existence, not only amounts.

See the [retired external data import](external-data-import.md) for what that
last requirement cost to learn, and the [private file storage plan](file-storage-plan.md)
for the storage safety contract.
