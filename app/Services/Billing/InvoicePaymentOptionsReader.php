<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Square\Mirror\SquareInvoicePage;
use App\Services\Square\SquareCollectionGuard;
use App\Support\Billing\Document\DocumentMode;
use App\Support\Billing\Document\InvoiceDocumentNotices;
use App\Support\Billing\InvoicePaymentOptions;

/**
 * Reads the facts behind {@see InvoicePaymentOptions} for one invoice of one
 * workspace: the workspace's own Stripe connection, Square's hosted page for a
 * linked invoice, and whether Square collects it. Every read names the
 * workspace the invoice belongs to.
 */
final class InvoicePaymentOptionsReader
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentConnectionResolver $connections,
        private readonly SquareInvoicePage $square,
        private readonly SquareCollectionGuard $collection,
    ) {}

    /**
     * @return array{stripe: array{intent_href: string, publishable_key: string}|null, square: array{href: string}|null}
     */
    public function for(Workspace $workspace, ClientInvoice $invoice): array
    {
        $payable = (int) $invoice->workspace_id === (int) $workspace->id
            && InvoiceDocumentNotices::offersPayLink(
                DocumentMode::Invoice,
                (string) $invoice->status,
                (int) $invoice->balance_amount,
                (bool) $invoice->is_visible_to_client,
            );
        if (! $payable) {
            return InvoicePaymentOptions::compose(false, null, false, false, false, null, '');
        }

        return InvoicePaymentOptions::compose(
            true,
            $this->square->forInvoice($workspace, $invoice)['url'] ?? null,
            $this->collection->isSquareCollected($invoice),
            $this->stripe->access($workspace) !== null,
            $this->connections->stripeServedByEnvironment($workspace),
            $this->stripe->publishableKey(),
            route('svc.billing.invoices.stripe-payment-intent', [$workspace, $invoice], absolute: false),
        );
    }
}
