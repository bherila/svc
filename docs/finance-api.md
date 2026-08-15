# Finance reconciliation API

The versioned API lets a finance application reconcile its transaction records
to SVC invoice payments without sharing databases or importing banking data into
SVC. Amounts are integer minor units, identifiers are public UUIDs, and every
request is restricted by both token ability and workspace membership.

## Authentication

Issue an expiring token for an exact SVC user public UUID. The plaintext token is
shown once; only its SHA-256 digest is stored.

```bash
php artisan svc:auth:issue-token \
  USER_PUBLIC_UUID \
  "Finance integration" \
  finance.read finance.reconcile \
  --expires-at=2026-09-15T00:00:00+00:00
```

Send it as `Authorization: Bearer TOKEN`. Read-only integrations should receive
only `finance.read`. A token never broadens the user's workspace role: listing
requires workspace view access, and reconciliation writes require owner or admin
access. Revoke a named token with:

```bash
php artisan svc:auth:revoke-token USER_PUBLIC_UUID "Finance integration"
```

If duplicate names exist, the command fails closed unless `--all` is supplied.

## Endpoints

`GET /api/v1/workspaces/{workspace}/invoice-payments`

- Requires `finance.read`.
- Defaults to successful payments and 50 records per page.
- Optional query parameters: `status`, `invoice`, `received_from`, `received_to`,
  and `per_page` (maximum 100).
- Returns invoice/client public identifiers, payment/net/reconciled amounts, and
  active plus historical reconciliation allocations. Internal database IDs,
  payment notes, and provider payment identifiers are not returned.

`PUT /api/v1/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}`

- Requires `finance.reconcile` and workspace owner/admin access.
- Accepts `allocated_amount`, `currency`, optional `reconciled_on`, and optional
  `is_active`.
- Is idempotent for the payment/system/transaction tuple.
- Allows one external transaction to cover multiple SVC payments and one payment
  to have multiple external transactions, while active allocations may not
  exceed the successful payment amount net of refunds.

`DELETE /api/v1/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}`

- Requires `finance.reconcile` and workspace owner/admin access.
- Deactivates the allocation for auditability; it does not delete its history.

Cross-workspace resource combinations return `404`. Missing or expired tokens
return `401`; missing abilities or insufficient workspace roles return `403`;
invalid input and domain preconditions return `422`.
