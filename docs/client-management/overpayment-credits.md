# Overpayment Credits

## What it does

When a client pays more than an invoice's remaining balance, the extra amount is **not** rejected. Instead it is kept on file as a credit and automatically applied to the next draft invoice(s) for that company. Credits never expire.

Typical reasons this happens in practice:

- Rounding at the bank (wire fees, FX conversion).
- Pre-payment: a client sends $5,000 against a $3,000 invoice expecting it to cover the next month's retainer too.
- Catch-up transfer intentionally slightly high.

Before this change, the API returned HTTP 422 if `payment > remaining_balance`. That validation is now removed.

## Data model

No new columns on `client_invoice_payments` or `client_invoices`.

Credit state is **derived** from existing tables at read time, so we never have to reconcile a stale balance:

```
available_credit = Σ max(0, total_payments − invoice_total)   (non-void invoices)
                   − Σ |credit line_total|                    (credit lines on invoices in status ∈ {issued, paid})
```

Only credits on `issued` or `paid` invoices count as "consumed", because drafts are regenerated freely.

## Service

`App\Services\ClientManagement\OverpaymentCreditService` owns all credit math:

- `availableCreditForCompany(ClientCompany $company): float` — number described above, clamped ≥ 0.
- `applyCreditsToDraftInvoice(ClientInvoice $invoice): void` — called by `ClientInvoicingService` after milestones and before `recalculateTotal()`. Creates/replaces a single `credit`-typed line on the draft, capped at the invoice's pre-credit subtotal (an invoice never goes negative from a credit; leftover credit rolls to the next).
- `buildLedger(ClientCompany $company): OverpaymentLedger` — itemised per-invoice view for the UI (how much was overpaid on each source invoice, how much has been consumed, how much remains).

## Credit line on the invoice

When applied, a single line is added:

| field | value |
| --- | --- |
| `line_type` | `credit` |
| `description` | `Credit from prior overpayments` |
| `quantity` | `1` |
| `unit_price` | `-<applied>` |
| `line_total` | `-<applied>` |

Credits sit after expenses and milestones in the invoice rendering. The admin and portal invoice pages display a **Credit applied** summary row when `credit_applied > 0`, and an **Available credit** tile when `available_credit_after > 0`.

## Invoice serialization

`ClientInvoice::toDetailedArray()` exposes three new fields:

- `credit_applied` (float) — absolute value of the credit lines on this invoice.
- `overpaid_amount` (float) — `max(0, payments_total - invoice_total)`.
- `available_credit_after` (float) — snapshot of company-wide credit after this invoice is accounted for.

The Zod schemas in `resources/js/client-management/types/invoice.ts` accept these as optional.

## Payment lifecycle

The existing Paid ↔ Issued transition rules still apply:

- `total_payments >= invoice_total` → invoice marks **paid** (with `paid_date = latest payment date`). Overpayments qualify.
- Deleting or reducing a payment so `total_payments < invoice_total` → invoice reverts to **issued**, `paid_date` cleared.

`remaining_balance` can go negative; the UI surfaces it as *"Overpaid by $X"* on the issued/paid source invoice and offers an **Available credit** chip linking to the consumer invoice(s).

## Voiding a source invoice

If you void an overpaid invoice, that overpayment no longer counts as available credit. Any draft invoice that had consumed it is regenerated in the normal draft-regeneration flow, which drops the credit line.

## Tests

`tests/Feature/ClientManagement/OverpaymentCreditTest.php` covers:

- Overpayment is now accepted (no more 422).
- Excess becomes a credit line on the next draft, capped at invoice total.
- Partial consumption carries remainder to the following invoice.
- Multiple overpayments compose linearly.
- Voiding the source invoice removes its contribution from the ledger.
- Issued/paid invoices containing a credit line are never modified by later credit changes.

Unit tests for the ledger math live in `tests/Unit/ClientManagement/OverpaymentLedgerTest.php`.
