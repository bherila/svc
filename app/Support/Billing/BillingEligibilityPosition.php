<?php

namespace App\Support\Billing;

/** A committed order, or an event visible only in the current transaction. */
final readonly class BillingEligibilityPosition
{
    public function __construct(public int $id, public ?int $sequence) {}

    public function after(self $earlier): bool
    {
        if ($this->sequence === null && $earlier->sequence === null) {
            return $this->id > $earlier->id;
        }
        if ($this->sequence === null) {
            return true;
        }
        if ($earlier->sequence === null) {
            return false;
        }

        return $this->sequence > $earlier->sequence;
    }
}
