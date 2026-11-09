<?php

namespace App\Support\Packages\Migration;

/**
 * Why a subscriber was not moved on the effective date (MIG-3). Recorded on
 * its target, which is then settled. Codes are part of the agent API.
 */
enum MigrationSkip: string
{
    /** The agreement is no longer active: terminated, expired or paused. */
    case AgreementEnded = 'agreement_ended';
    /** The agreement ends before the new terms would start, by a cancellation or its own end date. */
    case EndsBeforeEffective = 'ends_before_effective';
    /** The service is no longer on the version moved from: another move took it first. */
    case VersionMoved = 'version_moved';
    /** The service is no longer running. */
    case EnrollmentInactive = 'enrollment_inactive';

    public function message(): string
    {
        return match ($this) {
            self::AgreementEnded => 'The agreement had already ended, so it kept its terms.',
            self::EndsBeforeEffective => 'The agreement ends before the new terms would start, so it keeps its terms until it ends.',
            self::VersionMoved => 'The service had already moved to another version.',
            self::EnrollmentInactive => 'The service was no longer running.',
        };
    }
}
