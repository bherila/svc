# Security Policy

## Reporting a vulnerability

Please report security vulnerabilities through GitHub's private vulnerability
reporting for this repository. Do not open a public issue containing secrets,
personal data, client records, billing records, or exploit details.

## Data safety

This public repository must contain only source code, documentation, and
synthetic test data. Never commit production databases, exports, uploaded
documents, client records, invoices, payment processor records, credentials, or
other personal or financial data.

GitHub secret scanning and push protection are enabled. CI also runs the local
disclosure guard in `scripts/scan-sensitive.mjs`. Install the repository's
pre-commit hook after cloning with:

```sh
pnpm run hooks:install
```

The guard is intentionally conservative. If a legitimate source artifact is
blocked, prefer generating it during tests. Any exception should receive a
security review before the guard is changed.

## Security assumption: the identity provider verifies email changes

SVC has no email verification of its own. Every sign-in stamps `email_verified_at` and
refreshes the account's address from the email the identity provider asserts (the
`bherila/auth-laravel` account defaults, configured through `App\Auth\OAuthAccounts`).
Accounts resolve on the provider subject, never on email, but several grants bind to
whichever account currently holds an address:

- portal invitations applied at sign-in (`PortalInvitations::applyAtSignIn`);
- shared-project invitation acceptance (`SharedProjectParticipations`);
- approval rights of a client's billing contact (`ApprovalPermission::isBillingContact`);
- reviewer grants (`ReviewerGrants`);
- adoption of an existing account when a package request is approved
  (`PackageRequestWorkflow`).

This is safe only because the identity provider confirms mailbox control before an
email change takes effect, as the owner has confirmed it does. A provider (or a second
provider added later) that lets a person set or change an address without proving they
control it would let them claim every grant above for that address.

### When the provider sends `email_verified`

If the provider's identity (userinfo) response carries an `email_verified` claim, SVC
honours it (`App\Auth\ProviderEmailVerification`, checked by `App\Auth\OAuthAccounts`):

- `true`, or the string `"true"` in any case, signs in as before;
- any other value - `false`, `"false"`, `"0"`, `0`, `""`, `null` or anything unrecognised -
  refuses the sign-in with a 403 that tells the person to verify the address with the
  provider. The refusal happens before any write: no account is created, no subject is
  linked, and an existing account's name, address and `email_verified_at` are left as they
  were. No session is started, and the refusal is logged and audited as a failed sign-in.

If the claim is **absent**, SVC keeps the behaviour above and trusts the provider's
address. That is the case today: the provider SVC uses (`bherila/auth-manager`) sends
`sub`, `name`, `email`, `credential_version`, `apps` and the authentication context, but
no `email_verified`, so this assumption still carries the grants listed above. Sending the
claim from the provider would let SVC enforce it with no change here.

Before trusting a provider that does not verify address changes, SVC must go further:
require the claim (refuse or leave unverified any sign-in without it) and bind these
grants only to verified addresses.

## Security assumption: only `main` deploys, because the repository settings say so

`tests.yml` (production, Environment `web1`), `deployment-diagnostic.yml` (`web1`),
`pilot-deploy.yml` (`pilot`) and `staging-deploy.yml` (`staging`) each run their credentialed
job only for `refs/heads/main`, and `WorkflowCredentialCensusTest` keeps every credentialed job
that way. But workflow files belong to the branch: anyone who can push a branch can edit that
branch's copy, and no test can see it. The control is a repository setting: each of those
Environments' **Deployment branches** must be restricted to `main`, and pushes to `main` to its
maintainers.

`staging-deploy.yml` may deploy an earlier main commit than the one dispatched. The gate
(`scripts/deploy/staging/gate.mjs`) runs from the dispatched main head, before any secret is
exposed, and accepts the commit only if the GitHub compare API reports it as an ancestor of
`main` and the Actions API reports a successful `ci` job in a main push run of `tests.yml` for
it. Both answers are trusted as GitHub gives them to the job's own read-only token.

## Security assumption: unthrottled routes are bounded elsewhere

`RouteThrottleCensusTest` allows a write route with no application throttle only with a
reason. The ones that rest on something outside SVC:

- **Processor webhooks** (`api/webhooks/stripe*`, `api/webhooks/square*`) are never throttled,
  because the processor retries and a 429 would delay or drop a genuine event. An unsigned
  request costs a connection lookup and a signature check before it is refused, so a flood of
  them is bounded only by the web server and the CDN in front of it.
- **Passport's** `oauth/token` (a bare `throttle`, 60 a minute per address),
  `oauth/token/refresh` and `oauth/authorize` approve and deny, and **bherila/auth-laravel's**
  credential and delegated-access routes, keep the middleware their packages give them. SVC adds
  `throttle:credentials` to the package's credential group through
  `bherila-auth.oauth_server.credentials.middleware`.

Guest buckets (`sign-in`, `public-packages`, `oauth-register`, Passport's token endpoint) key on
the client address that `bherila-auth.trusted_proxies` resolves: `X-Forwarded-For` is honoured
only from the configured proxies (Cloudflare's published ranges by default, checked by
`cloudflare-ranges.yml`). A proxy in front of SVC that is not in that list makes every guest
behind it one bucket.

## Security assumption: signed URLs are bearer tokens and `APP_KEY` stays secret

`signed:relative` routes, such as an expense receipt's content, accept any URL signed with
`APP_KEY` until it expires; nothing else checks who presents it. The `local` disk's root
(`storage/app/private`) holds the OAuth signing keys, so it is not served (`serve => false`):
nothing issues temporary URLs for it, and serving it would register signed `GET` and `PUT
storage/{path}` routes over that root.

## Trust assumption: every stored instant is UTC, in the app and in the database session

The app writes UTC (`config/app.php` sets `timezone` to `UTC`, and `WorkspaceClock::instant()`
is UTC). MariaDB converts each `TIMESTAMP` through the session's `time_zone`, so the MySQL and
MariaDB connections pin the session to `+00:00` (`config/database.php`) instead of taking the
server's default, which on web1 is `SYSTEM`, the OS clock in Europe/London. Before the pin, a value
written in British summer time was stored an hour early: the app read back what it wrote, while
`mysqldump`, `UNIX_TIMESTAMP()` and a restore onto another server saw the hour.
`2026_11_23_090000_reexpress_timestamps_for_a_utc_session` moved the stored values onto the UTC
session, re-expressed from the server's default zone (`App\Support\Database\UtcTimestampSession`).

Nothing checks this at runtime. A new connection must set the same `timezone`, and SQL must not
mix `NOW()` or `CURRENT_TIMESTAMP` with workspace-local times. Migrating with a cached
configuration that predates the pin would read the old zone; the migration refuses a session that
is not UTC rather than convert twice.
