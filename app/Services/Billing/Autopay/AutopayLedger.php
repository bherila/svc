<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enters an autopay PaymentIntent in the ordinary payment ledger (#636), for
 * the charge path and for an operator closing out a held attempt (#641): the
 * same row either way, so Stripe's webhook settles it exactly as it settles a
 * portal payment.
 */
final class AutopayLedger
{
    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function record(Workspace $workspace, ClientInvoice $invoice, InvoiceAutopayAttempt $attempt, string $intentId, int $amount, InvoicePaymentStatus $status): void
    {
        try {
            // The webhook can win the race and create the payment from the
            // intent's metadata; then there is already a row to leave alone.
            $exists = $invoice->payments()
                ->where('workspace_id', $workspace->id)
                ->where('provider', 'stripe')
                ->where('provider_payment_identifier', $intentId)
                ->exists();
            if ($exists) {
                return;
            }
            $this->invoices->applyPayment($invoice, [
                'amount' => $amount,
                'currency' => $invoice->currency,
                'received_on' => $this->clock->today($workspace)->toDateString(),
                'method' => 'stripe',
                'status' => $status->value,
                'provider' => 'stripe',
                'provider_payment_identifier' => $intentId,
                'idempotency_key' => 'autopay:'.$attempt->public_id,
            ], $workspace);
        } catch (Throwable $failure) {
            // The attempt already says what happened; Stripe's webhook records
            // a success from the intent's metadata whether or not this did.
            Log::warning('An autopay payment could not be entered in the ledger yet.', ['attempt' => $attempt->public_id, 'error' => $failure->getMessage()]);
        }
    }
}
