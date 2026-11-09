<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;

/**
 * Whether a company contact may add a package from the portal now (ACC-8),
 * and if not, why. The Services page offers "Review and add" exactly when
 * this has no refusal, and adding checks it again under the package's lock.
 *
 * A retired or unpublished package, or one not marked portal-addable, is not
 * offered (PKG-6). A version that takes a payment at sign-up is offered only
 * while the workspace can take it: a Stripe connection (PKG-4) that has since
 * gone leaves a sign-up nobody could finish, so it is refused rather than
 * half-made.
 */
final class PortalAddRule
{
    /**
     * @param  PackageTerms|null  $published  the package's published version, if any
     * @param  bool  $paymentsAvailable  whether the workspace has a Stripe connection now
     */
    public static function refusal(ServicePackage $package, ?PackageTerms $published, bool $paymentsAvailable): ?PackageRefusal
    {
        $offered = match ($package->packageStatus()) {
            PackageStatus::Published => $package->portal_addable && $published !== null,
            PackageStatus::Draft, PackageStatus::Retired => false,
        };
        if (! $offered || $published === null) {
            return PackageRefusal::NotAddable;
        }

        return $published->involvesPayment() && ! $paymentsAvailable ? PackageRefusal::PaymentUnavailable : null;
    }
}
