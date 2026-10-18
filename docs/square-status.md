# Square payment status

The signed receiver is `POST /api/webhooks/square`. Register the notification URL
under the application's configured `APP_URL`, for example
`https://svc.example.test/api/webhooks/square`, for `invoice.updated`,
`invoice.payment_made`, `payment.updated`, `refund.updated`, `dispute.created`
and `dispute.state.updated` (or its older name, `dispute.state.changed`).
The local operator installs `SQUARE_WEBHOOK_SIGNATURE_KEY`; an absent key refuses
requests with 403 before decoding, database work or Square reads. Oversized bodies
are refused with 413 before hashing.

The receiver checks the base64 HMAC-SHA256 signature over that configured URL
followed by the exact raw request body. It has no session, authentication or CSRF
middleware. Any external application edge must also permit the machine path.
Only event and object identifiers are used from a signed notification. Invoice,
order, payment and dispute facts are re-fetched through the existing read-only
client.
No raw webhook body or signature key is stored in a receipt.

`svc:square:reconcile-payments` runs every fifteen minutes without overlapping.
`svc:square:reconcile-payments --invoice=INVOICE_PUBLIC_ID` reconciles one linked
invoice now instead, and `invoices.square_reconcile` (REST and MCP, workspace
managers, `billing:write`) does the same for an agent - for example right after
`invoices.square_link`, rather than waiting up to fifteen minutes. All three use
`SquareInvoiceReconciliation`, which runs the sweep's own read-only refetch; an
invoice with no active link is refused. Repeating it records nothing twice. The
operation reads Square before writing its idempotency receipt, so the receipt and
its audit record the outcome: a failed read or a refused payment is audited as a
failure, leaves no receipt, and the same key may be retried. Each call can read
Square up to 102 times, so a workspace gets 20 per ten minutes before 429. Asked
for one invoice while Square is not configured, the command fails rather than
reporting success.
It visits active links in bounded database batches, including paid invoices so
refunds missed by webhooks are recovered. Both transports finish their work in
the request or command; neither dispatches jobs. The sweep works without the
webhook signature key, using the existing Square connection configuration.
Recorded Square payments are also swept independently of current links, so a
later refund still corrects the original invoice after unlinking or re-pointing.
Historical links may recover existing refunds but never import new payments.
Connection, credential and rate-limit failures stop a sweep immediately; missing
or unreadable individual records can be skipped so other records are reconciled.

Completed full and partial payments use the received-payment service, with
provider `square` and the payment identifier as the existing unique provider key.
Refunds apply the existing received-payment correction and are cumulative, so a
slower response cannot restore refunded money. A draft, void invoice, currency
mismatch, over-balance payment, invalid data or ambiguous tip allocation is
refused instead of changing the ledger. A refund or dispute is recorded
whatever the payment's finance reconciliation allocations; they are kept and
flagged for review (see [the finance reconciliation API](finance-api.md)).
Failed events release their durable claim and answer 503 for retry;
successfully completed event IDs do no more work.

A dispute notification names the dispute, which is re-fetched. On a payment svc
recorded:

- An open inquiry or chargeback, and a lost or accepted dispute, make the
  payment `disputed` and hold back only the amount Square contests
  (`disputed_amount`): a 5,000 dispute against a 12,345 payment reopens the
  invoice by 5,000, not 12,345. A changed amount is recorded again.
- A won dispute (in part or in full) or a closed inquiry restores it to
  `succeeded`. If the invoice was paid again while the dispute was open, the
  restoration is still recorded: the excess is the invoice's overpayment, feeds
  the derived overpayment credit, and is named as `overpaid_amount` in the
  `invoice.payment_received` activity for review. If the invoice was voided
  meanwhile, the payment is restored, the invoice stays void, and the activity
  carries `review: restored_on_void_invoice`.
- A refunded payment stays refunded.
- Square's own update time of the dispute last applied is kept on the payment,
  so a read of the dispute older than one already applied changes nothing; two
  notifications fetched around a state change cannot undo it in the wrong
  order.

A dispute on a payment svc never recorded changes nothing. An unknown state,
another currency, an amount above the payment or an unreadable update time is
refused and released for retry. The sweep does not read disputes, so a missed
dispute notification is not recovered.
The received date comes from the card capture timeline in the workspace's time
zone. Payments without a trustworthy completion timestamp fail closed; creation
and last-update times are not substituted for the day money was received.
Webhook receipts retain their first arrival time and expire after thirty days,
beyond the delivery retry window. The daily
`svc:square:prune-webhook-receipts` command removes expired completed and failed
receipts in bounded batches, while retaining live claims in the configured
workspace. Payment-identifier uniqueness continues to guard the ledger after a
receipt expires.

