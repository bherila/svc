<?php

namespace App\Services\Square;

use App\Exceptions\CollectionBelongsToSquare;
use App\Models\ClientInvoice;

/**
 * Square collects what Square bills (#438).
 *
 * An invoice is *Square-collected* when it is actively linked to a Square
 * invoice, or when the agreement it was generated under carries the
 * billed-in-Square marker. SVC remains the system of record for such an
 * invoice - it may still be generated and issued, so balances and revenue stay
 * complete and the Square payment is recorded against it - but SVC must never
 * collect it: Square already emails it, charges the card on file and hosts the
 * payment page, and a second request for the same money is the double billing
 * this exists to stop.
 *
 * Every client-facing collection path asks this guard, under the invoice lock
 * where the path holds one. {@see SITES} names each of them;
 * `SquareCollectionGuardSitesTest` proves each refuses and that no other code
 * reaches a collection primitive.
 *
 * Recording a payment is not collection. Neither is issuing, generating, or
 * reading - those stay open.
 *
 * A payment reminder is the one client-facing path that does not refuse
 * outright: the owner decided (#438) that a reminder about an invoice Square
 * collects carries Square's own payment page. So its site asks this guard to
 * choose the pay link, and a Square-collected invoice gets Square's page or no
 * reminder at all - never SVC's own collection.
 */
final class SquareCollectionGuard
{
    /**
     * The guarded entry points, by the method that asks. Every web, REST, MCP,
     * console and scheduled path into client collection funnels through one of
     * these.
     *
     * @var array<string, string>
     */
    public const array SITES = [
        'App\Services\Billing\InvoiceEmailService::assertSendable' => 'manual email delivery: website send, invoices.send over REST and MCP, and a keyed retry of a failed send',
        'App\Services\Billing\InvoiceEmailService::sendAutomatic' => 'automatic email delivery claimed by svc:billing:dispatch-invoice-emails, run every minute by the scheduler',
        'App\Services\Billing\InvoiceEmailService::deliver' => 'the last check before any client email leaves, outside every transaction, after its claim has committed',
        'App\Services\Billing\InvoiceEmailService::deliverRegistered' => 'an email registered inside an API transaction and sent after it commits',
        'App\Services\Billing\InvoiceLifecycleService::issue' => 'scheduling automatic email delivery when an invoice is issued',
        'App\Services\Billing\InvoiceCorrectionService::setHold' => 'releasing a held automatic delivery back onto the schedule, on the website and invoices.release_delivery',
        'App\Services\Billing\StripePaymentIntentService::createLocked' => 'the Stripe payment intent behind the client\'s SVC payment link and charge',
        'App\Services\Billing\Reminders\PaymentReminderRepository::squareFacts' => 'payment reminders, by hand on the website, invoices.send_reminder over REST and MCP, and svc:billing:send-payment-reminders: an invoice Square collects carries only Square\'s own payment page, and gets no reminder without one',
    ];

    public function __construct(private readonly SquareLinkRepository $links) {}

    /** Why SVC may not collect this invoice, or null when it may. */
    public function refusal(ClientInvoice $invoice): ?string
    {
        $link = $this->links->activeLink($invoice);
        if ($link !== null) {
            return sprintf(
                'Invoice %s is linked to Square invoice %s, so Square collects it: SVC does not email it to the client, charge it or offer a payment link for it. Unlink it from Square to collect it in SVC.',
                (string) $invoice->invoice_number,
                $link->square_invoice_number,
            );
        }

        $series = $this->links->agreementSeriesKey($invoice);
        if ($series !== null) {
            return sprintf(
                'Invoice %s belongs to an agreement billed in Square (series %s), so Square collects it: SVC does not email it to the client, charge it or offer a payment link for it. Clear the agreement\'s Square marker to collect it in SVC.',
                (string) $invoice->invoice_number,
                $series,
            );
        }

        return null;
    }

    public function isSquareCollected(ClientInvoice $invoice): bool
    {
        return $this->refusal($invoice) !== null;
    }

    /** @throws CollectionBelongsToSquare */
    public function assertSvcMayCollect(ClientInvoice $invoice): void
    {
        $refusal = $this->refusal($invoice);
        if ($refusal !== null) {
            throw new CollectionBelongsToSquare($refusal);
        }
    }
}
