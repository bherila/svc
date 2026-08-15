# SVC

SVC is an open-source-ready operations platform for independent service businesses. It is being extracted from the mature client-management domain in [`bherila/2025-website`](https://github.com/bherila/2025-website) without copying production data.

The application will cover clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows. Banking and tax systems remain external integrations.

## Current status

The first foundation slice establishes:

- organization workspaces and role-bearing memberships;
- tenant-isolated client companies, projects, and tasks;
- a permissioned client portal that excludes internal-only work;
- configurable OAuth 2.0 identity-provider authentication with PKCE;
- a Stripe SDK boundary with signature verification and redacted configuration health checks;
- an explicit extraction and integration boundary.

See [the architecture](docs/architecture.md), [the extraction plan](docs/extraction-plan.md),
[the private file storage plan](docs/file-storage-plan.md), and
[the legacy migration plan](docs/legacy-migration-plan.md).

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

## Deployment

A green push to `main` deploys the empty alpha to the shared cPanel account using
GitHub-hosted `ubuntu-24.04-arm` runners and the protected `web1` environment:

- Laravel root: `~/svc-laravel`
- Webroot: `~/svc.bherila.net` → `svc-laravel/public`
- Database: dedicated `bherila_svc` database and least-scope user
- PHP: `ea-php85` for both the vhost and deployment Artisan commands
- Private files: `storage/app/private/svc-blobs`, excluded from code-deploy deletion

The deployment installs the server-held `.env`, runs schema migrations, performs a
write/read/delete probe against the private disk, checks the redacted Stripe status,
and verifies `/up` plus the OAuth redirect. It does not seed or import business data.

## Private-file mirror

web1 is authoritative. The guarded mirror script syncs its private root into
`~/proj/x-data/svc`, which is covered by the local restic backup:

```bash
pnpm blobs pull            # dry-run, web1 -> x-data
pnpm blobs pull --apply
pnpm blobs verify
```

`push` exists only for restore-after-loss, refuses an empty mirror, and never deletes
remote files unless both `--prune` and interactive confirmation are supplied.
