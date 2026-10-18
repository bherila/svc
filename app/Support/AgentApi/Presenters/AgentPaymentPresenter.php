<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Support\AgentApi\AgentApiVersion;
use LogicException;

/**
 * One received payment, as every reader sees it (#454).
 *
 * `payments.list`, the payment-mutation responses and the operator's invoice
 * screen all render a payment through this, so the three cannot drift into
 * different shapes. The screen adds its own capability URLs beside it; it never
 * reshapes these fields.
 *
 * An explicit allow-list. Processor identifiers and the reconciliation
 * allocations stay out entirely. The reference, the operator's private note and
 * the finance transaction it was recorded against are a workspace manager's to
 * read: the person who can record and correct
 * them is the one who has to be able to check them, and a portal reader or a
 * member who cannot manage billing sees null.
 */
final class AgentPaymentPresenter
{
    /** The columns {@see self::present()} reads; a query selecting fewer cannot be presented. */
    public const array COLUMNS = ['id', 'public_id', 'workspace_id', 'client_invoice_id', 'amount', 'refunded_amount', 'currency', 'received_on', 'method', 'reference', 'notes', 'external_finance_transaction_uuid', 'status', 'provider', 'lock_version'];

    /**
     * @param  ClientInvoice  $invoice  the payment's own invoice, already resolved inside the workspace
     * @return array{id: string, invoice_id: string, amount: int, refunded_amount: int, currency: string, received_on: string|null, method: string|null, reference: string|null, notes: string|null, external_finance_transaction_uuid: string|null, status: string, manual: bool, version: string}
     */
    public function present(ClientInvoicePayment $payment, ClientInvoice $invoice, bool $manager): array
    {
        if ((int) $payment->client_invoice_id !== (int) $invoice->id || (int) $payment->workspace_id !== (int) $invoice->workspace_id) {
            throw new LogicException('A payment is presented with its own invoice.');
        }

        return [
            'id' => (string) $payment->public_id,
            'invoice_id' => (string) $invoice->public_id,
            'amount' => (int) $payment->amount,
            'refunded_amount' => (int) $payment->refunded_amount,
            'currency' => (string) $payment->currency,
            'received_on' => $payment->received_on?->toDateString(),
            'method' => $payment->method,
            'reference' => $manager ? $payment->reference : null,
            'notes' => $manager ? $payment->notes : null,
            // The finance system's own transaction this payment was recorded
            // against, when the operator named one. Only the pointer: the
            // reconciliation allocations stay with the finance API.
            'external_finance_transaction_uuid' => $manager ? $payment->external_finance_transaction_uuid : null,
            'status' => (string) $payment->status,
            // Recorded by hand rather than by a payment processor: only these
            // can be moved by payments.set_status and payments.refund, because
            // a processor's payment follows the processor.
            'manual' => $payment->provider === null,
            // The opaque revision every payment write checks. Any write to the
            // row moves it, so a change made against a stale read is refused.
            'version' => AgentApiVersion::for($payment),
        ];
    }
}