When an invoice paid through another source remains open in the Square mirror,
the operator invoice and `billing_audit.stale_and_missing` show
`paid_outside_square`, with a finished dashboard link. The owner closes the Square
invoice there; svc does not write to Square.

## Billing-audit flags

`billing_audit.stale_and_missing` also carries `square_billing_audit`, read from
svc's mirror as of its last sync and empty where Square does not serve the
workspace. Each flag has a full `count`, at most 100 entries with finished svc and
Square dashboard URLs, and `truncated`:

- `unmirrored_series_periods`: a member of a series an agreement is billed in
  (`square_series_key`), within the agreement (from its start to its end, once it
  has one), that no svc invoice is linked to. `SCHEDULED` members are not billed yet, and `DRAFT`, `CANCELED` and
  `FAILED` ones are no bill, so none of them is flagged.
- `unmatched_payments`: a Square payment id on an invoice in svc's reach (actively
  linked, a billed-in-Square series member within its agreement, or of a confirmed
  customer) that svc has
  not recorded under provider `square`. `not_recorded` means the invoice is linked
  and the sweep has not recorded the payment, for example because it failed
  closed; `invoice_not_linked` means no svc invoice is linked to receive it. A
  payment recorded against another svc invoice still matches. The mirror takes
  payment ids from the order's tenders - `payment_id`, else the tender's own `id`
  - and counts a tender once it is settled (a captured card, Square account or buy
  now pay later tender, a completed bank transfer, or cash): an authorization may
  still be voided, and a capture changes the invoice's paid amount, so the order is read
  again then. An order with more tenders than the reconciler accepts (100) stays
  unresolved, as does a paid invoice until the sync has read its order or an order
  whose tender list is malformed; ids awaiting a re-read are not reported.
- `unlinked_customer_balances`: Square customers with no client company mapped
  and an open invoice, most owed first - the customers `square.customers.unlinked`
  lists with `with_open_balance`.
- `series_drift`: a billed-in-Square agreement whose billed series members differ
  in `cadence`, `currency` (another one, or more than one) or latest billed
  `amount`, or whose series the mirror does not hold (`series_not_mirrored`).
  `SCHEDULED` members are not billed yet, and members dated before the
  agreement's start are history; both are left out. Square numbers invoices
  uniquely per location only, so a prefix used in more than one location (or by a
  bill with no location) is
  reported as `multiple_locations` and nothing else is derived from it - no
  comparison, unmirrored period or unmatched payment - and Square still billing
  after the agreement ended is reported as `billed_after_end`. The agreement's amount
  is its retainer fee for one whole cycle; an agreement that sells no retainer fee
  is not compared on amount, and a series with fewer than two dated billed members
  has no cadence to compare.

The audit loads the agreements, the members of their series, the other mirrored
invoices within its reach, their active links, customers and recorded payment ids
once, with every query bounded and scoped to the workspace, and classifies them in
`SquareBillingAuditPolicy` without the database. Series members load first under
their own bound, so a large mirror never hides a billed-in-Square series.
`complete` is false unless the newest sync run completed without truncation (a
run still going or failed leaves the mirror between generations), when a bound (5,000 series members, 5,000 other mirrored invoices or
500 agreements, or 10,000 payment ids checked against the ledger) was reached, or while the sync has yet to read the payment ids of
a paid invoice in reach (it reads a bounded number of orders per run, and stamps
an order too large to read so it does not hold up the rest). The counts are then
a floor: without a complete sync nothing is derived from a series and no balance
is stated; a series is reported absent only after a complete sync, no series drift
is reported when series members were bounded, and no customer balance is stated
when the other invoices were, since any of them could then be partial. Payment ids record
the tender rules they were read by, so a change to those rules re-reads each
paid invoice's order once. Every flag
is resolved in svc or in the Square dashboard by a person: nothing here links,
records, imports or writes to Square.

## Status probe

`php artisan svc:square:status --format=json` reports whether Square is
configured, its environment and pinned `Square-Version`, whether its workspace
exists and the webhook signature key is installed, the last completed sync and
the latest sync run (status, trigger, counts and error code), plus
`last_run_abandoned` (a run still requested or running after the sync's own
30-minute abandonment threshold) and `sync_overdue` (no completed sync within an
hour). It never prints a credential and never calls Square. It exits 0 when Square
is configured, its workspace exists, a sync completed within the hour and the
latest run neither failed nor was abandoned, and 1 otherwise - so a stopped
scheduler shows up even though nothing else marks its runs while it is down. The
post-deploy probe runs it beside `svc:stripe:status` and `svc:brevo:status`, and,
as for those, treats exit 1 as a report rather than a deploy failure.

Payment reminders for linked invoices carry the mirrored Square payment page;
see [payment reminders](client-management/invoice-delivery.md#payment-reminders).
