<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * What decided whether a portal contact may approve hours and expenses: a
 * manager's explicit grant or denial, or - with none - the default, which is
 * the client's billing contact ({@see ApprovalPermission}).
 */
enum ApprovalBasis: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case BillingContact = 'billing_contact';
    case NotBillingContact = 'not_billing_contact';

    public function allows(): bool
    {
        return match ($this) {
            self::Granted, self::BillingContact => true,
            self::Denied, self::NotBillingContact => false,
        };
    }

    /** The effective state, as the settings page prints it. */
    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Can approve (set by a manager)',
            self::Denied => 'Cannot approve (set by a manager)',
            self::BillingContact => 'Billing contact (default)',
            self::NotBillingContact => 'Not the billing contact (default)',
        };
    }
}
