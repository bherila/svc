# Payments

Recording and validating payments against client invoices, and the invoice status transitions they drive. Part of the [Billing & Invoicing System](billing.md). See also [Overpayment credits](overpayment-credits.md) (overpaid amounts carry forward) and [Stripe billing](stripe-billing.md) (online payments for issued invoices).

## Payment Methods

`method` is free text in the database — imports carry whatever the source system
called it, and Stripe writes `stripe` itself — but the record-payment form
offers a closed list so a hand-entered payment does not arrive as
`bank_transfer`, `Bank Transfer` and `wire` on three different days:

- Bank transfer, Wire, ACH, Check, Card, Cash
- **Other…**, which asks for the name rather than storing the literal `other`

The list lives in `resources/js/lib/payments.ts`. Nothing downstream reads
`method`; it appears only on the invoice screen.

## Payment status

A payment carries exactly one of six statuses, and `InvoicePaymentStatus` is the
whole vocabulary:

| status | counts toward the invoice's paid amount |
| --- | --- |
| `succeeded` | yes |
| `pending`, `failed`, `refunded`, `disputed`, `canceled` | no |

The list is enforced at the **service** boundary, not only at the HTTP one. It
used to be written down in four places — two validation rule lists, an
`in_array` in `setPaymentStatus()`, and the literal `'succeeded'` the balance
recomputation filtered on — and the four did not agree about who enforced it.
`InvoiceLifecycleService::applyPayment()` and `svc:billing:payment` did not, and
the column is an unconstrained `varchar(24)`, so `--status=paid` — the intuitive
*invoice* word — or a mistyped `suceeded` was accepted end to end and produced a
row the recomputation read as having contributed nothing. The invoice stayed
`issued` for its whole balance with that payment sitting on it, so money
genuinely received was invisible and the same balance could be collected again.

Both sides now parse rather than compare:

- **On write**, an unrecognised status is refused by name, and a non-string
  value is refused by type rather than stringified. Omitting the field still
  means `succeeded`; supplying an empty string does not, because that is a value
  someone chose.
- **On recomputation**, every payment row is validated before any of them is
  totalled. A status this application cannot read is not evidence that the row
  moved no money, so the balance is not recomputed at all. Write-side validation
  does not cover this — an import, a migration or a hand-repair can put such a
  row there without passing through `applyPayment()`.

Repairing one is still possible: `setPaymentStatus()` writes the recognised
replacement *before* it recomputes, so reclassifying the offending row succeeds.
A second unreadable row rolls that repair back — one at a time is a repair, two
is a balance nobody can total.

## Payment Validation
The system enforces strict payment validation to maintain data integrity:

1. **Overpayment Prevention**:
   - A **succeeded** payment cannot exceed the invoice's remaining balance. A
     payment in any other status has moved no money yet, so it is not checked
     against the balance on the way in; the check applies when it is transitioned
     to `succeeded`, where successful payments net of refunds cannot exceed the
     invoice total
   - The refusal is a `DomainException`, rendered as HTTP 422 for a JSON request
     and as a `billing` validation error otherwise

2. **Payment Amount Rules**:
   - `amount` is an integer in **minor units**, minimum 1 — so $0.01
   - `currency` must match the invoice's, as an uppercase ISO 4217 code
   - `received_on` is optional; omitted, it defaults to today in the
     workspace's timezone

3. **Payment Date Rules**:
   - `received_on` is a calendar date written `YYYY-MM-DD` — the same format
     the finance read endpoint filters this column by. `date` used to be the
     write-side rule, so `01/02/2026`, `2026-8-15` and a whole ISO 8601 instant
     were accepted and stored as whatever day the parser chose
   - **Not in the future**, and not more than
     `PaymentDateBounds::FLOOR_YEARS_BEFORE_TODAY` (two) years back. Both ends
     are measured against `WorkspaceClock::today($invoice->workspace)`, so a
     workspace west of UTC is not refused its own evening and a year typed one
     digit wrong does not reconcile into 2015
   - **A payment may predate the invoice's `issue_date`.** A deposit or an
     advance retainer applied to an invoice issued afterwards is a real
     arrangement, so this is not refused. The recording form warns as the date
     is entered, and the payments table marks the row; the write succeeds
   - The bounds live in `InvoiceLifecycleService::applyPayment()` as a
     `DomainException`, because `svc:billing:payment`, an import and a
     hand-repair never cross the HTTP door. `StorePaymentRequest` keeps the
     cheap format rule so the browser gets a field error

## Invoice Status Transitions

