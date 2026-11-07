<?php

namespace App\Support\Billing\Autopay;

/** What a consent record changed. */
enum AutopayConsentAction: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case LimitChanged = 'limit_changed';

    public function label(): string
    {
        return match ($this) {
            self::Enabled => 'Turned on',
            self::Disabled => 'Turned off',
            self::LimitChanged => 'Limit changed',
        };
    }
}
