# SVC Agent Instructions

SVC is a standalone Laravel 13 and React application extracted from the client-management domain of `bherila/2025-website`.

- Keep tenant ownership explicit through workspaces and memberships. Every tenant-owned query and write must be workspace-scoped and covered by an isolation test.
- Treat OAuth, file storage, email, payment processing, and finance reconciliation as replaceable adapters. Bherila-specific behavior belongs in configuration or adapters, not domain models.
- Never copy production databases, client records, financial documents, credentials, or private fixtures into this repository.
- Do not write production data while developing product features. Migrations and tests target local disposable databases only.
- Use `pnpm`, never `npm ci`. Run focused PHP and frontend checks during iteration, then the repository checks before publication.
- Prefer one reviewable PR with stacked commits for a coherent extraction slice.