**Draft → Issued is never a payment transition.** It happens only through
`InvoiceLifecycleService::issue()`, which sets the issue date, flips client
visibility, records `invoice.issued`, and enforces the service-period and
`invoice_kind` invariants in the [domain contract](../domain-contract.md).

Everything after that is derived. `refreshStatus()` recomputes the paid and
balance amounts from the invoice's payments and settles the status from the
result:

| derived from | status |
| --- | --- |
| paid ≥ total | `paid` |
| 0 < paid < total | `partially_paid` |
| paid = 0 | `issued` |
| the invoice was already void | `void` (payments never revive it) |

`partially_paid` is a real database status, not a UI-only badge — this schema
carries five invoice statuses where the predecessor's column had four, and code
ported from that world writes exhaustive four-element lists that silently omit
it. `InvoiceStatus` exists so the vocabulary and the questions asked of it live
in one place.

The transition is derived in both directions: reducing or refunding a payment so
that `paid < total` moves a `paid` invoice back to `partially_paid` or `issued`
on the next recomputation. There is no payment-deletion path; a payment is
withdrawn by transitioning it to `failed`, `canceled` or `refunded`.

**Two shapes may not be recomputed at all**, because deriving a status for them
would be an act rather than a reading:

- a **draft** carrying a payment. The derivation answers `issued` for any
  non-void invoice, so it would promote the draft straight past `issue()` — past
  the period and kind invariants, past the issue date, visibility and activity —
  into a status the emailer will send. `applyPayment()` refuses to attach a
  payment to a draft, so this shape arrives imported or hand-edited.
- an invoice whose **status this application does not recognise**. It would be
  silently normalised into one of the four outcomes above;
  `InvoiceStatus::isSettledValue()` and `hasChargedValue()` both read an unknown
  status as settled and charged precisely because code that cannot interpret a
  state must not act on it, and rewriting it is the strongest action available.

Both refuse inside the payment transaction, so the payment insert or update
rolls back and neither row is changed.

> `client_invoices.paid_on` exists in the schema but nothing in this application
> writes it. Documentation inherited from the predecessor describes a
> `paid_date` set to the latest payment date; treat the derived `paid_amount`
> and `balance_amount` above as the source of truth.

## Payment Table Display

The payments table on the invoice detail page shows the received date, status,
method, reference and amount. The only write it offers is **Correct date**,
described below; a row dated before the invoice's `issue_date` is marked, and
the table says once, underneath, what the marker means.

A payment the external finance system has reconciled - one with an active
allocation from `svc.finance.external_system` (`finance` by default), flagged
for review or not - carries a **Reconciled in finance** badge beside its status,
shown to workspace owners and admins only. The badge links to the finance
transaction at `SVC_FINANCE_TRANSACTION_URL`, a template in which
`{transaction_uuid}` is replaced by the transaction's UUID; a payment split
across several transactions gets one numbered link per transaction. Without a
template, or with one that is not an absolute http(s) address, the badge shows
without a link. Each row is anchored at `#payment-{payment-uuid}`, where the
payment's deep link lands (see [the finance API](../finance-api.md#deep-links)).

## Correcting a payment's date

