# Finance reconciliation API

The versioned API lets a finance application reconcile its transaction records
to SVC invoice payments without sharing databases or importing banking data into
SVC. Amounts are integer minor units, identifiers are public UUIDs, and every
request is restricted by both the credential's scope and the caller's workspace
role.

The operations are declared once on the operation registry
(`AgentOperationCatalog`, #457), like every other `/api/v1` operation. Each is in
the OpenAPI document (`public/openapi/svc-agent-v1.json`, served at
`/api/openapi.json`) with its request and response schemas, scopes and refusals,
and each is an MCP tool and a console command of the same name, all running one
`FinanceReconciliationOperations`:

| Operation                            | REST                                                      | Scope               | Console                    |
| ------------------------------------ | --------------------------------------------------------- | ------------------- | -------------------------- |
| `finance.invoice_payments.list`      | `GET /api/v1/workspaces/{workspace}/invoice-payments`     | `finance:read`      | `svc:finance:payments`     |
| `finance.payouts.list`               | `GET /api/v1/workspaces/{workspace}/payouts`              | `finance:read`      | `svc:finance:payouts`      |
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

A first-party finance system is registered once with
`svc:auth:register-first-party-client` and, to connect without a consent
screen, allow-listed in `AGENT_API_FIRST_PARTY_OAUTH_CLIENTS` (see
[first-party applications](mcp.md#first-party-applications)).

When the person turns the connection off, the finance system calls
`connections.disconnect` (`DELETE /api/v1/connection`) with its own access
token: every credential the person holds for that client is revoked.

Before trusting a connection, the finance system reads `context.get`
(`identity:read`): its `provider_subject` is the person's subject at the trusted
identity provider (null for an account not bound to it), to compare with the
subject of the person who connected. It is a stable cross-application
identifier, not a secret, but it appears only in that authorized
self-description and is never logged.

## Endpoints

`GET /api/v1/workspaces/{workspace}/invoice-payments` (`finance.invoice_payments.list`)

- Defaults to successful payments and 50 records per page, numbered by `page`.
- Optional query parameters: `status` (or `any`), `invoice`, `received_from`,
  `received_to`, `per_page` (maximum 100) and `page`; for incremental sync,
  `updated_since`, `watermark` and `cursor` (below).
- Returns invoice/client public identifiers, payment/net/reconciled amounts, and
  active plus historical reconciliation allocations, with Laravel's `links` and
  `meta` pagination blocks. Internal database IDs and payment notes are not
  returned. A processor payment also carries what the processor reports about
  settling it (see [Processor settlement and payouts](#processor-settlement-and-payouts)).

### Incremental sync

The listing is also a change feed, with the same semantics as the sibling
applications' change feeds. Naming `updated_since`, `watermark` or `cursor`
switches it from numbered pages to change order:

- Payments come newest change first (`updated_at desc, id desc`), `per_page` at a
  time, with an opaque `meta.next_cursor` bound to its workspace, query and pass;
  `page` is refused there.
- The first page of a pass fixes a snapshot `watermark` - 120 seconds before the
  request (`ChangeWindow::LAG_SECONDS`), or an earlier time passed as
  `watermark`, never a later one - and every page returns it in
  `meta.watermark`. Rows changed while the pass is paged wait for the next pass.
  With a cursor, omit `watermark` or send the same one.
- The next pass sends that watermark as `updated_since`, which is inclusive.
  `updated_at` is stamped when a row is written, not when its transaction
  commits; the lag outlasts any request's transaction, so a row stamped before a
  pass started but committed after it read is in the next pass.
- **Passes overlap, so rows are re-delivered.** Deduplicate by `(id, version)`:
  timestamps locate candidates, `version` detects a change.
- A change feed reads every status: send `status=any`. It returns every
  recognised status, never a row whose status is outside the published enum. A
  full refund moves a payment to `refunded` while a partial refund leaves it
  `succeeded`, and a payment can change out of any other filter, so another
  `status`, `received_from` and `received_to` are refused (`422`) there; `invoice`
  is allowed, since a payment never changes invoice. Payments are never deleted.
- A row never stamped with `updated_at` comes last in a full pass and never
  matches `updated_since`.
- Each payment's opaque `version` also moves when one of its allocations does.
  Two changes in the same second still give two versions.
- The embedded `invoice` and its `client` are **read-time snapshots, not
  change-tracked**: a change to the invoice or client does not move the
  payment's `version` or `updated_at`. Follow the `invoices.list` and
  `clients.list` change feeds to keep them current.

The finance system's other reads take the same window: `invoices.list`
(`billing:read`) and `clients.list` (`clients:read`) page by id as before, and
switch to this change order - with `meta.watermark` - when a request names
`updated_since` or `watermark`. A cursor from one order is refused in the other.
A change feed takes only filters on what a record never changes: on
`invoices.list`, `company_id` and `invoice_kind`; on `clients.list`, none. Any
other - `status`, `collectible`, `overdue`, the issue, due and service-period
dates, a client's `status` - is refused (`422`, naming it): a record that
changes out of the filter would never be delivered again, and `overdue` changes
as the date passes without the invoice changing at all. Sync without them and
filter the synced records.

## Processor settlement and payouts

A Stripe or Square payment settles into the bank as part of a processor payout:
one deposit for many payments, net of the processor's fees. Each payment in
`finance.invoice_payments.list` carries what the processor reports about that:

| Field                          | Meaning                                                                                                                   |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| `provider_payment_identifier`  | The processor's own id for the payment (a Stripe payment intent, a Square payment); null for one recorded by hand.       |
| `provider_charged_at`          | When the processor charged it (ISO 8601). Not `received_on`, which for Stripe is the day svc heard of the payment.       |
| `provider_fee_amount`          | The processor's fee in minor units of the payment's currency, positive for a fee charged.                                 |
| `provider_payout_identifier`   | The payout the payment settled in.                                                                                         |
| `provider_payout_arrival_date` | The day that payout arrives at the bank.                                                                                   |

Each is null until the processor reports it. A fee is kept only in the
payment's own currency: a payment settled in another currency has no
`provider_fee_amount`. When the processor reports any of them the payment's
`version` and `updated_at` move, so the change feed delivers it.

`GET /api/v1/workspaces/{workspace}/payouts` (`finance.payouts.list`) lists the
payouts themselves, for the same callers as the payment listing:

- `id` (public UUID), `version`, `provider` (`stripe` or `square`),
  `provider_payout_identifier`, `amount`, `currency`, `arrival_date`, `type`
  (`automatic`, `manual` or `instant`) and `status` (`pending`, `in_transit`,
  `paid`, `failed` or `canceled`).
- `amount` is signed minor units: negative when the payout took money from the
  bank account (refunds or disputes larger than the period's charges).
- A payout's member payments are the payments whose
  `provider_payout_identifier` names it.
- Numbered pages (`page`, `per_page` up to 100, newest arrival first) filtered
  by `provider`, `status`, `arrival_from` and `arrival_to`; or the change feed
  (`updated_since`, `watermark`, `cursor`) with the same semantics as the
  payment feed. A payout's status and arrival date can change, so the feed takes
  only `provider` and refuses the others (`422`). Payouts are never deleted.
- A report never overwrites a newer one: a payout reported `failed` after it
  was reported `paid` stays `failed`, however the reports arrive. An older
  report changes none of the payout's payments either, so an arrival date it
  carries cannot undo a newer one.

**Matching is net-only.** A payout's `amount` is what reached the bank. Refunds,
disputes and fee adjustments that land in a different payout than their
payment, and Stripe manual or instant payouts, are not explained here; the
finance system treats what does not add up as unexplained.

Where the facts come from:

- **Stripe.** When a payment succeeds, svc reads its charge and balance
  transaction for the charge time and fee (best effort: a failure leaves them to
  the payout or the backfill). `payout.paid`, `payout.failed` and
  `payout.canceled` store the payout and, for an automatic payout, read its
  balance transactions to record on each charged payment its payout, arrival
  date and fee. One Stripe account serves the installation, so a payout is
  stored for every workspace whose payments (charges, refunds or disputes) it
  names. Stripe does not list a manual or instant payout's transactions; such a
  payout is updated only where a row for it already exists.
- **Square.** `payout.sent`, `payout.paid` and `payout.failed` are hints: svc
  reads the payout and its entries from Square's read-only Payouts API, stores
  it for the connected workspace, and records on each charged payment its
  payout, arrival date and fee. Reconciling a payment records its capture time
  and Square's processing fee so far; a fee from the payout is not overwritten.
- **History.** `svc:finance:backfill-payouts` fills both for payments and
  payouts recorded before svc stored them (below).

`PUT /api/v1/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}` (`finance.reconciliations.upsert`)

- Accepts `allocated_amount`, `currency`, optional `reconciled_on`, and
  `is_active`. Other fields are ignored. Through OAuth and MCP `is_active` is
  required: the finance system sends the state it wants now, so a retry that
  arrives late never brings back an allocation withdrawn in between. The finance
  token may still omit it, which keeps an existing allocation's state (a new one
  is active); only an explicit `true` reactivates. The request schema
  therefore lists `is_active` as optional; the OAuth door answers `422` without it.
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

## Recording a deposit and its allocation in one call

A finance system that sees money arrive for an invoice SVC has not recorded -
a bank transfer, a cheque - records the payment with `payments.record` and
passes `finance_reconciliation`:

```json
{
  "invoice_id": "…", "amount": 7000, "currency": "USD", "method": "ach",
  "received_on": "2026-10-15", "expected_version": "…", "confirm": true,
  "finance_reconciliation": {
    "external_system_slug": "synthetic-ledger",
    "external_transaction_uuid": "…",
    "allocated_amount": 7000
  }
}
```

The payment and its allocation are written in one transaction under the call's
`Idempotency-Key`: either both exist or neither does. The allocation is what
`finance.reconciliations.upsert` would write for the new payment - active, in
the payment's currency, with no `reconciled_on` - and the write is audited under
both `payments.record` and `finance.reconciliations.upsert`. The object is
closed (exactly those three fields), needs `finance:reconcile` on top of
`payments.record`'s own scopes and write cutovers, needs `status: succeeded`
(the default), and `allocated_amount` may not exceed `amount`. It does not set
the legacy `external_finance_transaction_uuid`, which it supersedes.

A retry with the same key and body answers with the original payment and
writes nothing; the same key with any other body is a `409`. A `422` is
definitive - nothing was written - and a later attempt takes a new key.

`payments.record` refuses (`422`, checked under the invoice lock, so a read
taken before the other payment or link arrived does not let it through) while
another payment on the invoice may still be in flight - `pending`, or a status
SVC cannot read - and while Square collects the invoice, whose payments
Square's own sync records. `invoices.get` reports both to an owner/admin as
`pending_payments` and `collection_owner` (`svc` or `square`), so a caller can
tell beforehand why a record would be refused. Stripe and Square payments are
recorded by their processors; reconcile those with
`finance.reconciliations.upsert` rather than recording them again.

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

## Deep links

A finance application that shows an SVC payment links to it by its public UUID,
never by a screen address:

```
GET /finance-links/payments/{payment-uuid}
```

The address is permanent: it names the payment and nothing else, so it keeps
working when SVC's screens move. It is a browser route, not an API operation.

- A signed-in reader who may see the payment is redirected (`302`) to its
  invoice screen, anchored at the payment's row (`#payment-{payment-uuid}`).
  "May see" is exactly what the invoice screen requires: membership of the
  payment's workspace, reach to its client, and every project the invoice bills.
- Anyone else gets `404`, the same response an unknown UUID gets: a payment in
  another workspace, a client or invoice this reader cannot reach, a client
  portal reader, a malformed id, or an integer key. The payment is looked up
  only inside the reader's own workspaces, so another tenant's row is never
  read to decide.
- A reader who is not signed in is sent to sign in, whether or not the payment
  exists, and returns to the link afterwards.
- It is throttled to 60 requests a minute per reader.

The other direction is configuration, never code. A payment with an active
allocation from the finance system shows **Reconciled in finance** on its
invoice screen, to owners and admins, linking to each allocated transaction:

| Setting                       | Config key                     | Default   |
| ----------------------------- | ------------------------------ | --------- |
| `SVC_FINANCE_EXTERNAL_SYSTEM` | `svc.finance.external_system`  | `finance` |
| `SVC_FINANCE_TRANSACTION_URL` | `svc.finance.transaction_url`  | unset     |

The URL is an absolute http(s) template in which `{transaction_uuid}` is
replaced, for example
`https://finance.example.test/transactions/{transaction_uuid}`. The UUID is the
one the allocation was written with: SVC never re-points it, so a finance system
that merges transactions keeps resolving the UUIDs it published. Unset, or not
an absolute http(s) address, the badge has no link.

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
php artisan svc:finance:payouts --workspace=WORKSPACE --user=USER \
    [--provider=stripe] [--status=paid] [--arrival-from=YYYY-MM-DD] \
    [--arrival-to=YYYY-MM-DD] [--per-page=50] [--page=1] [--format=json]
```

### Backfilling settlement

```bash
php artisan svc:finance:backfill-payouts [--provider=all|stripe|square] \
    [--since=YYYY-MM-DD] [--workspace=WORKSPACE] [--apply] [--format=json]
```

Reads Stripe's and Square's payouts (created on or after `--since`; Square's
own default without it is the last year) and their member payments, then each
processor payment still missing a charge time or fee, and records them through
the same code as the webhooks. It reads the processors only, and writes
nothing without `--apply`: the default is a dry run reporting what would
change. Every write is keyed by the processors' own identifiers and writes only
what differs, so running it again changes nothing. A run stopped by a processor
failure reports what it did and exits non-zero; running it again resumes, since
stored payouts are unchanged and the charge pass revisits only payments still
missing facts. `--workspace` keeps every write to one workspace. Output is
counts only.
