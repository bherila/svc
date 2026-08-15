# SVC

SVC is an open-source-ready operations platform for independent service businesses. It is being extracted from the mature client-management domain in [`bherila/2025-website`](https://github.com/bherila/2025-website) without copying production data.

The application will cover clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows. Banking and tax systems remain external integrations.

## Current status

The first foundation slice establishes:

- organization workspaces and role-bearing memberships;
- tenant-isolated client companies, projects, and tasks;
- a permissioned client portal that excludes internal-only work;
- configurable OAuth 2.0 identity-provider authentication with PKCE;
- an explicit extraction and integration boundary.

See [the architecture](docs/architecture.md) and [the extraction plan](docs/extraction-plan.md).

## Local development

```bash
cp .env.example .env
composer install --no-interaction --prefer-dist
pnpm install --frozen-lockfile --prefer-offline
php artisan key:generate
php artisan migrate
composer dev
```

The generated SQLite database and `.env` are local-only. Do not import production data into this checkout.
