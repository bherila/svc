<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeEvent;
use App\Models\ClientStripePaymentMethod;
use App\Models\Workspace;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Event;

final class StripeWebhookService
{
    public function __construct(
        private readonly InvoiceLifecycleService $invoices,
        private readonly StripePaymentMethodService $paymentMethods,
        private readonly StripeGateway $gateway,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function process(Event $event, string $payload): bool
    {
        $data = $event->toArray();
        $object = is_array($data['data']['object'] ?? null) ? $data['data']['object'] : [];
        $payloadHash = hash('sha256', $payload);

        // The idempotency-ledger row must commit independently of business handling:
        // if it shared the business transaction, a domain failure would roll the row
        // back, leave no queryable failure record, and make Stripe retry the same
        // deterministic failure indefinitely.
        DB::table('client_stripe_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'stripe_event_id' => (string) $event->id,
            'event_type' => (string) $event->type,
            'object_id' => isset($object['id']) ? (string) $object['id'] : null,
            'payload_hash' => $payloadHash,
            'status' => 'received',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        $ledger = ClientStripeEvent::query()->where('stripe_event_id', $event->id)->firstOrFail();
        if (! hash_equals($ledger->payload_hash, $payloadHash)
            || $ledger->event_type !== (string) $event->type) {
            throw new \DomainException('A Stripe event ID was replayed with different contents.');
        }
        if (in_array($ledger->status, ['processed', 'failed'], true)) {
            // Processed events are benign replays; failed events were already recorded
            // and need operator intervention, not a retry of the same deterministic
            // failure. A 'received' row from an interrupted attempt falls through and
            // is processed again.
            return false;
        }

        try {
            return $this->handle($event, $object, $ledger);
        } catch (\DomainException $exception) {
            $ledger->forceFill([
                'status' => 'failed',
                'error_summary' => mb_substr($exception->getMessage(), 0, 1000),
                'processed_at' => $this->clock->now(),
            ])->save();

            throw $exception;
        }
    }

    /** @param array<string, mixed> $object */
    private function handle(Event $event, array $object, ClientStripeEvent $ledger): bool
    {
        return DB::transaction(function () use ($event, $object, $ledger): bool {
            $providerCreatedAt = max(0, (int) ($event->created ?? 0));
            if (in_array($event->type, ['payment_method.attached', 'setup_intent.succeeded'], true)) {
                $paymentMethod = $this->paymentMethodObject($event, $object);
                if ($paymentMethod === null) {
                    throw new \DomainException('The Stripe event does not identify a payment method.');
                }
                if (! is_string($paymentMethod['id'] ?? null) || $paymentMethod['id'] === ''
                    || ! is_string($paymentMethod['customer'] ?? null) || $paymentMethod['customer'] === '') {
                    throw new \DomainException('The Stripe payment method does not identify its customer.');
                }
                $method = $this->paymentMethods->attach($paymentMethod, (string) $event->id, $providerCreatedAt);
                $ledger->workspace_id = $method instanceof ClientStripePaymentMethod && $method->workspace_id > 0
                    ? $method->workspace_id
                    : null;
            } elseif ($event->type === 'payment_method.detached') {
                $providerId = is_string($object['id'] ?? null) ? $object['id'] : '';
                if ($providerId === '') {
                    throw new \DomainException('The Stripe event does not identify a detached payment method.');
                }
                $workspaceId = $this->paymentMethods->detach($providerId, (string) $event->id, $providerCreatedAt);
                $ledger->workspace_id = is_int($workspaceId) && $workspaceId > 0 ? $workspaceId : null;
            } elseif ($event->type === 'customer.updated') {
                $customerId = is_string($object['id'] ?? null) ? $object['id'] : '';
                $settings = is_array($object['invoice_settings'] ?? null) ? $object['invoice_settings'] : [];
                $default = $settings['default_payment_method'] ?? null;
                $defaultId = is_string($default) && $default !== ''
                    ? $default
                    : (is_array($default) && is_string($default['id'] ?? null) ? $default['id'] : null);
                if ($customerId === '') {
                    throw new \DomainException('The Stripe event does not identify an updated customer.');
                }
                $workspaceId = $this->paymentMethods->changeDefault(
                    $customerId,
                    $defaultId,
                    (string) $event->id,
                    $providerCreatedAt,
                );
                $ledger->workspace_id = is_int($workspaceId) && $workspaceId > 0 ? $workspaceId : null;
            } else {
                $this->handleInvoiceEvent($event, $object, $ledger);
            }

            $ledger->forceFill(['status' => 'processed', 'processed_at' => $this->clock->now()])->save();

            return true;
        });
    }

    /** @param array<string, mixed> $object */
    private function handleInvoiceEvent(Event $event, array $object, ClientStripeEvent $ledger): void
    {
        $payment = $this->findPayment($object);
        $providerCreatedAt = max(0, (int) ($event->created ?? 0));
        $status = match ((string) $event->type) {
            'payment_intent.succeeded' => 'succeeded',
            'payment_intent.processing' => 'pending',
            'payment_intent.payment_failed' => 'failed',
            'payment_intent.canceled' => 'canceled',
            'charge.dispute.created' => 'disputed',
            'charge.dispute.closed' => ($object['status'] ?? null) === 'won' ? 'succeeded' : 'disputed',
            default => null,
        };

        if ($event->type === 'charge.refunded' && $payment !== null) {
            if ($this->isOlderPaymentEvent($payment, $providerCreatedAt, 'refunded')) {
                $ledger->workspace_id = $payment->workspace_id;

                return;
            }
            $currency = strtoupper((string) ($object['currency'] ?? ''));
            $refundedAmount = (int) ($object['amount_refunded'] ?? 0);
            if ($currency !== $payment->currency) {
                throw new \DomainException('Stripe refund currency does not match the invoice payment.');
            }
            $this->invoices->setRefundedAmount($payment, $refundedAmount);
            $this->recordPaymentEvent($payment, $providerCreatedAt, (string) $event->id);
            $ledger->workspace_id = $payment->workspace_id;
        } elseif ($status !== null && $payment !== null) {
            if ($this->isOlderPaymentEvent($payment, $providerCreatedAt, $status)) {
                $ledger->workspace_id = $payment->workspace_id;

                return;
            }
            if ($status === 'succeeded') {
                $amount = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);
                $currency = strtoupper((string) ($object['currency'] ?? ''));
                if ($amount !== (int) $payment->amount || $currency !== $payment->currency) {
                    throw new \DomainException('Stripe payment does not match the pending invoice payment.');
                }
            }
            if (! $this->isStalePaymentTransition($payment->status, $status)) {
                $this->invoices->setPaymentStatus($payment, $status);
                $this->recordPaymentEvent($payment, $providerCreatedAt, (string) $event->id);
            }
            $ledger->workspace_id = $payment->workspace_id;
        } elseif ($event->type === 'payment_intent.succeeded') {
            $this->createSuccessfulPayment($event, $object, $ledger);
        }
    }

    private function isOlderPaymentEvent(
        ClientInvoicePayment $payment,
        int $providerCreatedAt,
        string $nextStatus,
    ): bool {
        $lastCreatedAt = $payment->provider_event_created_at;
        if ($lastCreatedAt === null) {
            return false;
        }
        if ($lastCreatedAt > $providerCreatedAt) {
            return true;
        }

        // Stripe event timestamps have one-second precision. Within a tied
        // second, keep a failure rather than returning it to processing.
        return $lastCreatedAt === $providerCreatedAt
            && $payment->status === 'failed'
            && $nextStatus === 'pending';
    }

    private function recordPaymentEvent(
        ClientInvoicePayment $payment,
        int $providerCreatedAt,
        string $eventId,
    ): void {
        ClientInvoicePayment::query()
            ->where('workspace_id', $payment->workspace_id)
            ->whereKey($payment->id)
            ->update([
                'provider_event_created_at' => max(0, $providerCreatedAt),
                'provider_event_id' => $eventId,
                'updated_at' => $this->clock->now(),
            ]);
    }

    private function isStalePaymentTransition(string $current, string $next): bool
    {
        return match ($current) {
            'succeeded' => in_array($next, ['pending', 'failed'], true),
            'disputed' => in_array($next, ['pending', 'failed'], true),
            'refunded' => $next !== 'refunded',
            'canceled' => $next !== 'canceled',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function paymentMethodObject(Event $event, array $object): ?array
    {
        if ($event->type === 'payment_method.attached') {
            return $object;
        }

        $paymentMethod = $object['payment_method'] ?? null;
        if (is_array($paymentMethod)) {
            return $paymentMethod;
        }
        if (! is_string($paymentMethod) || $paymentMethod === '') {
            return null;
        }

        return $this->gateway->client()->paymentMethods->retrieve($paymentMethod, [])->toArray();
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
            ->lockForUpdate()
            ->first();
    }

    /** @param array<string, mixed> $object */
    private function createSuccessfulPayment(Event $event, array $object, ClientStripeEvent $ledger): void
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $invoiceId = $metadata['invoice_public_id'] ?? null;
        $workspaceId = $metadata['workspace_public_id'] ?? null;
        if (! is_string($invoiceId) || $invoiceId === ''
            || ! is_string($workspaceId) || $workspaceId === '') {
            return;
        }
        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        if ($workspace === null) {
            return;
        }
        $invoice = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $invoiceId)
            ->first();
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
            'received_on' => $this->clock->today($workspace)->toDateString(),
            'method' => 'stripe',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_identifier' => (string) ($object['id'] ?? ''),
        ]);
        $this->recordPaymentEvent($payment, max(0, (int) ($event->created ?? 0)), (string) $event->id);
        $ledger->workspace_id = $payment->workspace_id;
    }
}
