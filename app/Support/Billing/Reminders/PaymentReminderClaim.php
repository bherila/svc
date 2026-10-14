<?php

namespace App\Support\Billing\Reminders;

use Carbon\CarbonImmutable;

/** An automatic step already claimed for an invoice, as its reminder row stands. */
final readonly class PaymentReminderClaim
{
    public function __construct(
        public PaymentReminderStep $step,
        public PaymentReminderStatus $status,
        public int $attemptNumber,
        public ?CarbonImmutable $nextAttemptAt,
    ) {}
}
