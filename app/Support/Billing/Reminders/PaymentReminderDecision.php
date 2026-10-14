<?php

namespace App\Support\Billing\Reminders;

/** Send a reminder carrying this pay link, or do not, and why (#438). */
final readonly class PaymentReminderDecision
{
    private function __construct(
        public ?PaymentReminderRefusal $refusal,
        public PaymentReminderPayLink $payLink,
        public ?string $payUrl,
        public ?string $squareInvoiceNumber,
        public ?PaymentReminderStep $step,
        public bool $retry,
    ) {}

    public static function refuse(PaymentReminderRefusal $refusal): self
    {
        return new self($refusal, PaymentReminderPayLink::None, null, null, null, false);
    }

    public static function send(PaymentReminderPayLink $payLink, ?string $payUrl, ?string $squareInvoiceNumber): self
    {
        return new self(null, $payLink, $payUrl, $squareInvoiceNumber, null, false);
    }

    public function atStep(PaymentReminderStep $step, bool $retry): self
    {
        return $this->refusal !== null ? $this : new self(null, $this->payLink, $this->payUrl, $this->squareInvoiceNumber, $step, $retry);
    }

    public function sends(): bool
    {
        return $this->refusal === null;
    }
}
