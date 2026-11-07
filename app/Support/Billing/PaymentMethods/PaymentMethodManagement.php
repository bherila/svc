<?php

declare(strict_types=1);

namespace App\Support\Billing\PaymentMethods;

/**
 * What a viewer of the portal Billing page may do to the company's saved
 * payment methods, decided from facts the caller has already read. Pure and
 * database-free.
 *
 * Changing a method means calling the workspace's own Stripe account, so every
 * change needs its connection; adding one also needs the publishable key of
 * that same account for the browser to mount Stripe's form. Fail closed:
 * unknown means not offered.
 */
enum PaymentMethodManagement
{
    /** May add, make default and remove. */
    case Full;
    /** May make default and remove, but the browser has no key to mount Stripe's form. */
    case ChangeOnly;
    /** The viewer is not a contact who may speak for the company's money. */
    case NotPermitted;
    /** The provider's Stripe connection cannot take payments right now. */
    case Unavailable;

    public static function decide(bool $viewerMay, bool $stripeConnected, ?string $publishableKey): self
    {
        if (! $viewerMay) {
            return self::NotPermitted;
        }
        if (! $stripeConnected) {
            return self::Unavailable;
        }

        return $publishableKey !== null && $publishableKey !== '' ? self::Full : self::ChangeOnly;
    }

    public function mayAdd(): bool
    {
        return match ($this) {
            self::Full => true,
            self::ChangeOnly, self::NotPermitted, self::Unavailable => false,
        };
    }

    public function mayChange(): bool
    {
        return match ($this) {
            self::Full, self::ChangeOnly => true,
            self::NotPermitted, self::Unavailable => false,
        };
    }

    /** The sentence shown where the controls would be, or null when they are. */
    public function notice(string $providerName): ?string
    {
        return match ($this) {
            self::Full => null,
            self::ChangeOnly => $providerName.' cannot take new payment methods right now. You can still remove one or choose another default.',
            self::NotPermitted => 'Only a contact with access to the whole company can add, remove or change payment methods.',
            self::Unavailable => $providerName.' is not set up to take payments right now, so payment methods cannot be added or changed.',
        };
    }
}
