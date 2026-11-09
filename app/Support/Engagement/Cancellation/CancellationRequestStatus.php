<?php

namespace App\Support\Engagement\Cancellation;

/** Where a company contact's request to end an agreement stands (CAN-3, CAN-4). */
enum CancellationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending => true,
            self::Approved, self::Declined, self::Withdrawn => false,
        };
    }
}
