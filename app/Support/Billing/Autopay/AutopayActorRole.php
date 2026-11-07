<?php

namespace App\Support\Billing\Autopay;

/** Which side of the relationship made a change. */
enum AutopayActorRole: string
{
    /** A contact of the client company, in the portal. */
    case Client = 'client';
    /** An owner or admin of the provider workspace. */
    case Provider = 'provider';
    /** SVC itself, when the default method was removed. */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'client',
            self::Provider => 'provider',
            self::System => 'SVC',
        };
    }
}
