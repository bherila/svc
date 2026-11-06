# Runbook: move environment payment credentials into a workspace connection

Operator procedure for `svc:payments:migrate-environment` (added in #618). It
turns the deployment's `STRIPE_*` and `SQUARE_*` environment credentials into
the first payment connection of one workspace, once, by hand.

Claude and CI never run this against a real database. The command and its tests
(`tests/Feature/Payments/MigrateEnvironmentConnectionsCommandTest.php`) target
local disposable databases only. Everything below is for the operator on the
deployment host.

## What it does, in one paragraph

Until the command has run, the environment credentials serve every workspace,
exactly as before #618. After `--apply`, for each processor that has environment
credentials, one `workspace_payment_connections` row (`origin = environment`)
belongs to the chosen workspace, and the environment credentials serve **no
other workspace** for that processor. Other workspaces become "not connected"
until their owners enter their own keys or reuse this connection.

## Before you start

1. **Pick the workspace** that is paid into this account and has its public id
   (a UUID; shown in the workspace URL). Without `--workspace` the command uses
   `SQUARE_WORKSPACE`.
2. **Check no other workspace takes payments through the environment keys.**
   After the migration they stop. Each of them needs its own connection (or to
   reuse this one; reuse needs the same owner) on the Payments page. Plan that
   before running, not after.
3. **Confirm the schema is current.** `php artisan migrate:status` shows the
   `workspace_payment_connections` migrations (create, and add publishable key)
   as run. The command does not run migrations.
4. **Confirm the environment values are present and right**: `STRIPE_SECRET_KEY`
   and `STRIPE_WEBHOOK_SECRET` for Stripe; `SQUARE_ACCESS_TOKEN`,
   `SQUARE_WEBHOOK_SIGNATURE_KEY` and `SQUARE_ENVIRONMENT` (`production` by
   default) for Square. A processor with no secret/token is skipped
   (`no_environment_credentials`).
5. **Know that Square uses the account's first location.** The migration has no
   location setting; it stores the first location Square returns. If the account
   has several and payments use another, enter the connection by hand on the
   Payments page instead.
6. **Take a database backup or snapshot.** The only full undo is a database
   change (see Rollback).
7. **Record the baseline.** `php artisan svc:square:status --format=json` and a
   screenshot of the workspace's Payments page, which says "Using the
   deployment's Stripe account until a workspace connection is set up." for each
   processor served by the environment.
8. **Do not remove the environment values afterwards.** The copied secrets are
   stored on the connection, but the card form still reads
   `STRIPE_PUBLISHABLE_KEY` for an environment-origin connection, and
   `SQUARE_VERSION` and the timeouts still configure
   Square calls. A follow-up removes the environment path.

## Step 1: preview (the default)

```bash
php artisan svc:payments:migrate-environment --workspace=<workspace public id>
```

The command is a preview unless `--apply` is given. A preview calls Stripe and
Square **not at all** and writes nothing. (`--dry-run` and `--pretend` are
deprecated aliases for the same preview; they cannot be combined with
`--apply`.) Add `--format=json` for machine-readable output.

Per processor it reports one of:

| Status | Meaning | Exit code |
| --- | --- | --- |
| `would_migrate` | `--apply` would store the environment credentials as this workspace's connection | 0 |
| `already_migrated` | A connection from the environment already exists for this processor; nothing to do | 0 |
| `no_environment_credentials` | No secret/token in the environment for this processor | 0 |
| `workspace_already_connected` | The workspace already has a connection someone entered; the command will never replace it | 1 |
| `rejected` (apply only) | The processor refused the credential; the reason is printed, never the credential | 1 |

Because a preview calls no processor, `would_migrate` does not prove the
credentials are valid. A bad key shows up as `rejected` on `--apply`, which
writes nothing for that processor.

Stop and resolve anything that is not `would_migrate` or `already_migrated`
before continuing. An exit code of 2 means the workspace was not named or not
found.

## Step 2: apply

```bash
php artisan svc:payments:migrate-environment --workspace=<workspace public id> --apply
```

For each processor with a `would_migrate` preview it:

1. validates the credential with the same read the Payments page uses (Stripe:
   read the account; Square: list locations);
2. stores one connection for the workspace, `origin = environment`, with the
   key or token and webhook secret/signature key copied from the environment,
   encrypted at rest;
3. prints the connection's new per-workspace webhook URL
   (`<APP_URL>/api/webhooks/<stripe|square>/<token>`).

Each processor is independent: Stripe can be migrated while Square is
`rejected`. Running it again is safe. A processor already migrated is skipped
and nothing is called.

### What it changes

- **Database, `workspace_payment_connections`:** one new row per migrated
  processor. Stripe's `webhook_endpoint_id` stays null because SVC did not
  create that endpoint and will never delete it. For Square it also stores the
  location and sets `workspaces.has_square_connection` for the workspace.
- **Who is served:** the environment credentials stop serving every workspace
  other than the target, for that processor, immediately. A workspace that
  revoked its own connection never falls back to the environment either.
- **Webhooks:** the registered legacy URLs (`/api/webhooks/stripe`,
  `/api/webhooks/square`) keep working. They are verified with the migrated
  secret and confined to the target workspace. The new per-workspace URL it
  printed is optional until you re-point the processor's dashboard at it.
- **Scheduled jobs:** the Square sync, reconcile and prune commands now run for
  workspaces with a connection, so the target workspace's Square mirror continues
  under the connection.

It does **not** change anything at Stripe or Square: no webhook endpoint is
created, changed or deleted. It does not edit `.env`, does not write audit
events, and stores no publishable key (the deployment's `STRIPE_PUBLISHABLE_KEY`
keeps serving the card form).

## Step 3: verify

### On the Payments page

Open `/workspaces/<workspace public id>/payments` as an owner or admin of the
workspace.

- Each migrated processor's card now says **Connected.** and no longer says
  "Using the deployment's ... account until a workspace connection is set up."
- **Account** shows the Stripe account name or Square business, **Key** shows
  the masked hint (for example `sk_live_…9z9z`), and **Mode** shows Live/Test or
  Production/Sandbox as expected. For Square, **Location** and the **Webhook
  URL** are shown.
- No **Publishable key** row for Stripe. That is expected for this connection.
- Open the Payments page of a workspace that is **not** the target and confirm it
  says "Not connected" for the processor. That is the intended consequence.

### With `svc:square:status`

```bash
php artisan svc:square:status
php artisan svc:square:status --format=json
```

Expect `Connected workspaces: 1` (or more, if others have connected their own),
with the target workspace's public id, its `Environment`, `Webhook signature
key: present`, and no run marked abandoned or truncated. `configured` is true.

Right after the migration the exit code can be 1: it requires a completed
mirror sync within the last hour, and `Last completed sync` reads `never` until
the first one finishes. The scheduler runs `svc:square:sync --apply` every
fifteen minutes; to see it settle sooner run it once by hand:

```bash
php artisan svc:square:sync --apply
php artisan svc:square:status
```

A healthy result has exit code 0, `Sync overdue: no` and a recent `Last
completed sync`. A failed or truncated run is the thing to investigate before
declaring the migration done.

### Stripe

There is no Stripe status command. After the first real or test event, check the
Stripe Dashboard (Developers, Webhooks) shows 2xx deliveries to the webhook
endpoint, and that a new payment for the target workspace's invoice completes
and is recorded. If you choose to make a test payment, use a test-mode
deployment or a small invoice you intend to refund.

## Rollback

Choose by what you need. Nothing here touches Stripe or Square.

### Stop using the connection (Disconnect)

On the Payments page choose **Disconnect**. Charging for that workspace stops at once
and the stored secrets are cleared. **This does not hand the workspace back to
the environment credentials**: a processor that has ever had an
environment-origin connection stays off the environment path, and so does a
workspace that has had a connection. Use it to switch a workspace off, not to
undo the migration.

### Replace it with a key entered by hand (the usual fix)

Choose **Replace key** and enter the restricted key (and, for Stripe, the publishable key) or the Square
token on the Payments page. That revokes the environment-origin row and creates
an `entered` connection. For Stripe, SVC also creates the workspace's own
webhook endpoint and verifies a publishable key against the account (the
restricted key then needs SetupIntents: Write; see
`docs/help/payments.md`). Use this when the migration worked but the connection
needs a different key, location or mode.

### Full revert to the environment path

Only if the migration must be undone completely and the follow-up that removes
the environment path has not shipped. There is no command for it. With the
backup from step 6 in hand, as a database change by the operator:

```sql
-- Look first. The second query must return no rows: a workspace that reused the
-- connection would be left pointing at nothing.
SELECT id, public_id, workspace_id, processor, revoked_at
  FROM workspace_payment_connections WHERE origin = 'environment';
SELECT id, workspace_id FROM workspace_payment_connections
 WHERE reuses_connection_id IN (SELECT id FROM (
         SELECT id FROM workspace_payment_connections WHERE origin = 'environment') AS env);

START TRANSACTION;
DELETE FROM workspace_payment_connections WHERE origin = 'environment';
UPDATE workspaces SET has_square_connection = EXISTS (
  SELECT 1 FROM workspace_payment_connections c
   WHERE c.workspace_id = workspaces.id AND c.processor = 'square' AND c.revoked_at IS NULL);
COMMIT;
```

Then wait about a minute for the "any active connection" cache to expire (or
restart PHP workers), and re-run step 3 in reverse: the Payments pages say
"Using the deployment's ... account" again, and the legacy webhook URLs verify
with `STRIPE_WEBHOOK_SECRET` / `SQUARE_WEBHOOK_SIGNATURE_KEY` for every workspace
as before. Workspaces that entered their own connections in the meantime keep
them.

Re-running the migration later is safe after a full revert.
