# SVC Agent Instructions

SVC is a standalone Laravel 13 and React operations platform for independent service businesses: workspaces, clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows.

- Keep tenant ownership explicit through workspaces and memberships. Every tenant-owned query and write must be workspace-scoped and covered by an isolation test.
- Treat OAuth, file storage, email, payment processing, and finance reconciliation as replaceable adapters. Bherila-specific behavior belongs in configuration or adapters, not domain models.
- Never copy production databases, client records, financial documents, credentials, or private fixtures into this repository.
- Never commit exports, uploads, invoices, payment processor records, or real client/billing test fixtures. Use generated synthetic data with reserved domains and obvious test identifiers.
- Do not write production data while developing product features. Migrations and tests target local disposable databases only.
- Run `pnpm run hooks:install` once per clone so staged changes receive the same disclosure scan enforced by CI.
- Use `pnpm`, never `npm ci`. Run focused PHP and frontend checks during iteration, then the repository checks before publication.
- Before re-requesting review after a fix, update `origin/main`, run `composer test:mutation-diff`, and require a green result. Escaped mutants need either the missing assertion or an `@infection-ignore-all` reason comment.
- Prefer one reviewable PR with stacked commits for a coherent slice of work.
