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
slower response cannot restore refunded money. A refund or dispute is recorded
whatever the payment's finance reconciliation allocations; they are kept and
flagged for review (see [the finance reconciliation API](finance-api.md)).

A dispute notification names the dispute, which is re-fetched. A dispute on a
payment svc recorded moves it to `disputed` while an inquiry or chargeback is
open and when it is lost or accepted, and back to `succeeded` when it is won -
in part or in full, since the disputed amount may be part of the payment - or
when an inquiry closes. If the invoice was paid again while the dispute was
open, the restored payment is still recorded; the excess shows as the invoice's
overpayment, feeds the derived overpayment credit, and is named as
`overpaid_amount` in the `invoice.payment_received` activity for review. A refunded payment stays refunded. A dispute on a
payment svc never recorded changes nothing; an unknown state, another currency
or an amount above the payment is refused and released for retry. The sweep
does not read disputes, so a missed dispute notification is not recovered. A draft, void invoice, currency
mismatch, over-balance payment, invalid data or ambiguous tip allocation is
refused instead of changing the ledger. Failed events release their durable
claim and answer 503 for retry; successfully completed event IDs do no more work.
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
invoice there; svc does not write to Square. Other phase-two audit flags are a
separate slice: missing series periods, unmatched payments, unlinked open balances
and amount or cadence drift.

Payment reminders for linked invoices carry the mirrored Square payment page;
see [payment reminders](client-management/invoice-delivery.md#payment-reminders).
