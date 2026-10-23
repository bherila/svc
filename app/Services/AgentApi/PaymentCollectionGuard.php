<?php

namespace App\Services\AgentApi;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Services\Billing\BillingMaintenance;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Square\SquareCollectionGuard;
use App\Services\Square\SquareLinkRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Concurrency\LockResource;
use App\Support\Concurrency\Locks;
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
 * lock instead, and moves no invoice, so it is read under a share lock on the
 * agreement taken before the invoice's ({@see self::holdAgreement()}).
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

    public function __construct(
        private readonly SquareCollectionGuard $square,
        private readonly SquareLinkRepository $links,
    ) {}

    /**
     * Read the invoice's agreement and its billed-in-Square marker under a
     * share lock, before the invoice is locked - the order {@see LockResource}
     * declares (agreement, then invoice; billing maintenance before both, so
     * that is taken first). A locking read is a current one, so a marker
     * committed after this transaction's snapshot is seen; and while it is
     * held, `SquareLinkService::markAgreement()`, which locks the agreement
     * exclusively, waits for this payment to commit or roll back.
     *
     * The marker cannot be made to move the invoice instead: setting it would
     * then lock the agreement's invoices after the share-locked read of their
     * Stripe payments it already makes, and a payment's status change locks the
     * payment, then its invoice.
     *
     * @return array{agreement_id: int|null, series_key: string|null}
     */
    public function holdAgreement(ClientInvoice $invoice): array
    {
        $agreementId = $invoice->client_agreement_id === null ? null : (int) $invoice->client_agreement_id;
        if ($agreementId === null) {
            return ['agreement_id' => null, 'series_key' => null];
        }
        BillingMaintenance::share((int) $invoice->workspace_id);
        $agreement = ClientAgreement::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($agreementId)
            ->tap(Locks::forShare())
            ->first(['id', 'workspace_id', 'square_series_key']);
        $series = $agreement?->getAttribute('square_series_key');

        return ['agreement_id' => $agreementId, 'series_key' => is_string($series) ? $series : null];
    }

    /**
     * @param  array{agreement_id: int|null, series_key: string|null}  $agreement  what {@see self::holdAgreement()} read before the lock
     *
     * @throws HttpException 409 when the invoice moved after this transaction's snapshot
     * @throws DomainException when a payment may not be recorded by hand
     */
    public function assertMayRecord(ClientInvoice $locked, array $agreement): void
    {
        $seen = ClientInvoice::query()->where('workspace_id', $locked->workspace_id)->whereKey($locked->id)->firstOrFail(['id', 'workspace_id', 'lock_version']);
        abort_unless(AgentApiVersion::matches($seen, AgentApiVersion::for($locked)), 409, 'The invoice changed while this payment waited for it; read it and retry.');
        $lockedAgreement = $locked->client_agreement_id === null ? null : (int) $locked->client_agreement_id;
        abort_unless($lockedAgreement === $agreement['agreement_id'], 409, 'The invoice changed while this payment waited for it; read it and retry.');

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
        // The link is written under the invoice lock and advances the
        // invoice's revision, so the snapshot read is current once the
        // revision check above has passed; the marker is the one read under
        // the agreement's share lock.
        if ($this->links->activeLink($locked) !== null || $agreement['series_key'] !== null) {
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

    /**
     * The invoice's payments that may still be in flight, counted from just
     * their status and provider, streamed rather than hydrated: an invoice's
     * whole payment history is never loaded as models. Not grouped in SQL,
     * where a case-insensitive collation would fold `SUCCEEDED` into
     * `succeeded` before PHP could tell them apart.
     */
    private function inFlightPayments(ClientInvoice $invoice): int
    {
        $count = 0;
        foreach (ClientInvoicePayment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_invoice_id', $invoice->id)
            ->toBase()
            ->select(['status', 'provider'])
            ->orderBy('id')
            ->cursor() as $payment) {
            if (self::inFlight($payment->status ?? null, $payment->provider ?? null)) {
                $count++;
            }
        }

        return $count;
    }
}
