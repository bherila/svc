<?php

namespace App\Support\Billing\Reminders;

use Carbon\CarbonImmutable;

/**
 * The automatic reminder cadence (#438): three days before the due date, on
 * it, then a week and two weeks after. Each step is sent at most once per
 * invoice; the step is the durable claim's key.
 */
enum PaymentReminderStep: string
{
    case BeforeDue = 'due_minus_3';
    case OnDue = 'due';
    case WeekOverdue = 'due_plus_7';
    case TwoWeeksOverdue = 'due_plus_14';

    /** Calendar days from the due date, in the workspace's calendar. */
    public function offsetDays(): int
    {
        return match ($this) {
            self::BeforeDue => -3,
            self::OnDue => 0,
            self::WeekOverdue => 7,
            self::TwoWeeksOverdue => 14,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BeforeDue => '3 days before the due date',
            self::OnDue => 'On the due date',
            self::WeekOverdue => '7 days overdue',
            self::TwoWeeksOverdue => '14 days overdue',
        };
    }

    /** The day this step falls due for an invoice due on `$dueDate`. */
    public function scheduledOn(CarbonImmutable $dueDate): CarbonImmutable
    {
        return $dueDate->startOfDay()->addDays($this->offsetDays());
    }

    /**
     * The cadence in the order its steps fall due, which is the order the
     * cases are declared in; `PaymentReminderPolicyTest` pins it.
     *
     * @return list<self>
     */
    public static function cadence(): array
    {
        return self::cases();
    }
}
