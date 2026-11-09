<?php

namespace App\Support\Packages;

/** Whether an accepted (and, if required, paid) sign-up starts on its own or waits for an owner or admin. */
enum PackageActivationMode: string
{
    case Automatic = 'automatic';
    case Review = 'review';

    /** Where an accepted enrollment that owes nothing at sign-up goes (ACC-5). */
    public function statusOnceAccepted(): EnrollmentStatus
    {
        return match ($this) {
            self::Automatic => EnrollmentStatus::Active,
            self::Review => EnrollmentStatus::AwaitingReview,
        };
    }
}
