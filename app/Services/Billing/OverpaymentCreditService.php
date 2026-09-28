<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Services\Billing\Balances\OverpaymentLedger;
use App\Support\Billing\CreditPoolChanged;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\InvoiceStatus;
use App\Support\Concurrency\Locks;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
     * Lock a company's credit pool for spending, and refuse if this
     * transaction's snapshot of it is stale.
     *
     * The company row lock serialises every writer that can shrink the pool,
     * but the ledger is built from ordinary reads, and under REPEATABLE READ an
     * ordinary read returns the snapshot fixed by the transaction's first one -
     * which a lock taken later does not refresh. So the lock alone let a
     * transaction wait for a competing issue to commit, take the lock, and
     * still read the credit as unspent.
     *
     * `credit_revision` is advanced by every such writer under this same lock
     * ({@see self::recordPoolChange()}). Read here twice: through the lock,
     * which is a current read, and through an ordinary read, which is the
     * snapshot - establishing it now if the transaction has none yet, in which
     * case the two agree by construction. Equal, and no pool-shrinking write
     * has committed since the snapshot, so the ordinary reads the ledger is
     * built from are as current as the lock. Different, and nothing here can
     * refresh the snapshot, so the spend is refused rather than guessed.
     *
     * Call it after the invoice lock and before anything is read through the
     * snapshot that the ledger depends on; `issue()` takes it before its own
     * first ordinary read, so a transaction it owns never refuses.
     */
    public function lockForSpending(int $workspaceId, int $companyId): ?ClientCompany
    {
        $company = ClientCompany::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($companyId)
            ->tap(Locks::forUpdate())
            ->first();
        if (! $company instanceof ClientCompany) {
            return null;
        }

        $snapshot = DB::table('client_companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId)
            ->value('credit_revision');
        if (! is_int($snapshot) && ! (is_string($snapshot) && ctype_digit($snapshot))) {
            throw new RuntimeException('The company\'s credit revision could not be read.');
        }
        $snapshot = (int) $snapshot;
        $current = $company->credit_revision;
        if ($snapshot !== $current) {
            throw new CreditPoolChanged($snapshot, $current);
        }

        return $company;
    }

    /**
     * Record that this transaction changed what a company's credit pool holds.
     *
     * Every writer that can shrink the pool - spending credit at issue, a
     * payment leaving `succeeded`, a refund growing - calls this in the same
     * transaction as the change, after the locks it takes that rank before the
     * company. Taking the company lock here (a no-op if the caller already
     * holds it) is what makes a concurrent {@see self::lockForSpending()} wait
     * for this transaction and then see the new revision.
     *
     * A builder write on purpose: it reaches no model hook, so it moves no
     * agent-facing version and touches nothing but the counter.
     */
    public function recordPoolChange(int $workspaceId, int $companyId): void
    {
        ClientCompany::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($companyId)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
        DB::table('client_companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId)
            ->update(['credit_revision' => DB::raw('credit_revision + 1')]);
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
            ->whereIn('status', InvoiceStatus::live())
            ->with(['payments' => function ($payments) use ($company): void {
                $payments->where('workspace_id', $company->workspace_id);
            }])
            ->get();
        $hasForeignPayments = ClientInvoicePayment::query()
            ->whereIn('client_invoice_id', $invoices->modelKeys())
            ->where(fn (Builder $payments): Builder => $payments
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', '!=', $company->workspace_id))
            ->exists();
        if ($hasForeignPayments) {
            throw new RuntimeException('An invoice in the credit ledger contains a payment owned by another workspace.');
        }
        // Credit is derived by a positive filter over settled payments, so a
        // row whose status this application cannot read contributes nothing and
        // the overpayment it may represent silently disappears - the client is
        // shown less credit than they paid for, and the difference is never
        // reported anywhere. A `DomainException` rather than the integrity
        // failure above, because this one is operator-correctable: classify the
        // payment and the ledger builds.
        // Over the eager load, which is already narrowed to this company's
        // workspace, and after the foreign-payment check above has established
        // that no row belongs to another one. Asked in PHP rather than as a
        // `whereNotIn`, because the connection collates case-insensitively and
        // SQL would clear the rows this exists to catch - see
        // {@see ClientInvoicePayment::hasUnreadableStatus()}.
        $unreadablePayment = $invoices
            ->flatMap(fn (ClientInvoice $invoice): iterable => $invoice->payments)
            ->first(fn (ClientInvoicePayment $payment): bool => $payment->hasUnreadableStatus());
        if ($unreadablePayment !== null) {
            throw new DomainException(
                'Payment '.(string) $unreadablePayment->public_id.' carries the unrecognised status "'
                .(string) $unreadablePayment->status.'", so how much this client has overpaid cannot be '
                .'established and no credit can be derived. Classify or correct that payment status first.'
            );
        }
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
                ->where('status', InvoicePaymentStatus::Succeeded->value)
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
        $invoice->assertLineOwnership();

        $company = $invoice->clientCompany;
        if (! $company instanceof ClientCompany
            || (int) $company->workspace_id !== (int) $invoice->workspace_id) {
            throw new RuntimeException('The draft invoice does not belong to an available client company in its workspace.');
        }

        // Remove any stale credit lines from a previous regeneration pass.
        ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where('type', InvoiceLineType::Credit->value)
            ->delete();

        // The stored totals were reduced by the credit line just deleted, and
        // issue() trusts them. Recalculate on every path out of here, not only
        // the one that writes a replacement.
        $available = $this->availableCreditForCompany($company, (string) $invoice->currency);
        if ($available <= 0.0) {
            $this->recalculateTotals($invoice);

            return;
        }

        // Recompute the draft's pre-credit subtotal from line items (after the
        // stale credit was deleted above). We never take an invoice negative
        // — any excess credit stays in the pool for the next draft.
        $subtotal = ((int) ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->sum('total_amount')) / 100;
        $applied = round(min($available, max(0.0, $subtotal)), 2);
        if ($applied <= 0.0) {
            $this->recalculateTotals($invoice);

            return;
        }

        $maxSortOrder = (int) (ClientInvoiceLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->max('sort_order') ?? 0);

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
            ->where('client_invoice_lines.workspace_id', $company->workspace_id)
            ->whereIn('client_invoices.status', InvoiceStatus::charged())
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
        $invoice->recalculateTotals();
    }
}
