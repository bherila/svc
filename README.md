# SVC

SVC is an open-source-ready operations platform for independent service businesses. It is being extracted from the mature client-management domain in [`bherila/2025-website`](https://github.com/bherila/2025-website) without copying production data.

The application will cover clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows. Banking and tax systems remain external integrations.

## Current status

The current alpha establishes:

- organization workspaces and role-bearing memberships;
- tenant-isolated client companies, projects, and tasks;
- a permissioned client portal that excludes internal-only work;
- configurable OAuth 2.0 identity-provider authentication with PKCE;
- a Stripe SDK boundary with signature verification and redacted configuration health checks;
- engagement operations for proposals, acceptance, agreements, recurring billing,
  time entries, invoices, payments, PDFs, and authenticated client views;
- private tenant-scoped attachments with staged promotion, digest verification,
  repair tooling, and guarded web1-to-x-data mirroring;
- dry-run-first legacy migration and verification commands with explicit public-UUID
  identity bindings, redacted inventories, idempotent provenance ledgers, and
  source-change detection;
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

The integrated workspace screen is available at
`/workspaces/{workspace-public-id}/operations`. Legacy migration is inert until
an allowlisted read-only source is configured:

```bash
php artisan svc:migrate:legacy --source=legacy --workspace=<workspace-public-id> --format=json
php artisan svc:migrate:legacy --source=legacy --workspace=<workspace-public-id> --apply --format=json
php artisan svc:migrate:legacy:verify --run=<run-public-id> --format=json
```

The first command is a no-write inventory. Apply runs that report skips or
failures exit nonzero, and verification never prints source row values.

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

## Database snapshots

Before any real tenant or invoice rows are entered, pull-only database snapshots
must be enabled and verified. The guarded command exports a consistent snapshot
on web1, verifies its gzip stream and SHA-256 digest, and stores it under
`~/proj/x-data/svc-database/` for restic coverage. This is deliberately separate
from `~/proj/x-data/svc/`, whose contents are managed by the private-file mirror:

```bash
pnpm db-snapshot pull            # dry-run; no writes
pnpm db-snapshot pull --apply    # web1 -> x-data only
pnpm db-snapshot verify
```

There is deliberately no push or restore subcommand. Snapshot files, checksums,
and manifests are private data and must never be added to this public repository.
