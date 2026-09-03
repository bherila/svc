# SVC

SVC is an open-source-ready operations platform for independent service businesses: workspaces, clients, projects, agreements, time, invoices, payments, files, and subcontractor workflows. Banking and tax systems remain external integrations.

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
- a retained provenance ledger from the one-time onboarding import, kept as
  read-only history after the import tooling was retired;
- an explicit product and integration boundary.

See [the architecture](docs/architecture.md), [the interface guide](docs/ui.md),
[the product roadmap](docs/onboarding-import-plan.md),
[the private file storage plan](docs/file-storage-plan.md), and
[the retired external data import](docs/external-data-import.md).

For the billing rules themselves — retainer draw-down, rollover, cadence
cycles, deferred allocation, milestones, and overpayment credits — see
[client management and invoicing](docs/client-management/README.md). That
reference states the intended behaviour and marks which parts SVC implements
today.

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
`/workspaces/{workspace-public-id}/operations`.

Onboarding data import is not a feature of this application. A one-time import
brought one workspace's history in from a predecessor system; that tooling has
been retired and its commands no longer exist. The ledger tables it wrote are
still here and still readable, because they are the only record of which row
came from where. See [the retired external data import](docs/external-data-import.md).

## Deployment

A green push to `main` deploys the empty alpha to the shared cPanel account using
GitHub-hosted `ubuntu-24.04-arm` runners and the protected `web1` environment:

- Laravel root: `~/svc-laravel`
- Webroot: `~/svc.bherila.net` → `svc-laravel/public`
- Database: dedicated `bherila_svc` database and least-scope user
- PHP: `ea-php85` for both the vhost and deployment Artisan commands
- Private files: `storage/app/private/svc-blobs`, excluded from code-deploy deletion
- OAuth signing keys: `storage/app/private/oauth`, generated once on the server and
  excluded from code-deploy deletion

The deployment installs the server-held `.env`, runs schema migrations, performs a
write/read/delete probe against the private disk, checks the redacted Stripe status,
and verifies `/up` plus the OAuth redirect. It does not seed or import business data.

### There is no queue worker

Nothing on the shared account runs `queue:work`, so anything dispatched to the
database queue is written to `jobs` and never read. Sending an invoice used to
be dispatched that way: the row sat unread, the delivery stayed `pending`
forever, and the screen said "Invoice delivery queued." Under PHPUnit
`QUEUE_CONNECTION=sync` runs jobs inline, so the suite asserted a state
production could never reach.

Until a worker exists, work that must actually happen has to happen in the
request. If you add a queued job, either arrange for it to be run or do not
queue it — and do not let a test environment's `sync` driver stand in for a
worker that is not there.

### Invoice delivery status

Invoices are sent through Brevo. Our own delivery `status` records only that
the message left here; whether it was delivered, bounced or blocked arrives
later over a webhook. Point Brevo's transactional event webhook at
`POST /api/webhooks/brevo` and have it send `BREVO_WEBHOOK_TOKEN` as an
`X-Webhook-Token` header (or a `?token=` query parameter). Brevo signs nothing,
so that shared secret is the only guard: with none configured the endpoint
refuses every request.

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

## Finance reconciliation API

SVC exposes a narrow, versioned bearer-token API for listing invoice payments and
linking them to transaction UUIDs owned by an external finance system. Tokens are
expiring, stored only as hashes, and require explicit `finance.read` or
`finance.reconcile` abilities. See [the API contract](docs/finance-api.md).

## Connect an MCP client

SVC's remote MCP endpoint is `https://svc.bherila.net/api/v1/mcp`. It uses OAuth
with Bherila.net for sign-in; do not create or paste a personal API token. The
browser opened by the login command shows the requested SVC permissions before
continuing to the client.

Install it for your user account, then complete the browser login:

```bash
# Codex CLI
codex mcp add svc --url https://svc.bherila.net/api/v1/mcp \
  --oauth-resource https://svc.bherila.net/api/v1
codex mcp login svc

# Claude Code CLI
claude mcp add --transport http --scope user svc https://svc.bherila.net/api/v1/mcp
claude mcp login svc
```

Restart the client if it was already running. The MCP initialization response teaches
compatible harnesses to call `context.get` before choosing a workspace, and exposes
guided `log-time-across-projects` and `prepare-invoice-safely` prompts when the client
supports MCP prompts. Access remains limited by the signed-in user's current SVC role
and granted OAuth scopes. Invoice payment is not an MCP capability; invoice results
provide the browser payment URL when one is available.

Time-entry tools support listing and idempotent logging plus optimistic-locking
updates and soft deletion. Updates and deletion are limited to authorized draft
entries that have not been approved or invoiced. Set
`AGENT_API_TIME_ENTRY_WRITES_ENABLED=false` for an emergency time-write cutoff;
`AGENT_API_WRITES_ENABLED` remains the separate full workflow-write cutover.

Browser-based MCP clients must use an exact origin listed in
`AGENT_API_MCP_ALLOWED_ORIGINS`; unlisted origins receive no CORS authorization and
their MCP requests are rejected. This browser-origin list does not change which HTTP
`Host` values the MCP endpoint accepts; service hosts come only from `APP_URL` and the
configured OAuth resource. Native clients that omit `Origin` continue to work.
Dynamic public-client registrations are marked at creation, updated when used, and
pruned after 30 inactive days by the scheduled
`svc:oauth:prune-dynamic-clients` command (the retention is configurable).
