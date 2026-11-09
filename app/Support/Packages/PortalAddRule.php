<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;

/**
 * Whether a company contact may add a package from the portal now (ACC-8),
 * and if not, why. The Services page offers "Review and add" exactly when
 * this has no refusal, and adding checks it again under the package's lock.
 *
 * A retired or unpublished package, or one not marked portal-addable, is not
 * offered (PKG-6). Until sign-up payment exists (slice 3b), a version that
 * takes a payment at sign-up is refused here too, so nothing half-works.
 */
final class PortalAddRule
{
    /** @param PackageTerms|null $published the package's published version, if any */
    public static function refusal(ServicePackage $package, ?PackageTerms $published): ?PackageRefusal
    {
        $offered = match ($package->packageStatus()) {
            PackageStatus::Published => $package->portal_addable && $published !== null,
            PackageStatus::Draft, PackageStatus::Retired => false,
        };
        if (! $offered || $published === null) {
            return PackageRefusal::NotAddable;
        }

        return $published->involvesPayment() ? PackageRefusal::PaymentNotAvailableYet : null;
    }
}
