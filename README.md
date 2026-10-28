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

For a Codex environment, run `bash codex/setup.sh`. It installs PHP 8.5 and
required extensions through apt when needed, installs the locked Composer and
pnpm dependencies, and creates `.env` and an application key if absent. It keeps
an existing environment and key and does not run database migrations. Set
`CODEX_SKIP_SYSTEM_DEPENDENCIES=1` when the system packages are already managed
by the environment.

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

SVC retains `char(36)` UUID storage on MariaDB, including `foreignUuid()` columns.
The application registers a MariaDB schema grammar that preserves this contract
across the server's native-UUID boundary at 10.7, without changing the MariaDB query
grammar or JSON validation. The pre-migration database status check accepts newer
MariaDB servers only with that grammar installed. CI exercises the full MariaDB
lane on 11.4 and checks newly created foreign keys against retained character UUIDs.

Before maintenance, persistent-path conversion or database risk, a read-only host
preflight requires account-owned regular files at `~/.config/svc/deployment.env`,
`~/.config/svc/database.env` and both existing Passport key paths. File modes must be
0400 or 0600; unmanaged aliases and incomplete pairs fail without changing anything.
It checks metadata only and never opens secret contents. Candidate use-site checks
remain in place because preflight cannot prevent a later host configuration change.

Live OAuth verification parses the authorization redirect before issuing the
short-lived authenticated MCP smoke credentials. It requires the exact provider
endpoint, callback and `identity:read` scope, authorization-code response type,
nonempty client/state and a valid S256 challenge, with one value per required
parameter. Redirect query values never appear in arguments or verification logs.

The deploy action owns SVC's one cron line, the every-minute `schedule:run` from
`cd "$HOME/svc-laravel"` with a 1G memory limit and `# JOB:svc-laravel-scheduler`. It pauses
the line before maintenance, replaces any legacy line run from that directory and installs the
canonical line once the release serves; other applications' lines are left alone. Preflight
refuses an SVC line spelled any other way (`~/svc-laravel`, a release path, a bare job id),
because the action could neither pause nor replace it. Live verification and the post-finalize
diagnostic then require exactly one SVC line, the canonical one, and report fixed labels only.

