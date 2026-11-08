# Runbook: the isolated staging environment

Staging is a second SVC deployment that shares nothing with production. It exists to
exercise what a laptop cannot: Stripe delivering real webhooks over HTTPS, the scheduler,
the browser Payment Element and the report-only CSP, against synthetic data and Stripe
TEST mode.

**Status: prepared, not provisioned.** The host, database and subdomain are not chosen.
`.github/workflows/staging-deploy.yml` deploys nothing until the owner creates the GitHub
Environment `staging` and supplies every value in [What the owner provides](#what-the-owner-provides).
Until then a run fails at its first step and lists what is missing. Claude and CI never deploy
staging, call real Stripe or touch production.

## Isolation rules

| Concern | Staging value | Never |
| --- | --- | --- |
| Host account / directory | Its own deploy directory (`STAGING_DEPLOY_DIR`, not `svc-laravel`) and env file (`STAGING_ENV_SOURCE`, not `.config/svc/deployment.env`) | Production's account or env file |
| `APP_KEY` | Generated for staging only: `php artisan key:generate --show` | Production's key (it would decrypt production-encrypted values and share sessions) |
| Database | Its own MariaDB schema, user and password. Disposable; migrations and seeders may be re-run or the schema dropped | A production schema, a copy or restore of production data |
| Storage | The deploy directory's own `storage/` (`svc-blobs` disk, `SVC_FILESYSTEM_ROOT` unset). If S3 is ever used, a separate bucket (`AWS_BUCKET`) with its own IAM user | Production's bucket or path prefix |
| Data | Synthetic only (see [Seed data](#seed-data)) | Exports, uploads, invoices, processor records, real client or billing fixtures |
| Stripe | A TEST-mode key pair and a staging webhook endpoint | Any live key. The preflight refuses a key that is not `sk_test_`/`rk_test_` |
| Mail | A sink (see [Mail](#mail)) | Brevo or any real SMTP |
| OAuth | A separate client at the identity provider with the staging callback | Production's client id or secret |
| GitHub | Only `vars.STAGING_*` and `secrets.STAGING_*` of the environment `staging` | Production or pilot secrets/variables (a unit test pins this) |

## What the owner provides

Create the GitHub Environment **staging** (Settings, Environments). Recommended: required
reviewers, and a deployment-branch rule limiting it to `main` and `staging` plus the branches
you intend to deploy. The workflow reads the environment's values as follows; the preflight
prints any that are missing or unsafe (names and the rule only, never values).

Variables (`vars.`):

| Name | Meaning |
| --- | --- |
| `STAGING_DEPLOY_ENABLED` | Must be exactly `true`. The owner switch; set to `false` to pause. |
| `STAGING_DEPLOY_DIR` | Directory under the SSH user's home, e.g. `svc-staging` |
| `STAGING_ENV_SOURCE` | Private env file on the host, `.config/<name>/deployment.env` |
| `STAGING_APP_URL` | `https://<staging-host>` (https origin, no path) |
| `STAGING_DB_HOST`, `STAGING_DB_NAME`, `STAGING_DB_USER` | Staging database |
| `STAGING_MAIL_MAILER` | `log` or `smtp`; with `smtp` also `STAGING_MAIL_HOST` and `STAGING_MAIL_PORT` |
| `STAGING_STRIPE_PUBLISHABLE_KEY` | Stripe TEST publishable key, `pk_test_...` |
| `STAGING_OAUTH_PROVIDER_URL`, `STAGING_OAUTH_CLIENT_ID` | Identity provider and the staging client |
| `STAGING_OAUTH_REDIRECT_URI` | Exactly `STAGING_APP_URL` + `/oauth/callback` |

Secrets (`secrets.`):

| Name | Meaning |
| --- | --- |
| `STAGING_SSH_HOST`, `STAGING_SSH_USERNAME`, `STAGING_SSH_PRIVATE_KEY`, `STAGING_SSH_KNOWN_HOSTS` | Deployment access to the staging host only |
| `STAGING_APP_KEY` | `base64:...` key for staging only |
| `STAGING_DB_PASSWORD` | Staging database password |
| `STAGING_STRIPE_SECRET_KEY` | Stripe TEST secret key, `sk_test_...` |
| `STAGING_STRIPE_WEBHOOK_SECRET` | Signing secret (`whsec_...`) of the staging webhook endpoint |
| `STAGING_OAUTH_CLIENT_SECRET` | Staging OAuth client secret |

Host preparation (once, by the owner): the SSH user's home holds an empty private
`STAGING_ENV_SOURCE` file (mode 0600), as the pilot and production deployments expect. The
workflow writes the values above into it through the deployment action's `env-values` input.
**Confirm that input's behaviour against the pinned action's README on the first run**
(`bherila/shared-cpanel-deployment` v2.6.0); the pilot uses it only for `DB_URL=`/`DB_SOCKET=`.

### What the workflow does

`workflow_dispatch` with a `ref` (branch, tag, full SHA, or `refs/pull/<n>/head`), or a push to
a `staging` branch (create it only when the owner is ready). In order: check out the ref; run
the preflight; require that exact commit's `ci` aggregate job to have succeeded in a
`tests.yml` run of this repository (a pull request head counts; a fork's run does not); build;
deploy atomically with a retained rollback release. It does not run any production data command
and has no step that reads another environment.

Secrets reach only the preflight and deploy steps, never the Composer/pnpm build. The deployed
ref's own scripts run in the workflow, so restrict who can start it with the environment's
reviewers and branch rules.

The production hook scripts under `scripts/deploy/` are hard-wired to `svc-laravel` and
`svc.bherila.net`, and the pilot's to the pilot; staging uses none of them and relies on the
action's own operational and runtime audits. Staging-specific quiesce and live-verification
hooks are a follow-up.

### Scheduler: enabled on purpose

`install-cron: 'true'`. Staging is where the scheduler is validated, so the action installs the
single `schedule:run` line (as on production). With synthetic data and TEST keys this is safe:
payment reminders, autopay, the daily webhook health check and the Square jobs (inactive without
Square credentials) all run for real against Stripe test mode. Opt-in jobs stay off unless you
set them: `SVC_PROCESSOR_MOVEMENTS_SCHEDULED_APPLY`, `SVC_BILLING_SCHEDULED_CADENCE_DRAFTS`.
There is no queue worker; none is needed.

## Seed data

SVC binds an account to its identity-provider subject at first sign-in and never adopts an
existing unbound account by email, so the seeder creates no users. In this order:

1. Pick two dedicated test identities at the identity provider (never a real client's): an owner
   and a portal contact. Set `SVC_STAGING_SEED_OWNER_EMAIL` / `SVC_STAGING_SEED_PORTAL_EMAIL` to
   their addresses (defaults `staging-owner@example.test` / `staging-portal@example.test`).
2. Sign in to staging once as each of them. SVC creates each account bound to its identity.
3. On the host, from the deploy directory, run:

```bash
php artisan db:seed --class='Database\Seeders\StagingSeeder' --force
```

`StagingSeeder` then creates one workspace (`staging-synthetic`) owned by the first identity, one
client company with the second as its portal contact, and four issued invoices (two of $250, two
of $125, under the online-payment cap), all with invented names. It refuses, changing nothing,
until both identities have signed in; it refuses to run when `APP_ENV=production`, is
idempotent, and reads no other database.

Autopay needs an invoice due today: create one in the app, with a due date of today, for the
portal company.

## Stripe (TEST mode)

1. In the Stripe dashboard switch to **test mode** (or use a separate Stripe account for staging).
   Copy the test publishable and secret keys into `STAGING_STRIPE_PUBLISHABLE_KEY` and
   `STAGING_STRIPE_SECRET_KEY`.
2. Create the webhook endpoint (Developers, Webhooks, Add endpoint). The URL shape comes from
   `App\Support\Payments\WebhookUrl` and `routes/billing.php`; it must be **https**:
   - **Legacy environment endpoint (use this first):** `https://<staging-host>/api/webhooks/stripe`.
     Served from `STRIPE_*` in the env file until `svc:payments:migrate-environment --apply` has
     run, verified with `STAGING_STRIPE_WEBHOOK_SECRET`.
   - **Per-workspace endpoint:** `https://<staging-host>/api/webhooks/stripe/<40-char token>`.
     You do not create this by hand: saving the TEST secret key on the workspace's Payments page
     (`/workspaces/{workspace}/payments`) makes SVC register the endpoint at Stripe itself, with
     every event it needs, and store that endpoint's signing secret. After the environment
     connection is migrated, the legacy URL is verified with the migrated connection's secret.
   Subscribe the endpoint to every type in `StripeWebhookService::EVENTS` (the workspace
   connection does this automatically): `payment_intent.succeeded/processing/payment_failed/canceled`,
   `charge.refunded`, `refund.updated`, `refund.failed`, `charge.dispute.created/updated/closed/funds_withdrawn/funds_reinstated`,
   `payout.paid/failed/canceled`, `payment_method.attached/detached`, `setup_intent.succeeded`,
   `customer.updated`.
3. Reveal the endpoint's **signing secret** and save it as `STAGING_STRIPE_WEBHOOK_SECRET`.
4. Redeploy (or re-dispatch) so the env file carries the keys.

Webhooks need a publicly reachable HTTPS URL with a valid certificate, and the Payment Element
needs HTTPS too. An invalid signature is a 400 and an unknown token a 404; nothing is applied.

## OAuth

Register a **separate staging client** at the identity provider (`OAUTH_PROVIDER_URL`). Its
callback is `https://<staging-host>/oauth/callback` (`routes/web.php`, `oauth.callback`), identical
to `STAGING_OAUTH_REDIRECT_URI` (the preflight enforces this). Put the client id and secret in
`STAGING_OAUTH_CLIENT_ID` / `STAGING_OAUTH_CLIENT_SECRET`. The form-post sign-out redirect uses the
provider's origin, already in the CSP.

## Mail

**Recommended: `MAIL_MAILER=log`.** Every message is written to `storage/logs/laravel.log` on the
host; nothing can reach a real person and no extra service is needed on shared hosting. Read
reminders and invoice emails there (`tail -f storage/logs/*.log`).

If the host can run it, a sink with a UI is nicer: **Mailpit** (`STAGING_MAIL_MAILER=smtp`,
`STAGING_MAIL_HOST=127.0.0.1`, `STAGING_MAIL_PORT=1025`), with its web UI reachable only through
the same access control as the site. Never point staging at Brevo or a real SMTP relay; leave
`BREVO_WEBHOOK_TOKEN` unset (the Brevo webhook refuses everything without it).

## Access control that still lets Stripe in

Staging should not be public, but Stripe must reach the webhook route and the identity provider
redirects the browser (so `/oauth/callback` needs no exemption). SVC has no basic-auth middleware:
adding one is not trivial to do safely (it takes the `Authorization` header that bearer-token
routes also use), so use the web server. On cPanel/LiteSpeed or Apache, in the staging document
root's `.htaccess`, before the Laravel rules:

```apache
AuthType Basic
AuthName "SVC staging"
AuthUserFile /home/<user>/.htpasswds/svc-staging
<RequireAny>
    # Stripe: the legacy and per-workspace webhook routes only. They are authenticated by signature.
    Require expr %{REQUEST_URI} =~ m#^/api/webhooks/stripe(/[A-Za-z0-9]{40})?$#
    Require valid-user
</RequireAny>
```

Create the password file with `htpasswd -c`. Alternatively allow Stripe's published webhook IP
ranges plus your own IP and deny the rest. Verify from outside: the home page prompts for a
password, `curl -i -X POST https://<staging-host>/api/webhooks/stripe` returns **400** (bad
signature), not 401. Basic auth also protects the portal, so testers need those credentials in
addition to their identity login (and the smoke script below needs them via
Playwright's `httpCredentials`).

## Local alternative before staging exists

Exercise real HTTP webhook delivery on a laptop with the Stripe CLI; no public URL is needed.

```bash
# .env (local disposable database; TEST keys only)
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
php artisan serve              # http://localhost:8000

stripe login
stripe listen --forward-to localhost:8000/api/webhooks/stripe
# prints:  Ready! Your webhook signing secret is whsec_... (also: stripe listen --print-secret)
```

Put that `whsec_...` in `.env` as `STRIPE_WEBHOOK_SECRET` and restart `php artisan serve` (or
`php artisan config:clear`). Local webhooks use the **legacy** route `/api/webhooks/stripe` and the
environment secret, which serve while `svc:payments:migrate-environment --apply` has not run on that
database. Do not connect a Stripe key on the Payments page locally: SVC would ask Stripe to register
`http://localhost...`, which Stripe cannot reach. Confirm with `php artisan svc:stripe:status`.

```bash
stripe trigger payment_intent.succeeded   # plumbing: signed, delivered, recorded (matches no SVC invoice)
stripe trigger setup_intent.succeeded
stripe events resend evt_...              # replay one event: SVC answers duplicate=true
```

`stripe trigger` creates synthetic Stripe objects that belong to no SVC invoice, so it proves
signature checking and delivery, not invoice settlement. For settlement, pay a seeded invoice in the
portal with test card 4242 4242 4242 4242 while `stripe listen` runs and watch `listen` print
`200` for each event. A 400 means the signing secret is stale; a 404 means the wrong path.

## Staging acceptance checklist

Run in order against staging with the seeded data; tick each with who, when, and the Stripe event
ids. Test cards: success `4242 4242 4242 4242`; 3DS `4000 0027 6000 3184`; decline
`4000 0000 0000 0002`; dispute `4000 0000 0000 0259`; any future expiry, any CVC and postal code.

- [ ] **Deploy and schema.** The workflow succeeded for an exact CI-green commit; `php artisan about` on the host shows `staging`, the staging database and `log`/sink mail.
- [ ] **Isolation.** `php artisan svc:stripe:status` reports mode `test`; the Stripe account is the test account; the OAuth client and bucket (if any) are staging's.
- [ ] **Portal card payment.** As the portal contact, open an issued invoice, **Pay by card**, enter 4242 in the Payment Element, **Pay now**. The page says "Payment submitted", then the invoice shows Paid once `payment_intent.succeeded` is delivered (Stripe dashboard shows a 200 delivery). `scripts/staging/portal-card-smoke.mjs` automates this.
- [ ] **3DS.** Pay another invoice with the 3DS card; complete the challenge; it returns to the invoice and turns Paid.
- [ ] **Decline.** Pay with the decline card; the panel shows the failure, the invoice stays unpaid, and the invoice detail records the failed attempt.
- [ ] **Setup intent, default, autopay.** In `/portal/{company}/billing` save a card (4242), confirm it becomes the default, authorize autopay with a limit, then create an invoice due today and run `php artisan svc:billing:charge-autopay` (preview), then let the scheduler (or `--apply`) charge it. It charges once; a second run charges nothing.
- [ ] **Refund.** Refund a paid payment from the invoice detail (or `svc:billing:refund-payment` with a preview first). The `refund.updated` webhook converges and the ledger shows the refund.
- [ ] **Dispute.** Pay an invoice with the dispute card; `charge.dispute.created` arrives and the invoice/payment shows the dispute. Close it from the Stripe test dashboard and see `charge.dispute.closed` applied.
- [ ] **Webhook health.** `/workspaces/{workspace}/payments` shows "Webhook: all N events subscribed" (green). Remove one event in the Stripe dashboard to see it turn unhealthy, then fix it with the page's button.
- [ ] **Scheduler.** On the host `php artisan schedule:list` shows the jobs and `crontab -l` holds exactly one `schedule:run` line. Evidence that they ran: reminders (`svc:billing:send-payment-reminders`) appear in the mail sink/log, autopay (`svc:billing:charge-autopay`) charged the due invoice, and the daily `svc:payments:check-stripe-webhooks` (03:35) updated the page's "checked" time (or run it with `--apply` once).
- [ ] **CSP, report-only then enforce.** With `SVC_CSP_MODE=report-only` (the default), run every flow above in a fresh browser profile and read the application log for `csp.violation` lines (SVC's own collector at `POST /api/csp-reports`; the browser console also shows "Content-Security-Policy-Report-Only" messages). The pass condition is zero violations, including a card payment and a saved-card setup through the Payment Element. Only then set `SVC_CSP_MODE=enforce` on staging, repeat the card payment and setup, and confirm they still work with no `csp.violation` lines. Record any that appear; do not enforce in production before staging passes this.
- [ ] **Migration preview.** On staging data run `php artisan svc:payments:migrate-environment` (no `--apply`) and read the inventory against [the migration runbook](payment-connections-migration.md). Optionally `--apply` on staging to rehearse it, then re-check the webhook health page and a payment on both webhook URLs.
- [ ] **Sign-off.** Nothing real was used: no live key, no real client, no production data. Note the commit SHA tested.

## Browser smoke script

`scripts/staging/portal-card-smoke.mjs` drives the portal Payment Element with the test card
through to a paid invoice. It is not run by CI and has not been run against a real staging site
(none exists). It skips cleanly without `STAGING_URL`. Identity-provider sign-in cannot be
scripted generically, so it takes a Playwright storage-state file for the synthetic portal user
(`STAGING_STORAGE_STATE`) captured once by hand, plus `STAGING_PORTAL_INVOICE_PATH`. Stripe's
iframe field selectors (`input[name=number|expiry|cvc|postalCode]`) are the part most likely to
need adjusting on the first real run. If staging sits behind basic auth, add `httpCredentials` to
its `newContext`.
