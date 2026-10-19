<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Square\SquareCollectionGuard;
use App\Services\Square\SquareLinkRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Concurrency\LockResource;
use DomainException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Whether `payments.record` may record money against an invoice (G6).
 *
 * Recording by hand is for money that reached the operator by some route SVC
 * did not see - a bank transfer, a cheque. Two states mean the money may
 * already be, or be about to be, on the invoice by another route, and a
 * second record of it would double the receipt:
 *
 * - a payment still in flight ({@see self::inFlight()}): `pending` (an
 *   uncleared cheque recorded by hand, a Stripe bank debit still processing),
 *   a Stripe payment stored as `failed`, whose intent returns to
 *   `requires_payment_method` and can still be confirmed
 *   ({@see SquareLinkRepository::STRIPE_IN_FLIGHT}), or a status SVC cannot
 *   read, which cannot be shown not to be in flight;
 * - an invoice Square collects ({@see SquareCollectionGuard}), whose payments
 *   Square's own sync records.
 *
 * Statuses are classified here, in PHP, with the enum's exact parser rather
 * than in SQL: under MariaDB's case-insensitive collation `NOT IN` would read
 * an unreadable `SUCCEEDED` as `succeeded` and let it through.
 *
 * The refusal is asked while `payments.record` holds the invoice row locked.
 * The payment and Square-link rows are read plainly - a share lock on them
 * would take the payment rows after the invoice, against the order
 * {@see LockResource} declares (a payment's status change locks the payment,
 * then its invoice), and could deadlock with it. Under MariaDB's REPEATABLE
 * READ a plain read answers from the transaction's snapshot, which can
 * predate a payment or link committed while this request waited for the
 * lock. Every such write advances the invoice's revision (recording any
 * payment, {@see InvoiceLifecycleService::applyPayment()}; linking to Square),
 * so the snapshot's revision is compared with the locked row's first, and a
 * difference is a 409 to retry rather than a count taken over a stale view.
 * An agreement's billed-in-Square marker is written under the agreement's
 * lock instead, and is read as it stands once the invoice is held.
 *
 * A completed receipt replays before any of this is asked, so a retry of a
 * payment recorded earlier still answers with that payment.
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

    /**
     * @throws HttpException 409 when the invoice moved after this transaction's snapshot
     * @throws DomainException when a payment may not be recorded by hand
     */
    public function assertMayRecord(ClientInvoice $locked): void
    {
        $seen = ClientInvoice::query()->where('workspace_id', $locked->workspace_id)->whereKey($locked->id)->firstOrFail(['id', 'workspace_id', 'lock_version']);
        abort_unless(AgentApiVersion::matches($seen, AgentApiVersion::for($locked)), 409, 'The invoice changed while this payment waited for it; read it and retry.');

        $pending = $this->inFlightPayments($locked);
        if ($pending > 0) {
            throw new DomainException(sprintf(
                'Invoice %s has %d payment%s still in flight (pending, or a Stripe payment that can still be retried), so recording another could count the same money twice. Settle %s with payments.set_status, or let the processor finish, then record.',
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
            'pending_payments' => $this->inFlightPayments($invoice),
            'collection_owner' => $this->square->isSquareCollected($invoice) ? self::OWNER_SQUARE : self::OWNER_SVC,
        ];
    }

    /**
     * Whether a payment with this stored status and provider may still bring
     * money onto the invoice by a route SVC does not control.
     *
     * The status is parsed exactly: anything the enum does not read - another
     * spelling, another case, null - counts as in flight.
     */
    public static function inFlight(mixed $status, mixed $provider): bool
    {
        $parsed = is_string($status) ? InvoicePaymentStatus::tryFrom($status) : null;
        if ($parsed === null) {
            return true;
        }
        if ($provider === self::STRIPE && in_array($parsed->value, SquareLinkRepository::STRIPE_IN_FLIGHT, true)) {
            return true;
        }

        return match ($parsed) {
            InvoicePaymentStatus::Pending => true,
            InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Refunded,
            InvoicePaymentStatus::Disputed, InvoicePaymentStatus::Canceled => false,
        };
    }

    /** The provider name Stripe payments are stored under, as {@see SquareLinkRepository} reads it. */
    private const string STRIPE = 'stripe';

    private function inFlightPayments(ClientInvoice $invoice): int
    {
        return ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->get(['status', 'provider'])
            ->filter(static fn (ClientInvoicePayment $payment): bool => self::inFlight($payment->getAttribute('status'), $payment->getAttribute('provider')))
            ->count();
    }
}
