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
control it would let them claim every grant above for that address. Before trusting
such a provider, SVC must require an `email_verified` claim on the identity, refuse or
leave unverified any sign-in without it, and bind these grants only to verified
addresses.