The `deployment-diagnostic` workflow (manual dispatch from `main`, behind the `web1`
environment) re-checks a live deployment without changing it. Give it the release id and
full commit from a reviewed deploy run; it runs the pinned action's finalized runtime audit
(identity, canonical paths, serving, no `deploy.lock`, no open transactions, zero pending
migrations, persistent database), the cron proof and the hashed asset audit, then re-proves
the deployment generation so a deploy that began mid-run fails it. It issues no credentials
and runs no storage write probe.

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
`X-Webhook-Token` header or `Authorization: Bearer` credential. Query-string
credentials are rejected. Brevo signs nothing, so that shared secret is the
only guard: with none configured the endpoint refuses every request. Check a
deployment without exposing either secret with `php artisan svc:brevo:status
--format=json`. `php artisan svc:square:status --format=json` does the same for
Square and also reports the last mirror sync; see
[Square payment status](docs/square-status.md#status-probe).

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
linking them to transaction UUIDs owned by an external finance system. Its finance
integration tokens are expiring, stored only as hashes, and require explicit
`finance.read` or `finance.reconcile` abilities; OAuth clients use the
`finance:read` / `finance:reconcile` scopes, and the same operations are MCP tools
and `svc:finance:*` commands. See [the API contract](docs/finance-api.md).

## Connect an MCP client

SVC's remote MCP endpoint is `https://svc.bherila.net/api/v1/mcp`. It uses OAuth
with Bherila.net for sign-in; do not create or paste a personal API token. The
browser opened by the login command shows the requested SVC permissions before
continuing to the client.

Install it for your user account, then complete the browser login:

```bash
# Codex CLI
codex mcp add svc --url https://svc.bherila.net/api/v1/mcp \
  --oauth-resource https://svc.bherila.net/api/v1/mcp
codex mcp login svc

# Claude Code CLI
claude mcp add --transport http --scope user svc https://svc.bherila.net/api/v1/mcp
claude mcp login svc
```

The MCP endpoint is its own OAuth protected resource: its 401 challenge points at
`/.well-known/oauth-protected-resource/api/v1/mcp`, and a token must be requested for
`resource=<site>/api/v1/mcp`. REST clients use `/api/v1`, the resource a client that
names none receives. Neither token is accepted on the other endpoint, so an MCP
connection authorized when MCP shared the REST resource has to authorize again once.

Restart the client if it was already running. The MCP initialization response teaches
compatible harnesses to call `context.get` before choosing a workspace, and exposes
guided `log-time-across-projects` and `prepare-invoice-safely` prompts when the client
supports MCP prompts. Access remains limited by the signed-in user's current SVC role
and granted OAuth scopes. MCP can record money already received with the separately enabled `payments.record` capability, and correct a recorded payment's method, reference or date with `payments.correct`; it cannot initiate a charge or change an amount. Invoice results provide the browser payment URL when one is available.

Time-entry tools support listing and idempotent logging plus optimistic-locking
updates and soft deletion. Updates and deletion are limited to authorized draft
entries that have not been approved or invoiced. Set
`AGENT_API_TIME_ENTRY_WRITES_ENABLED=false` for an emergency time-write cutoff;
`AGENT_API_WRITES_ENABLED` remains the separate full workflow-write cutover.

Agent-initiated **invoice** writes need a second flag inside that cutover:
`AGENT_API_INVOICE_WRITES_ENABLED` defaults to false, and the six invoice tools
(`create_draft`, `update_draft`, `discard_draft`, `issue`, `send`, `void`), their
REST routes, the `billing:write` and `billing:deliver` capabilities, and the
`prepare-invoice-safely` prompt require **both** flags. The boundary follows
blast radius: creating a task is recoverable bookkeeping, while `issue`
allocates approved time irreversibly and `send` puts a document in front of a
paying client. Turning `AGENT_API_WRITES_ENABLED` off still withdraws
everything; the invoice flag only ever narrows further.

Browser-based MCP clients must use an exact origin listed in
`AGENT_API_MCP_ALLOWED_ORIGINS`; unlisted origins receive no CORS authorization and
their MCP requests are rejected. This browser-origin list does not change which HTTP
`Host` values the MCP endpoint accepts; service hosts default to `APP_URL` and the
configured OAuth resource. Reviewed reverse-proxy deployments can replace those defaults
with exact `host[:port]` authorities in `AGENT_API_MCP_ALLOWED_HOSTS`. Native clients that
omit `Origin` continue to work. OAuth discovery, client registration and the token
endpoint have their own, separate list (`oauth_server.cors` in `config/bherila-auth.php`),
which admits the hosted agents' browser origins.
Dynamic public-client registrations are marked at creation, updated when used, and
pruned after 30 inactive days by the scheduled
`bherila-auth:prune-dynamic-clients` command (`AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS`
sets the retention; `svc:oauth:prune-dynamic-clients` remains as an alias).
Expired Passport tokens and authorization codes are purged daily, 31 days past
their expiry, by a scheduled `passport:purge --expired --hours=744`; see
[Token retention](docs/mcp-operations.md#token-retention) for why revoked rows
are not purged sooner.

## Agent skills

Time gets logged from the repository the work happened in, not from this one, so
the skills that drive the MCP server are versioned here but installed for the
user account:

```bash
pnpm run skills:install
```

That symlinks everything under `.claude/skills/` into `~/.claude/skills/`, so a
later `git pull` updates an installed skill without a second step. Set
`CLAUDE_SKILLS_DIR` to install somewhere else.

`log-time` resolves the current repository to an SVC project, compares the
signed-in author's shipped work with their existing time entries, and logs the
result after confirming the destination and exact entries. It deliberately
holds **no** client or project data:
the repository-to-project mapping is workspace data read back from
`projects.list`, with a personal `~/.claude/svc-time-projects.json` as a bridge
for projects that have no `repository` recorded yet. Keep it that way — this
repository is public, and a mapping table here would be a client record in it.
