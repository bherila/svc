<?php

namespace App\Support\Billing\Reminders;

use Carbon\CarbonImmutable;

/**
 * Everything the reminder policy decides from, loaded once and then read
 * without a database (#438).
 *
 * Dates are calendar days in the workspace's time zone; `$now` is the instant
 * in that zone, for the sending window and retry times.
 */
final readonly class PaymentReminderFacts
{
    /**
     * @param  list<string>  $recipients
     * @param  list<PaymentReminderClaim>  $claims  the automatic steps already claimed
     */
    public function __construct(
        public string $invoiceStatus,
        public int $balanceAmount,
        public ?CarbonImmutable $issueDate,
        public ?CarbonImmutable $dueDate,
        public CarbonImmutable $now,
        public array $recipients,
        public ?SquareReminderFacts $square,
        public ?string $portalUrl,
        public ?CarbonImmutable $cadenceEnabledAt,
        public array $claims,
        public bool $inFlight,
    ) {}

    public function today(): CarbonImmutable
    {
        return $this->now->startOfDay();
    }

    public function claimFor(PaymentReminderStep $step): ?PaymentReminderClaim
    {
        foreach ($this->claims as $claim) {
            if ($claim->step === $step) {
                return $claim;
            }
        }

        return null;
    }
}
