<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Services\Billing\Balances\OverpaymentLedger;
use App\Support\Billing\InvoiceLineType;

/**
 * Tracks overpayment-derived credits for a client company and applies them
 * as credit lines on the next draft invoice.
 *
 * See docs/client-management/overpayment-credits.md for semantics and
 * invariants.
 */
class OverpaymentCreditService
{
    /**
     * Total credit currently available for a company, in dollars.
     *
     * available_credit =
     *     Σ max(0, total_payments − invoice_total)   (non-void invoices)
     *   − Σ |credit line_total|                      (on issued/paid invoices)
     *
     * Drafts don't count as "consumed" since they regenerate freely.
     */
    public function availableCreditForCompany(ClientCompany $company, string $currency): float
    {
        $ledger = $this->buildLedger($company, $currency);

        return $ledger->totalRemaining;
    }

    /**
     * Itemised view of overpayment credits for UI + debugging.
     */
    public function buildLedger(ClientCompany $company, string $currency): OverpaymentLedger
    {
        // Credit never crosses currencies. A pool built from every invoice would
        // let a USD overpayment be subtracted numerically from a EUR invoice.
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('currency', $currency)
            ->whereNotIn('status', ['void'])
            ->with('payments')
            ->get();
        // The engine reasons in whole currency units; this schema stores minor
        // units. Convert at the boundary, never inside the arithmetic.

        $totalConsumed = $this->totalConsumed($company, $currency);
        $totalOverpaid = 0.0;

        /** @var list<array{invoice_id: int, invoice_number: string|null, overpaid: float, consumed: float, remaining: float}> $entries */
        $entries = [];

        foreach ($invoices as $invoice) {
            // Only settled money, net of refunds. A pending, failed, disputed or
            // refunded payment is not collected cash and must not become credit
            // the client can spend.
            $settled = $invoice->payments
                ->where('status', 'succeeded')
                ->sum(fn ($payment): int => (int) $payment->amount - (int) $payment->refunded_amount);
            $paymentsTotal = ((int) $settled) / 100;
            $invoiceTotal = ((int) $invoice->total_amount) / 100;
            $overpaid = round(max(0.0, $paymentsTotal - $invoiceTotal), 2);
            if ($overpaid <= 0.0) {
                continue;
            }
            $totalOverpaid += $overpaid;
            $entries[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'overpaid' => $overpaid,
                'consumed' => 0.0, // Filled in below (FIFO).
                'remaining' => $overpaid,
            ];
        }

        // Distribute consumed amount against overpaid invoices FIFO by invoice id.
        usort($entries, fn (array $a, array $b): int => $a['invoice_id'] <=> $b['invoice_id']);
        $remainingToDistribute = $totalConsumed;
        foreach ($entries as $i => $entry) {
            if ($remainingToDistribute <= 0.0) {
                break;
            }
            $consume = min($entry['remaining'], $remainingToDistribute);
            $entries[$i]['consumed'] = round($consume, 2);
            $entries[$i]['remaining'] = round($entry['remaining'] - $consume, 2);
            $remainingToDistribute -= $consume;
        }

        $totalRemaining = round(max(0.0, $totalOverpaid - $totalConsumed), 2);

        return new OverpaymentLedger(
            entries: $entries,
            totalRemaining: $totalRemaining,
        );
    }

    /**
     * Apply available credit to a draft invoice (replaces any existing
     * credit line from the last generation pass).
     *
     * Never takes the invoice below $0 — any unused credit rolls forward.
     */
    public function applyCreditsToDraftInvoice(ClientInvoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            return;
        }

        $company = $invoice->clientCompany;
        if (! $company) {
            return;
        }

        // Remove any stale credit lines from a previous regeneration pass.
        $invoice->lines()->where('type', InvoiceLineType::Credit->value)->delete();

        $available = $this->availableCreditForCompany($company, (string) $invoice->currency);
        if ($available <= 0.0) {
            return;
        }

        // Recompute the draft's pre-credit subtotal from line items (after the
        // stale credit was deleted above). We never take an invoice negative
        // — any excess credit stays in the pool for the next draft.
        $subtotal = ((int) $invoice->lines()->sum('total_amount')) / 100;
        $applied = round(min($available, max(0.0, $subtotal)), 2);
        if ($applied <= 0.0) {
            return;
        }

        $maxSortOrder = (int) ($invoice->lines()->max('sort_order') ?? 0);

        $appliedMinor = (int) round($applied * 100);

        ClientInvoiceLine::create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $invoice->client_agreement_id,
            'description' => 'Credit from prior overpayments',
            'type' => InvoiceLineType::Credit->value,
            'quantity' => '1',
            'hours' => null,
            'unit_amount' => -$appliedMinor,
            'tax_amount' => 0,
            'total_amount' => -$appliedMinor,
            'line_date' => $invoice->service_period_end,
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->recalculateTotals($invoice);
    }

    /**
     * Sum of absolute credit amounts on all non-draft, non-void invoices for a
     * company. Only these count as "consumed" because drafts regenerate freely.
     */
    protected function totalConsumed(ClientCompany $company, string $currency): float
    {
        $sum = (int) ClientInvoiceLine::query()
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
            ->where('client_invoices.workspace_id', $company->workspace_id)
            ->where('client_invoices.client_company_id', $company->id)
            ->where('client_invoices.currency', $currency)
            ->whereIn('client_invoices.status', ['issued', 'partially_paid', 'paid'])
            ->where('client_invoice_lines.type', InvoiceLineType::Credit->value)
            ->sum('client_invoice_lines.total_amount');

        return round(abs($sum) / 100, 2);
    }

    /**
     * Re-sum the invoice from its lines after a credit line is added or removed.
     *
     * The predecessor had this on the model; here totals live with the
     * lifecycle service, so the credit path re-derives them the same way.
     */
    protected function recalculateTotals(ClientInvoice $invoice): void
    {
        $subtotal = (int) $invoice->lines()->sum('total_amount');
        $tax = (int) $invoice->lines()->sum('tax_amount');
        $total = $subtotal + $tax;
        $paid = (int) $invoice->paid_amount;

        $invoice->forceFill([
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'balance_amount' => max(0, $total - $paid),
        ])->save();
    }
}
