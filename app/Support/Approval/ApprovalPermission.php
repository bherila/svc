<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * Who in a client's portal may approve hours and expenses.
 *
 * A grant on the contact's portal membership decides it when someone made
 * one, in either direction. With none, the default applies: the contact whose
 * address is the client's billing address.
 */
final class ApprovalPermission
{
    public static function allows(?bool $granted, bool $isBillingContact): bool
    {
        return $granted ?? $isBillingContact;
    }

    /**
     * Why this contact may or may not approve: the explanation
     * {@see allows()} reached its answer by, for the settings page and API to
     * present. It never decides anything itself.
     */
    public static function basis(?bool $granted, bool $isBillingContact): ApprovalBasis
    {
        return match ($granted) {
            true => ApprovalBasis::Granted,
            false => ApprovalBasis::Denied,
            null => $isBillingContact ? ApprovalBasis::BillingContact : ApprovalBasis::NotBillingContact,
        };
    }

    /**
     * Is this address one of the client's billing addresses?
     *
     * The billing field may hold several, separated by commas, semicolons or
     * whitespace. Compared without regard to case or surrounding space; an
     * empty address matches nothing.
     */
    public static function isBillingContact(string $email, ?string $billingEmail): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $billingEmail === null) {
            return false;
        }

        foreach (preg_split('/[\s,;]+/', mb_strtolower($billingEmail), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if ($candidate === $email) {
                return true;
            }
        }

        return false;
    }
}
