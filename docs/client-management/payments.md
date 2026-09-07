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

## Correcting a payment's date

`POST /workspaces/{workspace}/invoices/{clientInvoice}/payments/{clientInvoicePayment}/received-on`,
requiring `manage` on the workspace, and reaching
`InvoiceLifecycleService::setPaymentReceivedOn()`.

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

## Payment Workflow

Recording a payment is the main write the screen offers, through **Record
payment** (`POST /workspaces/{workspace}/invoices/{clientInvoice}/payments`,
which requires `manage` on the workspace). Amount, currency and method are
required; status defaults to `succeeded`. Overpayment is refused at the service
boundary and surfaced as above. An `Idempotency-Key` header, or an
`idempotency_key` field, makes a retry safe.

Recording a payment is a five-field form: amount, method, reference, the date
the money arrived, and the invoice's own currency, which is not asked for.

There is no edit or delete. A payment is corrected by transitioning its status
or its refunded amount through `InvoiceLifecycleService`, which recomputes the
invoice and records the corresponding activity — history is preserved rather
than rewritten. The one exception is its date, which is a bookkeeping
correction rather than a money one and has its own narrow path above. The console equivalent of recording one is:

```
php artisan svc:billing:payment <invoice> <minor-units> <currency> <method> \
    --workspace=<workspace> [--status=succeeded] [--idempotency-key=<key>]
```

`--status` accepts only the six values above; anything else is refused rather
than stored, and `--received-on` is bounded by the same rule the screens are:
`YYYY-MM-DD`, not in the future, not more than two years back, on the
workspace's calendar.
