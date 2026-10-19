<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Services\Square\SquareCollectionGuard;
use App\Support\Billing\InvoicePaymentStatus;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether `payments.record` may record money against an invoice (G6).
 *
 * Recording by hand is for money that reached the operator by some route SVC
 * did not see - a bank transfer, a cheque. Two states mean the money may
 * already be, or be about to be, on the invoice by another route, and a
 * second record of it would double the receipt:
 *
 * - a payment still in flight: `pending` (an uncleared cheque recorded by
 *   hand, a Stripe bank debit still processing), or a status SVC cannot read,
 *   which cannot be shown not to be in flight;
 * - an invoice Square collects ({@see SquareCollectionGuard}), whose payments
 *   Square's own sync records.
 *
 * The refusal is asked while `payments.record` holds the invoice row locked,
 * so a payment or a Square link committed after the caller's read is seen:
 * every payment and every invoice-to-Square link is written under that same
 * lock. An agreement's billed-in-Square marker is written under the
 * agreement's lock instead, and is read as it stands once the invoice is
 * held. A completed receipt replays before the question is asked, so a retry
 * of a payment recorded earlier still answers with that payment.
 *
 * `invoices.get` reports the same two facts ({@see self::summary()}) so a
 * caller can tell, before it tries, why a payment would be refused.
 *
 * Recording a payment is not collection in {@see SquareCollectionGuard}'s
 * sense, and this is not one of its sites: the website's payment form,
 * `invoices.issue` with a payment, the console and the processors' webhooks
 * record as they always have.
 */
final class PaymentCollectionGuard
{
    public const string OWNER_SVC = 'svc';

    public const string OWNER_SQUARE = 'square';

    public function __construct(private readonly SquareCollectionGuard $square) {}

    /** @throws DomainException when a payment may not be recorded by hand */
    public function assertMayRecord(ClientInvoice $locked): void
    {
        $pending = $this->pendingPayments($locked);
        if ($pending > 0) {
            throw new DomainException(sprintf(
                'Invoice %s has %d payment%s still in flight (pending), so recording another could count the same money twice. Settle %s with payments.set_status, or let the processor finish, then record.',
                (string) $locked->invoice_number,
                $pending,
                $pending === 1 ? '' : 's',
                $pending === 1 ? 'it' : 'them',
            ));
        }
        if ($this->square->isSquareCollected($locked)) {
            throw new DomainException(sprintf(
                'Invoice %s is collected through Square, which records its own payments, so SVC does not record one by hand. Unlink it from Square, or clear its agreement\'s Square marker, to record a payment in SVC.',
                (string) $locked->invoice_number,
            ));
        }
    }

    /**
     * What `invoices.get` reports for an owner/admin.
     *
     * @return array{pending_payments: int, collection_owner: string}
     */
    public function summary(ClientInvoice $invoice): array
    {
        return [
            'pending_payments' => $this->pendingPayments($invoice),
            'collection_owner' => $this->square->isSquareCollected($invoice) ? self::OWNER_SQUARE : self::OWNER_SVC,
        ];
    }

    /** Payments on the invoice that may still be in flight: pending, or a status SVC cannot read. */
    private function pendingPayments(ClientInvoice $invoice): int
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->where(static fn (Builder $status) => $status
                ->where('status', InvoicePaymentStatus::Pending->value)
                ->orWhereNotIn('status', InvoicePaymentStatus::all())
                ->orWhereNull('status'))
            ->count();
    }
}
