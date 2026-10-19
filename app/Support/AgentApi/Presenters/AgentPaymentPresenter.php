<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\ManualPaymentChange;
use Illuminate\Database\Eloquent\Collection;
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
     * The bounded eager load {@see self::present()} needs beside the payment:
     * its refund history, inside the payment's workspace.
     *
     * @return array{refunds: \Closure}
     */
    public static function with(Workspace $workspace): array
    {
        return ['refunds' => static fn ($refunds) => $refunds->where('workspace_id', $workspace->id)
            ->select(['id', 'public_id', 'workspace_id', 'client_invoice_payment_id', 'kind', 'amount', 'reason', 'reverses_refund_id', 'created_at'])
            ->orderBy('id')
            // Writes stop at this many, so the limit never truncates; it keeps
            // the read bounded however a row arrived.
            ->limit(ManualPaymentChange::MAX_REFUND_ENTRIES)];
    }

    /**
     * @param  ClientInvoice  $invoice  the payment's own invoice, already resolved inside the workspace
     * @return array{id: string, invoice_id: string, amount: int, refunded_amount: int, currency: string, received_on: string|null, method: string|null, reference: string|null, notes: string|null, external_finance_transaction_uuid: string|null, status: string, manual: bool, refunds: list<array{id: string, kind: string, amount: int, reason: string|null, reverses_refund_id: string|null, reversed_by_id: string|null, recorded_at: string}>, version: string}
     */
    public function present(ClientInvoicePayment $payment, ClientInvoice $invoice, bool $manager): array
    {
        if ((int) $payment->client_invoice_id !== (int) $invoice->id || (int) $payment->workspace_id !== (int) $invoice->workspace_id) {
            throw new LogicException('A payment is presented with its own invoice.');
        }
        if (! $payment->relationLoaded('refunds')) {
            throw new LogicException('A payment is presented with its refunds loaded (AgentPaymentPresenter::with()).');
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
            // A hand-recorded payment's refunds and their reversals (#472),
            // oldest first. A processor's refunds are its report of a total,
            // so a processor payment has none here.
            'refunds' => $this->refunds($payment, $manager),
            // The opaque revision every payment write checks. Any write to the
            // row moves it, so a change made against a stale read is refused.
            'version' => AgentApiVersion::for($payment),
        ];
    }

    /** @return list<array{id: string, kind: string, amount: int, reason: string|null, reverses_refund_id: string|null, reversed_by_id: string|null, recorded_at: string}> */
    private function refunds(ClientInvoicePayment $payment, bool $manager): array
    {
        /** @var Collection<int, ClientInvoicePaymentRefund> $entries */
        $entries = $payment->getRelation('refunds');
        $publicIds = $entries->pluck('public_id', 'id');
        $reversedBy = $entries->whereNotNull('reverses_refund_id')->pluck('public_id', 'reverses_refund_id');

        return array_values($entries->map(static fn (ClientInvoicePaymentRefund $entry): array => [
            'id' => (string) $entry->public_id,
            'kind' => $entry->kind->value,
            'amount' => (int) $entry->amount,
            // Why, as the operator recorded it: a manager's to read, as the
            // note is.
            'reason' => $manager ? $entry->reason : null,
            'reverses_refund_id' => $entry->reverses_refund_id === null ? null : (string) $publicIds->get($entry->reverses_refund_id),
            'reversed_by_id' => $reversedBy->has($entry->id) ? (string) $reversedBy->get($entry->id) : null,
            'recorded_at' => $entry->created_at->utc()->toIso8601ZuluString(),
        ])->all());
    }
}
