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

## Before you approve

Do this first, whoever will run `--apply`. The preview writes nothing and calls
neither Stripe nor Square, so it is safe to run as often as you like:

```bash
php artisan svc:payments:migrate-environment
php artisan svc:payments:migrate-environment --format=json   # same facts, for a script
```

Read the inventory it prints, one block per processor, and check these before you
say yes:

1. **Who would own the connection, and why.** The block says which workspace
   receives it and the rule: you named it with `--workspace`, or
   `SQUARE_WORKSPACE` names it and nothing else could plausibly own it. If it
   says the owner is **not decided**, more than one workspace could own the
   connection (or none was named). `--apply` refuses in that case and writes
   nothing; name the owner with `--workspace=<public id>`.
2. **Who else uses the environment keys today.** The table lists every workspace
   that currently relies on them, with what would be affected: open invoices a
   client can pay online, autopay companies, saved cards, pending payments,
   autopay attempts held for review, and webhook events delivered in the last 90
   days. Every workspace except the owner **stops being served** the moment
   `--apply` finishes. Decide, for each one, who connects its own account, and
   when.
3. **The account it identifies.** The preview does not call Stripe or Square, so
   the Stripe account id and Square merchant id read **not checked**. What it
   does show is the masked key (`sk_live_…9z9z`) and its mode. Confirm that is the
   key you mean, and that live or test is what you expect. `--apply` reads the
   account from the processor and stores it; you see it on the Payments page.
4. **The Square location.** Also not checked in a preview. The rule is the first
   location Square returns when `--apply` runs; there is no setting. If the
   inventory says other locations exist, or cannot tell, and payments use any
   location but the first, enter the Square connection by hand instead.
5. **Existing connections.** A workspace that already has an active connection
   at a processor is **skipped**, never replaced (exit code 1). Other
   workspaces' connections are untouched.
6. **Rollback.** Read the Rollback section below. It is more than "restore a
   backup", but a payment already taken or a message already sent cannot be
   taken back.

An example, synthetic data only (invented names and ids, no real credentials;
counts trimmed to what fits):

```text
Migration impact inventory - read only: nothing is written and neither Stripe nor Square is contacted.

Stripe
 Environment credential: sk_live_…9z9z (live mode).
 Account: not checked. A preview never calls Stripe; --apply reads the Stripe account id from Stripe and stores it.
 Owner: not decided. The environment's Stripe key serves every workspace that has not connected its own, and 1 other workspace has Stripe activity; SQUARE_WORKSPACE only names the Square workspace. Choose one with --workspace.
 Result: Blocked: more than one workspace could own this connection. Re-run with --workspace=<public id>.

 Workspaces that depend on the environment Stripe credential today:
 | Workspace             | Role                       | Open payable invoices | Autopay companies | Saved cards | Pending payments | Held autopay attempts | Webhook events (90 days) |
 | Example Studio        | Loses access unless chosen | 3                     | 1                 | 2           | 0                | 0                     | 2                        |
 | Example Side Business | Loses access unless chosen | 1                     | 0                 | 0           | 0                | 0                     | 0                        |
 - Example Studio would stop being served by the environment Stripe credential unless it is chosen as the owner; its owner would need to connect Stripe on the Payments page.
 - Example Side Business would stop being served by the environment Stripe credential unless it is chosen as the owner; its owner would need to connect Stripe on the Payments page.

Square
 Environment credential: …mple (production mode).
 Owner: Example Studio (00000000-0000-4000-8000-000000000001). SQUARE_WORKSPACE names it, and it is the only workspace the environment's Square token has ever served.
 Location: not checked. Rule: The first location Square returns for the access token when --apply runs. The migration has no location setting.
 Other locations: yes. This workspace's local Square mirror lists 2 (Main Street, Pop-up Stand). If payments use any but the first one Square returns, enter the connection by hand instead.

Name the workspace with --workspace=<public id>: the inventory shows who could own the connection.
```

Here the owner would pick "Example Studio" with
`--workspace=<its public id>`, re-read the block (Example Side Business then reads
**Loses access**), and approve only if that is acceptable. The preview is the
record of what you approved; keep its output with the change ticket.

## Before you start

1. **Pick the workspace** that is paid into this account and has its public id
   (a UUID; shown in the workspace URL). Name it with `--workspace`. Without it
   the command falls back to `SQUARE_WORKSPACE`, but only when no other workspace
   could plausibly own the Stripe connection; otherwise `--apply` refuses and
   writes nothing (see Before you approve).
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
6. **Take a database backup or snapshot.** It is the safety net for the manual
   database reversal in Rollback, which deletes rows; it is not the only way
   back (see Rollback for what each option undoes).
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
Square **not at all** and writes nothing: it only reads the local database, and
prints the impact inventory described in Before you approve. (`--dry-run` and
`--pretend` are deprecated aliases for the same preview; they cannot be combined
with `--apply`.) Add `--format=json` for machine-readable output; the inventory
is under the `inventory` key.

