# Invoice review and delayed client delivery

Issuing an invoice creates two deliberately separate workflows. The first is
an administrator review notification. The second, only when a client company
has opted in, is delayed delivery to the client. An administrator notification
never counts as a client delivery.

## Administrator review notification

The first committed `draft` to `issued` transition registers one durable
notification for document revision 1. Registration occurs in the issuance
transaction, while sending occurs only after commit. A rollback therefore
leaves neither a row nor an email, and an idempotent second issue cannot create
another notification.

The notification uses `AdministratorInvoiceIssuedMail` and the
`invoices.administrator-issued-email` Blade view. It attaches the actual PDF
bytes rendered for the operator audience. Those bytes and the invoice summary
are snapshotted at issue time, so a later correction does not rewrite the
evidence of what was originally issued.

An owner or manager who covers every project attributed to the invoice is the
preferred recipient. A workspace owner, then a workspace administrator, is the
fallback for company-level and mixed invoices. Every lookup is workspace
scoped. Recipient eligibility is resolved again immediately before a retry so
a removed member is not mailed. If nobody eligible exists, the durable record
is visible as `missing_recipient` and is checked again daily without consuming
a delivery attempt.

The **Open** link is the normal authenticated client/workspace invoice URL. It
does not contain a bearer token and grants no access by itself; normal route
binding and invoice authorization still apply, including after login restores
the intended destination.

## Opt-in client delivery

Automatic delivery is disabled by default and is configured per client
company. Enabling it requires at least one valid recipient and an integer delay
from 0 through 365 days. Zero means eligible as soon as committed issuance and
the scheduler can process it; issuance itself still does not send the client
email inside its transaction.

The delay is snapshotted only for invoices issued after opt-in. Enabling a
company does not schedule its historical backlog, changing the delay affects
future invoices only, and disabling the option cancels pending work. Re-enabling
does not revive cancelled invoices.

The due instant starts at the committed `issued_at`, not the printable
`issue_date`. The service converts that instant to the workspace timezone, adds
calendar days while preserving the local wall-clock time across daylight-saving
changes, and stores the result in UTC.

At delivery time the scheduler locks and reloads the invoice, then rechecks its
workspace, client setting, exact invoice state, current recipients, current
document revision, due time, hold state, and all prior client deliveries. It
uses the normal `InvoiceMail` path and its client-audience PDF. Each attempt
records whether it was manual or automatic, its revision, recipients, result,
retry time, and provider reference.

`InvoiceMail` renders the `invoices.email` Blade view. The React screen only
composes and submits the delivery facts; it does not render the email body.

Successful manual delivery cancels the scheduled automatic delivery. A
correction holds it until an administrator explicitly releases it. Voiding or
disabling cancels it. Recording any payment also cancels it: automatic delivery
is an issuance notice and must not quietly become a payment reminder. An
operator may still make an intentional manual send where the ordinary invoice
state permits it.

## Correction boundary

An in-place correction is limited to an unpaid, issued, never-client-delivered
invoice with its original PDF evidence present. It preserves line identities,
time and expense allocations, credits, and payment history. Generated or
allocated lines allow description corrections only; an unallocated,
operator-authored line may also change quantity, unit amount, or tax. Lines
cannot be added or removed. Every correction requires the expected document
revision and a reason, increments the revision, recalculates totals, creates an
activity record, and holds automatic delivery. A generated credit keeps its
signed amount and total during a description-only correction.

Paid, partially paid, void, already sent, and in-flight invoices cannot use
this path. Their accounting correction is void/reissue or the narrower payment
workflow, as applicable.

## Durability and retries

`svc:billing:dispatch-invoice-emails --limit=50` processes bounded pages of
administrator notifications and due client sends. It is scheduled every minute
and does not use a queue worker. Claims are short row-lock transactions; the
external mail call occurs after the claim commits and outside the billing
transaction.

Known pre-provider failures use bounded backoff. A provider acceptance followed
by a local persistence failure remains `sending`: the result is ambiguous and
the scheduler will not blindly submit it again. Its durable claim and provider
logs must be reconciled before an operator chooses another send. A payment is
refused during the first hour of that claim; after that conservative expiry it
may proceed and cancels the automatic workflow while the delivery row remains
`sending` as evidence of the unresolved outcome. Administrator attempt history
and client delivery rows retain the non-private audit facts; PDF bytes and
recipient addresses are never written to application logs.

Manual delivery keys are unique per workspace. A replay returns the recorded
result even if the invoice later becomes paid or void. A definitely failed
attempt may be reclaimed only for the same invoice revision and only when no
other delivery is in flight. Concurrent first use of one key on different
invoices resolves to one delivery and one domain conflict, rather than exposing
a database exception.

Production rollout remains per-client opt-in. This feature does not enroll a
client, migrate historical invoices into the schedule, or change agent access.
