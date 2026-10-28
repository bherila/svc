# Finance reconciliation API

The versioned API lets a finance application reconcile its transaction records
to SVC invoice payments without sharing databases or importing banking data into
SVC. Amounts are integer minor units, identifiers are public UUIDs, and every
request is restricted by both the credential's scope and the caller's workspace
role.

## Contract baseline

Authenticated `context.get` (`GET /api/v1/context`, `identity:read`) publishes
required integer `data.contract_version: 3`. This is the current published baseline
of the published agent API contract. A finance consumer should check it when
connecting and before syncing, accepting only the exact integer baseline it
supports. Missing values, wrong types and unsupported integers are not version
1, even if an older installation also serves `/api/v1` or advertises OpenAPI
`info.version: 1.0.0`.

The baseline is separate from the application's release, per-record HMAC
`version` hashes, change-feed cursors and MCP protocol negotiation. It grants no
scopes, workspace role or write cutover: consumers must still check current
identity, capabilities and authorization on each operation. A future incompatible
contract baseline needs a new integer and matching published schemas; a numeric
range check is not compatibility negotiation.

The operations are declared once on the operation registry
(`AgentOperationCatalog`, #457), like every other `/api/v1` operation. Each is in
the OpenAPI document (`public/openapi/svc-agent-v1.json`, served at
`/api/openapi.json`) with its request and response schemas, scopes and refusals,
and each is an MCP tool and a console command of the same name, all running one
`FinanceReconciliationOperations`:

| Operation                            | REST                                                      | Scope               | Console                    |
| ------------------------------------ | --------------------------------------------------------- | ------------------- | -------------------------- |
| `finance.invoice_payments.list`      | `GET /api/v1/workspaces/{workspace}/invoice-payments`     | `finance:read`      | `svc:finance:payments`     |
| `finance.processor_movements.list` | `GET /api/v1/workspaces/{workspace}/processor-movements` | `finance:read` | `svc:finance:processor-movements` |
| `finance.payouts.list`               | `GET /api/v1/workspaces/{workspace}/payouts`              | `finance:read`      | `svc:finance:payouts`      |
| `finance.reconciliations.upsert`     | `PUT …/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}` | `finance:reconcile` | `svc:finance:reconcile`    |
| `finance.reconciliations.acknowledge` | `PUT …/invoice-payments/{payment}/reconciliations/{external-system}/{transaction-uuid}/acknowledgement` | `finance:reconcile` | `svc:finance:acknowledge-reconciliation` |
| `finance.reconciliations.deactivate` | `DELETE` the same path                                    | `finance:reconcile` | `svc:finance:unreconcile`  |

## Billable expense provenance

A finance consumer can record a billable expense through `expenses.log`, using
its existing expense scopes and manager authorization, with optional
`external_finance_transaction_uuid` on each entry. This opaque UUID is
creation-only provenance; SVC does not verify a remote banking transaction or
create a reconciliation allocation, receipt, or payment from it.

The existing `Idempotency-Key` remains the retry identity. The same key and body
replay the original result across REST and MCP; the same key with a changed UUID
returns `409`. A different key is a distinct allowed request, even for the same
UUID. There is no additional UUID uniqueness or deduplication policy.

Omitting the field or sending null preserves legacy behavior. API updates cannot
replace or clear it; ordinary edits retain it, and generated recurring expenses
do not copy it. Workspace owners/admins can read the reference even with
read-only credentials or disabled write cutovers. Other readers receive null,
and generic model serialization hides it. Existing receipt upload is unchanged.

See [expense tools](mcp.md#expense-finance-provenance) for the shared transport
contract.

## Authentication

Two credentials open these routes, and only these routes accept the first.

### Finance integration token (Sanctum)

Issue an expiring token for an exact SVC user public UUID. The plaintext token is
shown once; only its SHA-256 digest is stored.

```bash
php artisan svc:auth:issue-token --apply \
  USER_PUBLIC_UUID \
  "Finance integration" \
  finance.read finance.reconcile \
  --expires-at=2026-09-15T00:00:00+00:00
```

Send it as `Authorization: Bearer TOKEN`. Read-only integrations should receive
only `finance.read`. On these routes the `finance.read` ability stands for the
`finance:read` scope and `finance.reconcile` for `finance:reconcile`; the token
holds no other scope, so it opens only the finance operations and nothing else in
`/api/v1` (a token issued with Sanctum's `*` ability is no exception). A token
never broadens the user's workspace role: listing requires workspace membership,
and reconciliation writes require owner or admin access. This is the contract the
integration has always had. Revoke a named token with:

```bash
php artisan svc:auth:revoke-token USER_PUBLIC_UUID "Finance integration" --apply
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

Invoice change-feed mode is supported only for workspace owners and admins.
Other authorized viewers receive `422` naming `change_feed`: a portal snapshot
can lose an invoice when it becomes hidden or void, and this feed does not
emit removal records. They can still request ordinary invoice snapshots
without `updated_since` or `watermark`. These window parameters belong to the
REST listing; the MCP `invoices.list` tool currently lists snapshots only.

A change feed takes only filters on what a record never changes: on
`invoices.list`, `company_id` and `invoice_kind`; on `clients.list`, none. Any
other - `status`, `collectible`, `overdue`, the issue, due and service-period
dates, a client's `status` - is refused (`422`, naming it): a record that
changes out of the filter would never be delivered again, and `overdue` changes
as the date passes without the invoice changing at all. Sync without them and
filter the synced records.

## Signed processor movements

`finance.processor_movements.list` (`GET /api/v1/workspaces/{workspace}/processor-movements`)
reads recorded refunds, refund reversals, dispute debits, dispute credits, fees
and fee reversals. It never calls a processor or changes a payment, invoice or
allocation. It uses the same `finance:read` scope and owner/admin OAuth policy
as the payment listing; a Sanctum `finance.read` token may read its workspace
as a member. No new OAuth scope or contract baseline is required. Authorized
workspace managers discover this additive surface through the
`finance:processor_movements:read` workspace capability.

Each closed record has `id` (movement public UUID), `version`, `payment_id`
(original payment public UUID), `provider`, `kind`, `principal_minor`,
`fee_minor`, `net_minor`, `currency`, `provider_occurred_at`, `occurred_on`,
`occurred_timezone`, `date_basis`, nullable `available_on`, nullable
`provider_payout_identifier`, `needs_review` and nullable `review_reason`.
Amounts are signed safe integers in currency minor units: a refund/debit has
negative principal; a reversal/credit has positive principal. Fees are positive
when charged and negative when reversed; `net_minor = principal_minor - fee_minor`.
The original facts are immutable. Conflicting provider facts keep the original
amount and date and set `needs_review` with `immutable_facts_changed`.

Processor timestamps are UTC with microsecond precision. `occurred_on` is the
local calendar date captured with `occurred_timezone` at recording time;
changing the workspace's timezone does not move historical dates. `date_basis`
is `stripe_balance_created` or `square_effective_at`. Account keys, raw balance
entry/source identifiers, notes and payloads never cross this boundary.

This endpoint always returns `{data, meta: {next_cursor, watermark}}`. Follow
`next_cursor` until null; begin the next pass with its watermark as inclusive
`updated_since`, deduplicating by `(id, version)`. The watermark lags reads by
two minutes, as on the other change feeds. Microsecond positions and equal-time
id ties are preserved. A held or enriched record is redelivered when its version
changes. Optional immutable filters are `provider`, `kind`, `payment_id`,
`occurred_from` and `occurred_to` (inclusive captured local dates); `per_page`
defaults to 50 and is at most 100. `updated_since`, `watermark` and `cursor` select
the change window. Every unsupported filter, including `page`, `needs_review`
and `review_reason`, returns 422. A cursor belongs to one workspace, immutable
filter selection and lower bound; changing those requires a fresh pass.

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

## Disputed invoice state

An invoice with any payment whose status is `disputed` has invoice status
`disputed`, including a partial dispute or a balance already covered by a
replacement payment. The invoice remains charged and immutable; authorized
internal and portal readers can still see it, and `invoices.list` accepts
`status=disputed`. It is excluded from `collectible` and `overdue` filters and
collection summaries. Payment initiation, invoice collection email and payment
reminders pause while the dispute remains. An operator may still explicitly
record money already received against its remaining balance.

The undisputed part of a partly disputed payment still reduces the invoice's
balance. The entire disputed payment is withheld from the spendable credit pool;
its undisputed remainder does not become credit. Once every disputed payment
leaves that state, net payment contributions determine `issued`, `partially_paid`
or `paid` again. A processor restoring a won dispute after replacement payment is
recorded even when it creates excess credit; that restored excess is available
to future drafts without a separate hold. A void invoice always stays void.

Existing charged invoices with tenant-matching disputed payments are backfilled
to this status with a new invoice revision. Draft, void, unknown-status invoices
and malformed cross-workspace payment links are excluded.

The processor follow-ups tracked in #481 are delivered: scheduled Square dispute
recovery handles missed notifications and disputes arriving before payment;
conditional reconciliation acknowledgement records a reviewed allocation;
Stripe refund recovery rereads the refund list rather than trusting a stale
charge aggregate. Synthetic regressions are in `SquareDisputeRecoveryTest`,
`ReconciliationReviewAcknowledgementTest` and `StripeAuthoritativeRefundTest`.

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
another payment on the invoice may still be in flight - `pending`, a Stripe
payment stored as `failed` (its intent can still be confirmed), or a status SVC
cannot read exactly, in any spelling or case - and while Square collects the
invoice, whose payments Square's own sync records. Recording any payment
advances the invoice's `version`, so a request whose view of the invoice
predates one is refused with `409` (read the invoice again and retry). `invoices.get` reports both to an owner/admin as
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
php artisan svc:finance:processor-movements --workspace=WORKSPACE --user=USER \
    [--provider=stripe] [--kind=refund] [--payment-id=PAYMENT_UUID] \
    [--occurred-from=YYYY-MM-DD] [--occurred-to=YYYY-MM-DD] \
    [--per-page=50] [--updated-since=UTC_TIME] [--watermark=UTC_TIME] \
    [--cursor=OPAQUE_CURSOR] [--format=json]
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

## Acknowledge an allocation review

`finance.reconciliations.acknowledge` is an explicit finance API action for a
reviewed allocation that remains correct after a processor refund or dispute.
There is no browser acknowledgement screen. REST, MCP and the console use the
same service and owner/admin policy. OAuth requires `finance:reconcile`;
Sanctum requires `finance.reconcile`.

Send `PUT` to the allocation's natural path with `/acknowledgement` appended.
The closed body requires:

- `expected_payment_version`: the payment's current opaque `version` from
  `finance.invoice_payments.list` (include non-succeeded payments when reading);
- `expected_review_reason`: its allocation's current `processor_refund` or
  `processor_dispute` reason;
- `expected_review_flagged_at`: that allocation's exact review instant, including
  timezone (equivalent offsets identify the same instant);
- `confirm`: the JSON boolean `true`, after explicitly reviewing the allocation.

Under the existing billing, payment and allocation locks, the action checks the
payment revision before checking the review proof, then clears only
`review_reason` and `review_flagged_at`. Allocated amount, currency, activity,
reconciliation date and payment amount/status are retained, even if the allocation
now exceeds payment net or the payment is disputed or refunded. The payment's
version advances, and the operation is audited through the existing finance
mutation path. The response is `FinanceReconciliationResponse`.

This is conditional and idempotent by effect, without an idempotency key or
receipt. Repeating the original request returns 409, including after a successful
clear. A stale revision, changed proof or already-cleared review also returns
409: refetch the payment and allocation instead of replaying a stale decision.
A newer review is never cleared by an earlier acknowledgement. Missing or
invalid proof, non-boolean confirmation and unknown fields return 422.
Acknowledgement does not change any credit policy, resolve a processor dispute,
restore a payment or move money.

```bash
php artisan svc:finance:acknowledge-reconciliation PAYMENT_UUID synthetic-ledger TRANSACTION_UUID \
  --workspace=WORKSPACE_UUID --user=USER_UUID --expected-payment-version=OPAQUE_VERSION \
  --expected-review-reason=processor_dispute --expected-review-flagged-at=UTC_INSTANT \
  --confirm --format=json
```

The console previews by default and rolls back the acknowledgement and its audit.
Add `--apply` to persist it; stale proof is refused in either mode.