Per processor it reports one of:

| Status | Meaning | Exit code |
| --- | --- | --- |
| `would_migrate` | `--apply` would store the environment credentials as this workspace's connection | 0 |
| `already_migrated` | A connection from the environment already exists for this processor; nothing to do | 0 |
| `no_environment_credentials` | No secret/token in the environment for this processor | 0 |
| `workspace_already_connected` | The workspace already has a connection someone entered; the command will never replace it | 1 |
| `ownership_ambiguous` | Several workspaces could own the connection and none was named; `--apply` refuses | 2 |
| `needs_workspace` | No workspace was named and `SQUARE_WORKSPACE` does not name one; `--apply` refuses | 2 |
| `rejected` (apply only) | The processor refused the credential; the reason is printed, never the credential | 1 |

Because a preview calls no processor, `would_migrate` does not prove the
credentials are valid. A bad key shows up as `rejected` on `--apply`, which
writes nothing for that processor.

Stop and resolve anything that is not `would_migrate`, `already_migrated` or
`no_environment_credentials` before continuing. An exit code of 2 means the
workspace was not named, not found, or ownership is ambiguous: nothing was
written, and `--apply` gives the same refusal.

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

Nothing here touches Stripe or Square. The migration changes this deployment's
own database and routing, and that part can be reversed. What cannot be reversed
is anything that already left the building under it. Choose by what you need.

### What can be undone, and how

| You want | Use | Undoes | Does not undo |
| --- | --- | --- | --- |
| Stop charging for one workspace | **Disconnect** on its Payments page | Charging stops at once; the stored secrets are cleared | It does not hand the workspace back to the environment keys (see below) |
| Keep the workspace working with a different key, location or mode | **Replace key** on its Payments page | Revokes the environment-origin row and creates an `entered` connection | Nothing already charged |
| Put every workspace back on the environment keys | The conditional manual database reversal below | Deletes the environment-origin connection rows and resets the Square hint | Anything in "What cannot be undone" |

A backup or snapshot is a safety net for the database reversal, not the only
route back: Disconnect and Replace key need no restore.

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

### Full revert to the environment path (conditional, manual)

Only if the migration must be undone completely and the follow-up that removes
the environment path has not shipped. There is no command for it. It is a
database change by the operator, and it is only safe when **all** of these hold:

- the environment values (`STRIPE_*`, `SQUARE_*`) are still present and still
  valid, because they serve again as soon as the rows are gone;
- the second query below returns no rows, so no workspace reuses the connection
  and would be left pointing at nothing;
- if you re-pointed a processor dashboard at the per-workspace webhook URL the
  command printed, you point it back at the legacy URL first, because the
  per-workspace URL stops working when its row is deleted.

Save the output of the first query (it is the only record of the rows you are
about to delete besides a backup from step 6), then run:

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

No other table points at these rows, so nothing else needs repair. Then wait
about a minute for the "any active connection" cache to expire (or restart PHP
workers), and re-run step 3 in reverse: the Payments pages say
"Using the deployment's ... account" again, and the legacy webhook URLs verify
with `STRIPE_WEBHOOK_SECRET` / `SQUARE_WEBHOOK_SIGNATURE_KEY` for every workspace
as before. Workspaces that entered their own connections in the meantime keep
them.

Re-running the migration later is safe after a full revert.

### What cannot be undone

Reverting changes who serves payments from now on. It does not reach back:

- **Anything already charged.** A payment taken while a connection served the
  workspace stays taken. A charge, a refund or a dispute is a fact at Stripe or
  Square; reverting does not move money. Refund through the processor if needed.
- **Anything already sent.** Emails, payment reminders and receipts that went out
  while the migration was in effect cannot be recalled.
- **Webhook events** the processor delivered and SVC applied stay applied. While
  the migration was in effect the legacy webhook URLs were confined to the target
  workspace, so an event for any other workspace was not applied; a revert does
  not replay it. Resend it from the processor's dashboard if it matters.
- **The deleted rows' contents.** A revert deletes the connection rows, including
  their per-workspace webhook tokens. Only the saved query output or a backup
  holds them.
- **Time.** Workspaces other than the owner had no payment connection from the
  moment `--apply` finished until you reverted or they connected their own.
  Payments attempted in that window were refused and are not retried.
