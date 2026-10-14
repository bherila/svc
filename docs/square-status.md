# Square payment status

The signed receiver is `POST /api/webhooks/square`. Register the notification URL
under the application's configured `APP_URL`, for example
`https://svc.example.test/api/webhooks/square`, for `invoice.updated`,
`invoice.payment_made`, `payment.updated` and `refund.updated`.
The local operator installs `SQUARE_WEBHOOK_SIGNATURE_KEY`; an absent key refuses
requests with 403 before decoding, database work or Square reads. Oversized bodies
are refused with 413 before hashing.

The receiver checks the base64 HMAC-SHA256 signature over that configured URL
followed by the exact raw request body. It has no session, authentication or CSRF
middleware. Any external application edge must also permit the machine path.
Only event and object identifiers are used from a signed notification. Invoice,
order and payment facts are re-fetched through the existing read-only client.
No raw webhook body or signature key is stored in a receipt.

`svc:square:reconcile-payments` runs every fifteen minutes without overlapping.
It visits active links in bounded database batches, including paid invoices so
refunds missed by webhooks are recovered. Both transports finish their work in
the request or command; neither dispatches jobs. The sweep works without the
webhook signature key, using the existing Square connection configuration.

Completed full and partial payments use the received-payment service, with
provider `square` and the payment identifier as the existing unique provider key.
Refunds apply the existing received-payment correction and are cumulative, so a
slower response cannot restore refunded money. A draft, void invoice, currency
mismatch, over-balance payment, invalid data or ambiguous tip allocation is
refused instead of changing the ledger. Failed events release their durable
claim and answer 503 for retry; successfully completed event IDs do no more work.
The received date comes from the card capture timeline in the workspace's time
zone. Payments without a trustworthy completion timestamp fail closed; creation
and last-update times are not substituted for the day money was received.

When an invoice paid through another source remains open in the Square mirror,
the operator invoice and `billing_audit.stale_and_missing` show
`paid_outside_square`, with a finished dashboard link. The owner closes the Square
invoice there; svc does not write to Square. Other phase-two audit flags are a
separate slice: missing series periods, unmatched payments, unlinked open balances
and amount or cadence drift.

Portal invitations and automatic reminder settings are unaffected by this slice.
