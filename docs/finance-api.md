# Finance reconciliation API

The versioned API lets a finance application reconcile its transaction records
to SVC invoice payments without sharing databases or importing banking data into
SVC. Amounts are integer minor units, identifiers are public UUIDs, and every
request is restricted by both the credential's scope and the caller's workspace
role.

The three operations are declared once on the operation registry
(`AgentOperationCatalog`, #457), like every other `/api/v1` operation. Each is in
the OpenAPI document (`public/openapi/svc-agent-v1.json`, served at
`/api/openapi.json`) with its request and response schemas, scopes and refusals,
and each is an MCP tool and a console command of the same name, all running one
`FinanceReconciliationOperations`:

| Operation                            | REST                                                      | Scope               | Console                    |
| ------------------------------------ | --------------------------------------------------------- | ------------------- | -------------------------- |
| `finance.invoice_payments.list`      | `GET /api/v1/workspaces/{workspace}/invoice-payments`     | `finance:read`      | `svc:finance:payments`     |
| `finance.reconciliations.upsert`     | `PUT …/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}` | `finance:reconcile` | `svc:finance:reconcile`    |
| `finance.reconciliations.deactivate` | `DELETE` the same path                                    | `finance:reconcile` | `svc:finance:unreconcile`  |

## Authentication

Two credentials open these routes, and only these routes accept the first.

### Finance integration token (Sanctum)

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
only `finance.read`. On these routes the `finance.read` ability stands for the
`finance:read` scope and `finance.reconcile` for `finance:reconcile`; the token
holds no other scope, so it opens these three operations and nothing else in
`/api/v1` (a token issued with Sanctum's `*` ability is no exception). A token
never broadens the user's workspace role: listing requires workspace membership,
and reconciliation writes require owner or admin access. This is the contract the
integration has always had. Revoke a named token with:

```bash
php artisan svc:auth:revoke-token USER_PUBLIC_UUID "Finance integration"
```

If duplicate names exist, the command fails closed unless `--all` is supplied.
Sanctum tokens have no revoked flag, so revoking deletes the row, effective on the
next request. Each revocation logs `personal_access_token.revoked` with the user's
public UUID, the name, the count and fingerprints of the token ids - HMAC-SHA-256
under the application key, since a plain digest of a sequential id is reversed by
counting - never the token.

For a user bound to the identity provider, the token records the provider subject
and the credential generation it was issued under, and issuing is refused when the
provider reports the identity inactive. Once provider identity enforcement is enabled
(`BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true`), such a token stops working when the
identity is disabled, deleted, reset or ungranted at the provider (`401`); while the
provider cannot be asked the request answers a retryable `503` with `Retry-After`.
Writes check the provider freshly. A bound user's token issued before this stamping
existed is refused once enforcement is on, so reissue it first. Tokens of users with
no provider binding are unaffected.

### OAuth and personal API tokens

A REST-audience OAuth token, an operator-issued agent token
(`svc:auth:issue-agent-token … finance:read finance:reconcile`) or a personal API
token from the setup page that holds `finance:read` or `finance:reconcile` also
calls these routes, and an MCP connection holding them calls the tools. Through
this door **every** finance operation requires a workspace owner or admin, the
listing included, and the tools are offered only to someone who manages a
workspace. The finance token's member listing is not extended to OAuth.

## Endpoints

`GET /api/v1/workspaces/{workspace}/invoice-payments` (`finance.invoice_payments.list`)

- Defaults to successful payments and 50 records per page, numbered by `page`.
- Optional query parameters: `status` (or `any`), `invoice`, `received_from`,
  `received_to`, `per_page` (maximum 100) and `page`; for incremental sync,
  `updated_since`, `watermark` and `cursor` (below).
- Returns invoice/client public identifiers, payment/net/reconciled amounts, and
  active plus historical reconciliation allocations, with Laravel's `links` and
  `meta` pagination blocks. Internal database IDs, payment notes, and provider
  payment identifiers are not returned.

### Incremental sync

The listing is also a change feed, with the same semantics as the sibling
applications' change feeds. Naming `updated_since`, `watermark` or `cursor`
switches it from numbered pages to change order:

- Payments come newest change first (`updated_at desc, id desc`), `per_page` at a
  time, with an opaque `meta.next_cursor` bound to its workspace, query and pass;
  `page` is refused there.
- The first page of a pass fixes a snapshot `watermark` (now, or an earlier time
  passed as `watermark`; never a future one) and every page returns it in
  `meta.watermark`. Rows changed while the pass is paged wait for the next pass.
  With a cursor, omit `watermark` or send the same one.
- The next pass sends that watermark as `updated_since`, which is inclusive.
  Starting a few seconds earlier is harmless, and covers a write whose
  transaction committed after the previous pass read.
- `status=any` (either order) returns every status. A full refund moves a
  payment to `refunded` while a partial refund leaves it `succeeded`, so a
  synchronizer reads `any`. Payments are never deleted.
- A row never stamped with `updated_at` comes last in a full pass and never
  matches `updated_since`.
- Timestamps locate candidates; `version` detects a change. Each payment carries
  its opaque revision, which also moves when one of its allocations does. Two
  changes in the same second still give two versions.

The finance system's other reads take the same window: `invoices.list`
(`billing:read`) and `clients.list` (`clients:read`) page by id as before, and
switch to this change order - with `meta.watermark` - when a request names
`updated_since` or `watermark`. A cursor from one order is refused in the other.

`PUT /api/v1/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}` (`finance.reconciliations.upsert`)

- Accepts `allocated_amount`, `currency`, optional `reconciled_on`, and
  `is_active`. Other fields are ignored. Through OAuth and MCP `is_active` is
  required: the finance system sends the state it wants now, so a retry that
  arrives late never brings back an allocation withdrawn in between. The finance
  token may still omit it, which keeps an existing allocation's state (a new one
  is active); only an explicit `true` reactivates.
- Any change - recording, changing or withdrawing an allocation, or clearing its
  review flag - advances the payment's `version` and `updated_at`, since the
  listing embeds its allocations; a request that changes nothing leaves them.
- Is idempotent for the payment/system/transaction tuple, so it takes no
  `Idempotency-Key`: `201` when it created the allocation, `200` when it updated it.
- Allows one external transaction to cover multiple SVC payments and one payment
  to have multiple external transactions, while active allocations may not
  exceed the successful payment amount net of refunds.
- A write that succeeds clears the allocation's review flag (below).

`DELETE /api/v1/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}` (`finance.reconciliations.deactivate`)

- Deactivates the allocation for auditability; it does not delete its history.
  It also clears a processor review flag (below). Upserting it again with
  `is_active: true` reactivates it. Idempotent for the same tuple.

## Processor refunds and disputes

A refund or dispute reported by a payment processor (Stripe, Square) is always
recorded, whatever the payment's allocations: it is money that has already
moved. The allocations are not shrunk or deactivated. Each active one is kept
exactly as written and flagged instead:

- `needs_review`: `true` while the flag is set.
- `review_reason`: `processor_refund` (the refunded amount changed, up or down -
  a failed or canceled refund lowers it again) or `processor_dispute` (the
  payment was disputed). The latest event's reason is kept.
- `review_flagged_at`: when it was last flagged (ISO 8601).

A full refund leaves the payment `refunded`; a partial one leaves it `succeeded`
with `refunded_amount` raised, so key on amounts rather than status. A disputed
payment is `disputed` until the dispute is won (in part or in full), when it is
`succeeded` again; the flag stays until reviewed. While a payment is `disputed`,
`disputed_amount` is what the processor's dispute holds back - only that part
leaves the invoice - and the whole amount when the processor did not say; it is
null for any other status. An allocation may therefore
exceed `net_amount` while it is flagged. To clear the flag, write the
allocation again with an amount that fits the net (`PUT`), or deactivate it
(`DELETE`), which clears it too. A `PUT` is refused on a payment that is no
longer `succeeded` (fully refunded or disputed), so there `DELETE` is the way to
resolve the review. An inactive allocation never reports `needs_review`.

A workspace the caller is not a member of, and cross-workspace resource
combinations, return `404`: the workspace is concealed before anything is read
or written. Missing or expired tokens
return `401`; missing scopes or abilities and insufficient workspace roles return
`403` (a missing scope is answered by the route gate with the operation, reason
and an `insufficient_scope` challenge); invalid input and domain preconditions
return `422`. Responses are `Cache-Control: no-store`.

Every write - from any door - is recorded in `agent_mutation_audits` under its
operation id, with the caller's OAuth client id, `finance-token:<token row>` for
the Sanctum token, or `console`, and a refused write is recorded as failed with
its category. Writes take no `expected_version`: the path names the allocation
and the body is the whole of it, so the last write is the allocation.

Neither the agent write cutovers (`AGENT_API_WRITES_ENABLED`,
`AGENT_API_PAYMENT_WRITES_ENABLED`) nor the MCP switches withhold these REST
routes; the MCP switches (`mcp.read`, `mcp.write`, or the tool's own name) withhold
the tools.

## Console

The commands act as the owner or admin `--user` names, through the same
operation, rules and audit, and print the API's representation with
`--format=json`:

```bash
php artisan svc:finance:payments --workspace=WORKSPACE --user=USER \
    [--status=succeeded] [--invoice=INVOICE] [--received-from=YYYY-MM-DD] \
    [--received-to=YYYY-MM-DD] [--per-page=50] [--page=1] [--format=json]
php artisan svc:finance:reconcile PAYMENT SYSTEM TRANSACTION --workspace=WORKSPACE \
    --user=USER --amount=MINOR_UNITS --currency=USD [--reconciled-on=YYYY-MM-DD] \
    [--inactive] [--format=json]
php artisan svc:finance:unreconcile PAYMENT SYSTEM TRANSACTION --workspace=WORKSPACE \
    --user=USER [--format=json]
```
