# Provider identity enforcement

SVC signs people in only through the identity provider (`id.bherila.net`). With provider
identity enforcement on, a credential whose provider identity has since been disabled, deleted,
reset or ungranted stops working here on its next request: the browser session is signed out,
and OAuth and API tokens are refused. Enforcement is on when
`BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true` or a cutover has been recorded; once recorded it
stays on whatever the setting says, and every deploy checks it
(`scripts/deploy/post-deploy.sh`). Turning it on and recording the cutover are in
[MCP operations](mcp-operations.md#per-endpoint-resources-and-provider-identity-cutover). The
mechanism is auth-laravel's
[provider session verification](https://github.com/bherila/auth-laravel/blob/main/docs/provider-session-verification.md)
and [cutover](https://github.com/bherila/auth-laravel/blob/main/docs/provider-identity-cutover.md).

This page lists every way a request here is authenticated or authorized as a person or
principal, and what enforcement does to each.

## How a check works

- **One observation per person.** Every credential of a person is checked against one shared
  status observation, fresh for under five minutes, in a lock-capable cache store shared by
  every web worker. An unsafe method (website, REST or Sanctum) and every MCP write tool call
  ask the provider freshly.
- **Ended identity.** A browser session is logged out, invalidated and given a new CSRF token,
  then redirected to `/login` or answered with JSON 401. A bearer token is refused with 401, and
  a refresh gets `invalid_grant`.
- **Provider unavailable.** A retryable 503 with `Retry-After`. The session and the token are
  kept, and a refresh token is not used up.
- **Unbound accounts are never checked.** An account with no `oauth_provider`/`oauth_subject`
  has no provider identity. SVC creates exactly one: the deploy smoke principal
  (`DeploySmokeCredentialsCommand::principal()`), which holds no membership and cannot sign
  in. Every other account is created bound, by sign-in or by delegated provisioning. A binding
  under another issuer, or with an empty subject, is broken: it signs the session out and
  refuses the token. It is never treated as unbound (`ColumnProviderBindingResolver`).
- **Name and email** are refreshed only at sign-in. A status check never changes them.

## Credential inventory

"Covered" means enforcement ends the credential when the identity ends. "Not applicable" means
it is not a person's credential, or the person it names is not a provider identity. **Gap**
marks a path that acts as a person and is not checked against the provider; see
[Gaps](#gaps).

### Browser sessions

| Path | Who it represents | Provider-bound? | Treatment |
|---|---|---|---|
| Sign-in (`/login`, `/oauth/redirect`, `/oauth/callback`, `OAuthLoginController` over the package's `RelyingPartyLoginController`) | A staff member or a client contact | Yes, by subject | **Covered.** `ProviderSession::establish()` records the login generation, also while enforcement is off. Under enforcement, a login that cannot be verified is undone and answered 503 |
| Every `web` route: workspace screens, client screens, the client portal (`/portal/...`), approvals, reviewer grants, shared-project invitations, account and MCP-credential settings, payment-key screens, file and PDF downloads, exports, search and the palette | The signed-in account | Yes (smoke principal excepted, which cannot sign in) | **Covered.** `RequireActiveProviderSession` is appended to the whole `web` group (`bootstrap/app.php:68`), so it runs on every route file `routes/web.php` loads, whether or not the route has `auth`. `ProviderSessionRouteCensusTest` fails a session route registered anywhere else |
| Client portal writes: proposal acceptance, approve or dispute, autopay on or off, saving a card (Stripe SetupIntent), service enrolment, cancellation, invoice payment (`stripe-payment-intent`) | A client contact, as the session's account | Yes | **Covered**, freshly on each of these, since all are unsafe methods. Portal users sign in through the provider like staff; there is no separate portal login |
| Website session into `/api/v1` (`AuthenticateFirstPartySession`) | The signed-in account, as a synthetic `svc-web` token with REST scopes | Yes | **Covered.** It runs the same check itself, in JSON (`AuthenticateFirstPartySession::refuseEndedProviderSession`), freshly on unsafe methods. It is not admitted to the finance, credential-disposal or MCP routes |
| Sign-out (`POST /logout`) | The session being ended | — | Never checked (`provider_identity.except_routes`), so a person can sign out during a provider outage |
| Remember-me cookie | — | — | **None issued.** The login controller keeps the package default `$remember = false`, and SVC has no other way to sign in |
| Local password, password reset, passkeys, two-factor | — | — | **None exist.** The package's own routes are off (`bherila-auth.routes.enabled = false`), and no route accepts a password. The `users.password` column only holds an unusable hash |

### OAuth and API tokens

| Path | Who it represents | Provider-bound? | Treatment |
|---|---|---|---|
| Authorization code (`/oauth/authorize`, consent in the `web` group; first-party clients skip consent but still need the session) | The consenting account | Yes | **Covered.** The code is stamped with the session's provider subject and login generation. A bound account without a verified session is issued nothing |
| OAuth access tokens: MCP connections (`/api/v1/mcp`) and REST apps (`/api/v1`) | The person who authorized the client, as `AgentPrincipal` (the `api` guard, same `users` row) | Yes | **Covered** by `ProviderIdentityTokens`. A token exchanged from a code inherits the code's stamp. Every bearer request checks the stamp against the binding and the shared observation (the package's `ResourceAccessTokenRepository::isAccessTokenRevoked`). A REST write checks freshly (`EnsureOperationDeployed:44`, `ProviderIdentityWrites`), and so does an MCP write tool call, once per call (`McpRateLimitedCallToolHandler::refuseAnEndedIdentity`). Unstamped tokens are refused |
| Refresh tokens | The same person | Yes | **Covered.** A refresh checks freshly. An ended identity gets `invalid_grant`, and during an outage the refresh token is kept |
| Personal API tokens from the setup page (`account/api-credentials`, Passport personal access client) | The signed-in account | Yes | **Covered.** Stamped from the issuing session, then treated as an access token |
| Operator-issued agent tokens (`svc:auth:issue-agent-token`, through `AgentTokenIssuer`) | The named person | Yes | **Covered.** Issuing is refused if the provider reports the identity inactive, or cannot answer under enforcement. The token carries the provider's current generation and is then checked like any access token |
| Deploy and CI smoke tokens (`svc:mcp:deploy-smoke-credentials`, `scripts/mcp-smoke-credentials.php`) | The smoke principal | No | **Not applicable**: unbound, no membership, revoked after each run, and a short expiry |
| Sanctum finance tokens (`svc:auth:issue-token`, on the finance reconciliation routes under `auth:sanctum,api`) | The named owner or admin | Yes | **Covered** by `PersonalAccessTokenIdentity`, through `Sanctum::authenticateAccessTokensUsing` (`AppServiceProvider.php:185`). Stamped at issue from the provider's current generation, and refused at issue for an inactive identity. Every use is checked, freshly on unsafe methods. Unstamped tokens of bound accounts are refused |
| Client-credentials grant | — | — | **Refused** (`RefuseClientCredentialsGrant` in `config/passport.php`): no secret alone mints a token. Passport's transient-token cookie route is refused under the agent profile |
| Token self-revocation (`oauth.token.revoke`, `connections.revoke`) | The token being disposed of | Yes | Goes through `auth:api`, so an ended identity's token is already refused. Nothing is lost: that token no longer works |
| Signed download and upload URLs (`attachments.*`, `expenses.receipts.content`, `invoices.download_pdf`) | — | — | **Not a credential on its own.** Each one sits inside the REST group (`routes/api.php:104`), so `auth:api` or the website session still applies, and the signature only adds an expiry. See [Unsure](#unsure) |

### Other parties, machines and the operator

| Path | Who it represents | Provider-bound? | Treatment |
|---|---|---|---|
| Delegated access (`POST /application-access`, `DelegatedStaffAccess`) | The provider, acting for one of its signed-in people who owns or administers a workspace | The actor is resolved through its binding | **Not covered by the middleware**, by design: no session exists. Each request carries a provider-signed, single-use actor assertion, so the provider itself decides that the actor is live. Answers 404 until `DELEGATED_ACCESS_ENABLED` |
| Portal invitation link (emailed by the provider) | Nobody here: the link is the provider's admission to this application | — | **Not applicable.** SVC keeps the link encrypted only while the invitation is pending. Access is granted at sign-in, to the account that proved the address (`PortalInvitations::applyAtSignIn`), so it follows the sign-in check |
| Emails SVC sends: invoices, payment reminders, the autopay notice, service-package notices, the administrator's issued-invoice notice | Nobody | — | **Not a credential.** Every link opens a sign-in-gated page (`portal.invoice`, `portal.billing`, `clients.invoice`, `portal.services.show`), or Square's own pay page. No emailed link carries a token |
| Public service-package pages and request form (`/s/{token}`, `/s/{token}/{linkName}/requests`) | Anyone with the workspace's catalog link | No | **Not applicable**: anonymous, and the request form acts as nobody. Accepting a request later grants portal access to the bound account holding the address (`PackageRequestWorkflow::accountFor`), which must then sign in |
| Stripe webhooks (`/api/webhooks/stripe`, per-workspace `/{endpoint}`), Square webhooks (`/api/webhooks/square`, `/{endpoint}`) | The processor | No | **Not applicable**: signature-verified, no session. A payment they settle may approve time "by payment", which names no person (`ClientApprovalByPayment`) |
| Brevo webhook (`/api/webhooks/brevo`) | The mail provider | No | **Not applicable**: shared token |
| Workspace payment connections (stored Stripe and Square keys) | The workspace, at its processor | No | **Not applicable**: a machine credential entered on a covered session page |
| CSP reports, OAuth discovery, dynamic client registration, `/api/openapi.json`, `/up` | Anyone | No | **Not applicable**: anonymous |
| Queued jobs | — | — | **None exist.** Nothing implements `ShouldQueue`, and no queue worker runs (see `AGENTS.md`) |
| Scheduled commands, run from `routes/console.php` | The workspace, carrying out a decision a person made earlier | — | **Not re-checked: standing decisions.** See gap 2. These are `charge-autopay` (the client's recorded consent), `dispatch-invoice-emails` (automatic delivery chosen at issue), `send-payment-reminders`, `generate-cadence-drafts`, and the service-package and agreement-termination jobs. The rest (Square sync, processor movements, pruning, `passport:purge`) act for no one |
| Artisan commands that act as a named person (`--actor=` or `--user=`: `svc:tasks*`, `svc:collaboration`, `svc:attachments*`, `svc:clients:contacts`, `svc:square:covered-payments`, `svc:finance:*`, `svc:billing:deferred-buydown`, `svc:engagement:log-time`) | The operator, acting as the named account | The named account usually is | **Gap 1.** The account is resolved by public id (`FindsUserByPublicId`) and authorized on its current memberships. Its provider identity is never consulted |
| Other artisan commands (issue and revoke credentials, billing, Square, audits) | The operator with shell access | n/a | **Not applicable**: not a web credential. Issuing a credential checks the named person's identity (above) |

## What enforcement does not do

- **It does not remove anyone's access here.** When the provider ends an identity, the account
  keeps its workspace, portal, project, reviewer and billing-contact rows. Everything that reads
  those rows instead of a credential carries on. Invoice recipient suggestions
  (`InvoiceEmailService::recipientSuggestions`), the responsible administrator's issued-invoice
  notice, reminders and package notices still go to the address. The person just cannot sign in
  or use a token to follow the link. To offboard someone from SVC, remove their access here too.
- **It does not re-check grants made by email.** These follow the account that holds an address
  at sign-in. That is safe because sign-in is checked, and because the provider verifies email
  changes ([SECURITY.md](../SECURITY.md#security-assumption-the-identity-provider-verifies-email-changes)).
- **It never makes an unbound account bound.** Clearing `oauth_subject` by hand would exempt that
  account's sessions and Sanctum tokens from every check (`PersonalAccessTokenIdentity::accepts`
  accepts an unbound owner's token). No code path does this. Do not do it to end someone's
  access: disable them at the provider.

## Gaps

They are ranked by risk. Gap 2 is the owner's decision. Gap 3 is closed, as its entry says.

1. **Operator commands that act as a named person** (low). An operator can still act as someone
   whose identity has ended: logging time, reconciling payments, editing tasks, deleting a
   receipt. The record is then attributed to that person. The operator is trusted with the shell
   anyway, so the harm is a misattributed audit trail rather than an escalation. *Suggested fix:*
   when enforcement is on, have `FindsUserByPublicId` (and `LogTimeEntryCommand::resolveWorker`)
   refuse a bound account whose identity has ended, using
   `ProviderIdentityPolicy::verify(..., fresh: true)`. An outage is then a refusal, not a pass.
2. **Scheduled work on a decision made by someone whose identity has since ended** (low; owner
   decision). Autopay keeps charging the client's saved card on the consent a client contact gave
   in the portal, or a workspace manager recorded with the client's attestation
   (`client_autopay_consents.actor_user_id`). Automatic invoice delivery,
   reminders, scheduled package invoices and scheduled agreement terminations also carry on after
   the person who set them up has gone. These are the business's or the client's standing
   decisions rather than a person's session, so carrying on is arguably correct: a card
   authorization does not lapse when the employee who gave it leaves. *Suggested fix:* confirm
   this is accepted. If it is not, have `AutopayChargeService` hold a charge for review when the
   consent's actor is a client contact whose provider identity has ended.
3. **No census held the session check to the `web` group** (hardening; closed).
   `tests/Feature/Auth/ProviderSessionRouteCensusTest.php` now fails any route that starts or
   reads a session, or authenticates a session guard (`auth`, `auth:web`, `auth.basic`, or
   `auth:sanctum` with a session started), without `RequireActiveProviderSession`, which only
   the `web` group carries. `AuthenticateFirstPartySession` stands in for it only on a route
   where it is the one use of a session: no session middleware of its own and no session
   guard, so a group that hands the website's session to `/api/v1` any other way fails too.
   Its allowlist is empty, and an entry needs a reason.

## Unsure

- **Signed URLs.** [SECURITY.md](../SECURITY.md#security-assumption-signed-urls-are-bearer-tokens-and-app_key-stays-secret)
  treats a `signed:relative` URL as a bearer token that nothing else checks. Every signed REST
  route today is stricter than that: the `v1` group opened at `routes/api.php:102` closes after
  line 271, so `AuthenticateFirstPartySession` and `auth:api` run before `signed:relative`, and
  the controllers also require an authenticated principal (`AgentExpenseReceiptController::actor`,
  `AgentInvoiceOperationsController::principal`). A signed URL is therefore useless without a
  covered bearer or session, as [the MCP product contract](mcp-product-contract.md) says.
  SECURITY.md's assumption remains the conservative rule for any signed route added outside that
  group. Not confirmed with `route:list` here (no runnable PHP 8.5 environment); the tests fetch
  these URLs while authenticated.

## Code and tests

| | |
|---|---|
| Sign-in baseline | `app/Http/Controllers/OAuthLoginController.php` (package `RelyingPartyLoginController::callback`) |
| Session check | `RequireActiveProviderSession`, appended to `web` in `bootstrap/app.php`; `app/Http/Middleware/AuthenticateFirstPartySession.php` for `/api/v1` |
| OAuth tokens | package `ProviderIdentityTokens` and its repositories; `app/Support/AgentApi/ProviderIdentityWrites.php`; `app/Http/Middleware/EnsureOperationDeployed.php`; `app/Services/Mcp/McpRateLimitedCallToolHandler.php`; `app/Services/Credentials/AgentTokenIssuer.php` |
| Sanctum tokens | `app/Support/Auth/PersonalAccessTokenIdentity.php`, registered in `app/Providers/AppServiceProvider.php` |
| Delegated access | `app/Services/Access/DelegatedStaffAccess.php` |
| Configuration | `config/bherila-auth.php`, `provider_identity` |
| Tests | `tests/Feature/Auth/ProviderIdentityEnforcementTest.php`, `tests/Feature/Auth/ProviderIdentityCutoverTest.php`, `tests/Feature/Auth/ProviderSessionRouteCensusTest.php` (no session-authenticated route skips the check), `tests/Unit/Auth/ProviderIdentityWiringTest.php`, `tests/Unit/Auth/ProviderIdentityEnforcementCensusTest.php` (application code asks `ProviderIdentityEnforcement::enabled()`, never the setting) |
