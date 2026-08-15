# Extraction plan

The extraction is staged so the existing client-management system remains usable throughout the transition.

1. **Foundation:** establish the standalone application, workspace tenancy, configurable identity, privacy-safe CI, and client-company vertical slice.
2. **Engagement workflow:** move projects, tasks, time entries, proposals, and agreements while preserving legacy identifiers in an explicit migration map.
3. **Billing:** move invoices, line items, recurring billing, manual payments, generated PDFs, email delivery, and the optional Stripe adapter.
4. **Files and integrations:** move private attachments, add external finance-reconciliation references, and publish a narrow authenticated API.
5. **Cutover:** rehearse an idempotent migration against synthetic data, shadow-read production, verify counts and hashes, freeze legacy writes briefly, migrate, and retain a rollback window.

No production data belongs in this repository. Migration tooling must be dry-run by default, owner-scoped, idempotent, provenance-aware, and independently verifiable before any production application is authorized.
