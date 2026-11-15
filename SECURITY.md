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
