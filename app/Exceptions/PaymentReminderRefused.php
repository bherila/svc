<?php

namespace App\Exceptions;

use App\Support\Billing\Reminders\PaymentReminderRefusal;
use DomainException;

/**
 * A payment reminder was not sent (#438). A `DomainException`, so the website
 * reports it as a validation message, the API as a 422 and MCP as a tool
 * error; {@see PaymentReminderInFlight} is the retryable 409.
 */
class PaymentReminderRefused extends DomainException
{
    public function __construct(public readonly PaymentReminderRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }

    public static function because(PaymentReminderRefusal $refusal): self
    {
        return $refusal->isRetryableConflict() ? new PaymentReminderInFlight($refusal) : new self($refusal);
    }
}
