<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Event;

final class StripeWebhookService
{
    public function __construct(private readonly InvoiceLifecycleService $invoices) {}

    public function process(Event $event, string $payload): bool
    {
        return DB::transaction(function () use ($event, $payload): bool {
            $data = $event->toArray();
            $object = is_array($data['data']['object'] ?? null) ? $data['data']['object'] : [];
            $payloadHash = hash('sha256', $payload);
            $inserted = DB::table('client_stripe_events')->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'stripe_event_id' => (string) $event->id,
                'event_type' => (string) $event->type,
                'object_id' => isset($object['id']) ? (string) $object['id'] : null,
                'payload_hash' => $payloadHash,
                'status' => 'received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ledger = ClientStripeEvent::query()->where('stripe_event_id', $event->id)->firstOrFail();
            if ($inserted === 0) {
                if (! hash_equals($ledger->payload_hash, $payloadHash)
                    || $ledger->event_type !== (string) $event->type) {
                    throw new \DomainException('A Stripe event ID was replayed with different contents.');
                }

                return false;
            }

            $payment = $this->findPayment($object);
            $status = match ((string) $event->type) {
                'payment_intent.succeeded' => 'succeeded',
                'payment_intent.payment_failed' => 'failed',
                'charge.dispute.created' => 'disputed',
                'charge.dispute.closed' => ($object['status'] ?? null) === 'won' ? 'succeeded' : 'disputed',
                default => null,
            };

            if ($event->type === 'charge.refunded' && $payment !== null) {
                $currency = strtoupper((string) ($object['currency'] ?? ''));
                $refundedAmount = (int) ($object['amount_refunded'] ?? 0);
                if ($currency !== $payment->currency) {
                    throw new \DomainException('Stripe refund currency does not match the invoice payment.');
                }
                $this->invoices->setRefundedAmount($payment, $refundedAmount);
                $ledger->workspace_id = $payment->workspace_id;
            } elseif ($status !== null && $payment !== null) {
                if ($status === 'succeeded') {
                    $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
                    $currency = strtoupper((string) ($object['currency'] ?? ''));
                    if ($amount !== (int) $payment->amount || $currency !== $payment->currency) {
                        throw new \DomainException('Stripe payment does not match the pending invoice payment.');
                    }
                }
                $this->invoices->setPaymentStatus($payment, $status);
                $ledger->workspace_id = $payment->workspace_id;
            } elseif ($event->type === 'payment_intent.succeeded') {
                $this->createSuccessfulPayment($object, $ledger);
            }

            $ledger->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

            return true;
        });
    }

    /** @param array<string, mixed> $object */
    private function findPayment(array $object): ?ClientInvoicePayment
    {
        $ids = array_values(array_filter([
            isset($object['id']) ? (string) $object['id'] : null,
            isset($object['payment_intent']) && is_string($object['payment_intent']) ? $object['payment_intent'] : null,
        ]));

        return ClientInvoicePayment::query()
            ->where('provider', 'stripe')
            ->whereIn('provider_payment_identifier', $ids)
            ->first();
    }

    /** @param array<string, mixed> $object */
    private function createSuccessfulPayment(array $object, ClientStripeEvent $ledger): void
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $invoiceId = $metadata['invoice_public_id'] ?? null;
        if (! is_string($invoiceId) || $invoiceId === '') {
            return;
        }
        $invoice = ClientInvoice::query()->where('public_id', $invoiceId)->first();
        if ($invoice === null) {
            return;
        }
        $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        if ($amount <= 0 || $currency !== $invoice->currency) {
            return;
        }

        $payment = $this->invoices->applyPayment($invoice, [
            'amount' => $amount,
            'currency' => $currency,
            'received_on' => now()->toDateString(),
            'method' => 'stripe',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_identifier' => (string) ($object['id'] ?? ''),
        ]);
        $ledger->workspace_id = $payment->workspace_id;
    }
}
