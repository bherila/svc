<?php

namespace App\Services\Billing;

use App\Models\ClientInvoicePayment;
use App\Models\ClientInvoicePaymentRefund;
use App\Models\User;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Billing\RecordedRefund;
use App\Support\Concurrency\Locks;
use LogicException;

/**
 * The tenant-scoped boundary to a hand-recorded payment's refund history
 * (#472). Every read and write names the payment's workspace and the payment,
 * and is made while {@see InvoiceLifecycleService} holds the payment's row
 * lock, which is what serialises the history: two reversals of one refund
 * cannot both read it unanswered. The unique `reverses_refund_id` is the
 * schema's own refusal behind that.
 */
final class PaymentRefundLedger
{
    /**
     * The refund or reversal `$publicId` of this payment, with what answers it.
     *
     * Locking reads, so under MariaDB's REPEATABLE READ they see the entries a
     * transaction that held the payment lock before this one committed, not
     * this transaction's older snapshot.
     */
    public function find(ClientInvoicePayment $payment, string $publicId): ?RecordedRefund
    {
        $row = ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $payment->workspace_id)
            ->where('client_invoice_payment_id', $payment->id)
            ->where('public_id', $publicId)
            ->tap(Locks::forShare())
            ->first(['id', 'public_id', 'kind', 'amount']);
        if ($row === null) {
            return null;
        }
        $reversedBy = ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $payment->workspace_id)
            ->where('client_invoice_payment_id', $payment->id)
            ->where('reverses_refund_id', $row->id)
            ->tap(Locks::forShare())
            ->value('public_id');

        return new RecordedRefund((string) $row->public_id, $row->kind, (int) $row->amount, is_string($reversedBy) ? $reversedBy : null);
    }

    /**
     * The refunds of this payment nothing has reversed yet, oldest first.
     *
     * @return list<string>
     */
    public function unreversed(ClientInvoicePayment $payment): array
    {
        return array_values(ClientInvoicePaymentRefund::query()
            ->where('workspace_id', $payment->workspace_id)
            ->where('client_invoice_payment_id', $payment->id)
            ->where('kind', PaymentRefundKind::Refund->value)
            ->whereNotIn('id', ClientInvoicePaymentRefund::query()
                ->where('workspace_id', $payment->workspace_id)
                ->where('client_invoice_payment_id', $payment->id)
                ->whereNotNull('reverses_refund_id')
                ->select('reverses_refund_id'))
            ->orderBy('id')
            ->get(['public_id'])
            ->map(static fn (ClientInvoicePaymentRefund $refund): string => $refund->public_id)
            ->all());
    }

    /**
     * Write one row. `$reverses` is the public id of the refund a reversal
     * answers, already found on this payment.
     */
    public function record(
        ClientInvoicePayment $payment,
        string $publicId,
        PaymentRefundKind $kind,
        int $amount,
        string $reason,
        ?string $reverses,
        ?User $actor,
    ): ClientInvoicePaymentRefund {
        $reversesId = match ($kind) {
            PaymentRefundKind::Refund => $reverses === null ? null : throw new LogicException('A refund reverses nothing.'),
            PaymentRefundKind::Reversal => ClientInvoicePaymentRefund::query()
                ->where('workspace_id', $payment->workspace_id)
                ->where('client_invoice_payment_id', $payment->id)
                ->where('kind', PaymentRefundKind::Refund->value)
                ->where('public_id', $reverses ?? throw new LogicException('A reversal names the refund it reverses.'))
                ->value('id') ?? throw new LogicException('A reversal names a refund of the same payment.'),
        };

        $row = new ClientInvoicePaymentRefund([
            'workspace_id' => $payment->workspace_id,
            'client_invoice_payment_id' => $payment->id,
            'kind' => $kind,
            'amount' => $amount,
            'reason' => $reason,
            'reverses_refund_id' => $reversesId,
            'recorded_by_user_id' => $actor?->id,
        ]);
        $row->public_id = $publicId;
        $row->save();

        return $row;
    }
}
