<?php

namespace App\Support\Packages;

use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;

/**
 * Which version's terms an enrollment stands on.
 *
 * Accepted, it stands on the version signed. Until then a published package's
 * offer is accepted on its currently published version (EDGE-7), and a
 * retired package's offer on the version it was made with: retiring stops new
 * sign-ups, not offers already made (EDGE-8).
 */
final class EnrollmentVersions
{
    /** The version an acceptance would sign now, or null when none may be (a package never published). */
    public static function toAccept(ServicePackage $package, ServicePackageEnrollment $enrollment): ?int
    {
        return match ($package->packageStatus()) {
            PackageStatus::Published => $package->published_version_id ?? $enrollment->service_package_version_id,
            PackageStatus::Retired => $enrollment->service_package_version_id,
            PackageStatus::Draft => null,
        };
    }

    /** The version whose terms a client is shown for this enrollment. */
    public static function shown(ServicePackage $package, ServicePackageEnrollment $enrollment): int
    {
        return $enrollment->current_version_id
            ?? $enrollment->accepted_version_id
            ?? ($enrollment->enrollmentStatus() === EnrollmentStatus::Offered ? self::toAccept($package, $enrollment) : null)
            ?? $enrollment->service_package_version_id;
    }
}