`POST /workspaces/{workspace}/invoices/{clientInvoice}/payments/{clientInvoicePayment}/received-on`,
requiring `manage` on the workspace, a `received_on` and a `reason` (at most 500
characters), and reaching `InvoiceLifecycleService::correctPayment()` with the
date as its one field. The reason is required on the screen as it is by
`payments.correct` (#454), so every payment correction records the same audited
`invoice.payment_corrected` - before, after and reason - whichever door it came
through.

This is **not** a payment-edit path and does not open one. The request names one
field and the service writes one column: amount, currency, method, status and
refunded amount are unreachable through it, and what a payment is worth is still
corrected by transitioning its status or its refunded amount. What it does add
is a remedy for a mistyped day, which is not a money correction — it moves no
amount and cannot move an invoice's balance — and whose only previous remedy was
to cancel the payment and record it again, inventing a cancellation that never
happened.

The same bounds apply as on the way in, measured against the *invoice's*
workspace. The operation follows `setPaymentStatus()` and `setRefundedAmount()`:
it locks the payment row and then the invoice through it, in the order
`LockResource` declares, and records an `invoice.payment_date_corrected`
activity carrying the date it replaced. It deliberately does not recompute the
invoice — `received_on` is not an input to `refreshStatus()`, and that
recomputation refuses an invoice carrying any payment of an unreadable status,
so calling it would make correcting a date fail because of an unrelated row.

## Correcting a payment's descriptive fields

`InvoiceLifecycleService::correctPayment($payment, $changes, $reason,
$expectedVersion, $workspace)` is the one audited correction for a payment's
**descriptive** fields: `method`, `reference`, `notes` and `received_on`. They
describe money rather than being it - none is an input to `refreshStatus()`, the
credit pool or a reconciliation - so correcting one rewrites no financial fact.

- **A closed allow-list** (`PaymentCorrection::FIELDS`). Amount, currency,
  status, refunded amount, the invoice and processor or reconciliation fields
  are refused by name, each message naming the operation that does change what
  it represents. A column added later is refused until someone decides.
- **Bounded as on the way in.** `method` is required text of at most 40
  characters (its column, `ClientInvoicePayment::METHOD_MAX_LENGTH`, which the
  browser form, `payments.record` and a payment folded into `invoices.issue`
  all use, `PaymentMethodWidthTest`); `reference` (255) and `notes` (10,000) are nullable
  and a blank value clears them; `received_on` takes the same `YYYY-MM-DD`,
  not-in-the-future, two-years-back window on the invoice's workspace calendar.
- **A reason** is required, at most 500 characters.
- **An optional expected version** - the opaque `AgentApiVersion` of the row as
  the caller read it. Payments now carry `lock_version`, bumped by every
  Eloquent write, so a correction prepared against a stale read is refused with
  `PaymentVersionChanged` (a 409 to API callers).
- **Same locking and scoping as the date correction:** the payment row, then its
  invoice through a workspace-scoped locked query, in `LockResource` order.
  `refreshStatus()` is not called, for the reason given above.
- **No-op when nothing changes**; otherwise one `invoice.payment_corrected`
  activity with `changes` (a before/after of only the changed fields) and the
  `reason`, under a fresh occurrence. A note is recorded as a 120-character
  excerpt on each side, because the activity payload is capped at 10,000 bytes
  and a note may be that long by itself.

`setPaymentReceivedOn()` is this operation with one field and no reason. The
invoice screen's **Correct date** control used it before it asked for a reason;
it now asks, and calls `correctPayment()`. `setPaymentReceivedOn()` still records
`invoice.payment_date_corrected` with its `previous_received_on`/`received_on`
payload, so the activity feed and every earlier entry keep their meaning.

The console door is `svc:billing:correct-payment` and the API door is
`payments.correct`, over REST and MCP (see [the MCP guide](../mcp.md)); both
take the same four fields, and `payments.list` returns the note to the managers
who may write it.

## Withdrawing, failing or refunding a payment recorded by hand

A cheque that bounces, a transfer that is reversed, a payment entered twice and
money handed back to the client are each a change to what a recorded payment is
worth. They are made by transitioning the payment, never by editing it, so the
history says what happened (#455). `ManualPaymentChange` is the whole rule and
is unit tested without a database; `InvoiceLifecycleService::changeManualPayment()`
applies it under the payment and invoice locks, through the same write a
processor's events take (`setPaymentStatus()` / `setRefundedAmount()`).

**Only a payment recorded by hand.** A Stripe or Square payment follows its
processor, which reports its own failures, disputes and refunds; a change made
here would contradict the next event, so it is refused. `payments.list` marks a
hand-recorded payment `manual: true`.

**Status changes** (`payments.set_status`, `svc:billing:payment-status`) move a
payment to one of three statuses:

| from | → `succeeded` (it cleared) | → `failed` (it bounced or was reversed) | → `canceled` (recorded in error, withdrawn) |
| --- | --- | --- | --- |
| `pending` | yes | yes | yes |
| `succeeded`, nothing refunded | no change | yes | yes |
| `succeeded`, partly refunded | no change | no - refund the rest | no - refund the rest |
| `failed`, `canceled`, `refunded`, `disputed` | no | no (or no change) | no (or no change) |

`failed`, `canceled`, `refunded` and `disputed` are final for a hand-recorded
payment: money that arrives after all is recorded as a new payment, so both
facts stay in the history. `refunded` is reached only by refunding the whole
amount, `disputed` is a processor's word, and nothing returns to `pending`.
Setting the status a payment already has writes and records nothing.

**Refunds** (`payments.refund`, `svc:billing:refund-payment`) record money
already returned to the client outside SVC - SVC never sends money. `amount` is
what was returned now, in minor units, and the refunds of a payment may not
exceed its amount. Only a succeeded payment can be refunded; refunding all of it
makes it `refunded`, otherwise it stays `succeeded` with its `refunded_amount`
raised. A refund cannot be reduced: a refund entered in error is corrected by an
operator, not by this operation.

**Balances and issued invoices.** The invoice's paid amount, balance and status
are recomputed as for any payment change, so a paid invoice reopens: to
`partially_paid` or `issued` by the amount that stopped counting. The invoice
document is not changed, a void invoice stays void, and automatic delivery is
not rescheduled. If the client no longer owes the reopened balance, correct or
void the invoice separately - voiding still requires that nothing is paid and
nothing is pending. Moving a payment to `succeeded` is refused if successful
payments would exceed the invoice total, or on a void invoice. Leaving
`succeeded` changes the money the overpayment credit pool is derived from, so
the pool change is recorded under the company lock as a processor's refund is.

**Corrections and reconciliation.** Descriptive fields (method, reference, note,
date) stay correctable in every status, through `payments.correct`. Money that
stops counting cannot stay allocated to a finance transaction: failing,
withdrawing or refunding a payment beyond what its active reconciliation
allocations leave is refused, and the allocation is deactivated in the finance
system first. A processor's refund or dispute is not refused: it reports money
that has already moved, so it is recorded and the allocations are flagged for
review instead (see [the finance reconciliation API](../finance-api.md)).

**Reason and audit.** A reason (at most 500 characters) is required and kept in
the activity the transition records - `invoice.payment_received`,
`invoice.payment_failed`, `invoice.payment_canceled` or
`invoice.payment_refunded` - with the status it replaced (and, for a refund, the
previous refunded amount). The API requires the payment's `version` from
`payments.list` as `expected_version`, explicit confirmation and an idempotency
key; a payment that moved since it was read is refused with a conflict, and so
is a change whose transaction began from an older view of the invoice than the
one it locked (a payment recorded or changed on that invoice in the meantime).
The reconciliation capacity is read with a share lock, so an allocation
committed while the change waited is counted. The
console commands take `--expected-version`, and `--dry-run` runs the whole
change in a transaction that is always rolled back:

```
php artisan svc:billing:payment-status <payment> --workspace=<workspace> \
    --status=succeeded|failed|canceled --reason=<text> \
    [--expected-version=<version>] [--dry-run] [--format=json]
php artisan svc:billing:refund-payment <payment> --workspace=<workspace> \
    --amount=<minor-units> --reason=<text> \
    [--expected-version=<version>] [--dry-run] [--format=json]
```

## Payment Workflow

Recording a payment is the main write the screen offers, through **Record
payment** (`POST /workspaces/{workspace}/invoices/{clientInvoice}/payments`,
which requires `manage` on the workspace). Amount, currency and method are
required; status defaults to `succeeded`. Overpayment is refused at the service
boundary and surfaced as above. An `Idempotency-Key` header, or an
`idempotency_key` field, makes a retry safe.

Recording a payment is a five-field form: amount, method, reference, the date
the money arrived, and the invoice's own currency, which is not asked for.

`ReceivedPaymentData` is the one rule set for recording by hand: the browser
form, `payments.record` over REST and MCP, and a payment folded into
`invoices.issue` all parse through it, so a value one door refuses the others
refuse (#454). Its optional bookkeeping fields - `notes`, `status` and
`external_finance_transaction_uuid` - are accepted by the form and by
`payments.record` alike. `status` may be `succeeded` (the default) or `pending`:
`failed`, `canceled`, `refunded` and `disputed` are where a recorded payment
ends up, reached by transitioning it, so a row never appears already failed or
refunded with nothing in its history saying how.

There is no edit or delete of money. A payment's value is corrected by
transitioning its status or its refunded amount through
`InvoiceLifecycleService`, which recomputes the invoice and records the
corresponding activity — history is preserved rather than rewritten. A payment
recorded by hand is transitioned as described above. Its
descriptive fields are bookkeeping rather than money and have their own audited
correction, above. The console equivalent of recording one is:

```
php artisan svc:billing:payment <invoice> <minor-units> <currency> <method> \
    --workspace=<workspace> [--received-on=YYYY-MM-DD] [--reference=<text>] \
    [--notes=<text>] [--status=succeeded|pending] \
    [--external-finance-transaction=<uuid>] [--idempotency-key=<key>]
```

It parses through the same `ReceivedPaymentData` rules and records through the
same `RecordReceivedPayment` operation as the screen and `payments.record`, so
the three accept exactly the same payments (`PaymentRecordingStatusParityTest`).
`--status` is `succeeded` or `pending`: anything else is refused, and the refusal
points at `svc:billing:payment-status` (to mark a recorded payment failed or
canceled) and `svc:billing:refund-payment` (to record a refund), because those
states are reached by changing the payment, never by recording a new row.
A retry with the same idempotency key must name the same amount, currency,
method, status, finance transaction and (when given) date; anything else is a
different payment and is refused. `--received-on` is bounded by the same rule
the screens are: `YYYY-MM-DD`, not
in the future, not more than two years back, on the workspace's calendar.
