<?php

namespace App\Support\Packages;

/** Where a sign-up request stands. Only `new` can be decided; decided requests are closed. */
enum PackageRequestStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Declined = 'declined';

    public function isClosed(): bool
    {
        return match ($this) {
            self::New => false,
            self::Approved, self::Declined => true,
        };
    }
}
