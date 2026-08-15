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
