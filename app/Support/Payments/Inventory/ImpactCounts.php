<?php

namespace App\Support\Payments\Inventory;

/** What one workspace has that rides on the environment credentials. */
final readonly class ImpactCounts
{
    public function __construct(
        /** Open invoices a client could pay online right now. For Square, those linked to a Square invoice. */
        public int $payableInvoices = 0,
        /** Client companies with autopay switched on (Stripe only). */
        public int $autopayCompanies = 0,
        /** Cards on file (Stripe only; Square keeps its own). */
        public int $savedPaymentMethods = 0,
        /** Client companies linked to a Square customer (Square only). */
        public int $linkedCustomers = 0,
        /** Payments recorded as pending at the processor. */
        public int $pendingPayments = 0,
        /** Autopay attempts held for review (Stripe only). */
        public int $heldAutopayAttempts = 0,
        /** Webhook events the shared endpoint delivered to this workspace in the last 90 days. */
        public int $recentWebhookEvents = 0,
    ) {}

    public function isEmpty(): bool
    {
        return array_sum($this->toArray()) === 0;
    }

    /** @return array{payable_invoices: int, autopay_companies: int, saved_payment_methods: int, linked_customers: int, pending_payments: int, held_autopay_attempts: int, recent_webhook_events: int} */
    public function toArray(): array
    {
        return [
            'payable_invoices' => $this->payableInvoices,
            'autopay_companies' => $this->autopayCompanies,
            'saved_payment_methods' => $this->savedPaymentMethods,
            'linked_customers' => $this->linkedCustomers,
            'pending_payments' => $this->pendingPayments,
            'held_autopay_attempts' => $this->heldAutopayAttempts,
            'recent_webhook_events' => $this->recentWebhookEvents,
        ];
    }
}
