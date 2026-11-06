<?php

declare(strict_types=1);

namespace App\Support\Review;

use DateTimeInterface;

/** A grant stands until the workspace that gave it revokes it. There is no expiry and no other way out. */
enum ReviewerGrantState: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public static function of(?DateTimeInterface $revokedAt): self
    {
        return $revokedAt === null ? self::Active : self::Revoked;
    }

    public function reaches(): bool
    {
        return match ($this) {
            self::Active => true,
            self::Revoked => false,
        };
    }
}
