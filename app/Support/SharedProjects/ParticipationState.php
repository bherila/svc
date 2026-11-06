<?php

namespace App\Support\SharedProjects;

use DateTimeInterface;

/** A participation is active until the host revokes it or the participant leaves. */
enum ParticipationState: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Left = 'left';

    public static function of(?DateTimeInterface $revokedAt, ?DateTimeInterface $leftAt): self
    {
        return match (true) {
            $revokedAt !== null => self::Revoked,
            $leftAt !== null => self::Left,
            default => self::Active,
        };
    }

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
