<?php

namespace App\Support\Packages;

use Carbon\CarbonImmutable;

/**
 * When an open enrollment lapses (ACC-6): `payment_lapse_days` after the offer
 * for one not yet accepted, after the acceptance for one waiting for payment,
 * counted in calendar days of the workspace's timezone. It lapses on that day,
 * not the day after.
 *
 * Pure, so the date is tested across timezones without a database.
 */
final class EnrollmentLapse
{
    /** The workspace-local day it lapses on, or null when it can no longer lapse. */
    public static function lapsesOn(EnrollmentStatus $status, CarbonImmutable $offeredAt, ?CarbonImmutable $acceptedAt, int $lapseDays, string $timezone): ?CarbonImmutable
    {
        $from = match ($status) {
            EnrollmentStatus::Offered => $offeredAt,
            EnrollmentStatus::AwaitingPayment => $acceptedAt ?? $offeredAt,
            EnrollmentStatus::AwaitingReview, EnrollmentStatus::Active, EnrollmentStatus::Lapsed, EnrollmentStatus::Withdrawn => null,
        };

        return $from?->setTimezone($timezone)->startOfDay()->addDays(max(1, $lapseDays));
    }

    /** Whether it has lapsed by this workspace-local day. */
    public static function hasLapsed(EnrollmentStatus $status, CarbonImmutable $offeredAt, ?CarbonImmutable $acceptedAt, int $lapseDays, string $timezone, CarbonImmutable $today): bool
    {
        $lapsesOn = self::lapsesOn($status, $offeredAt, $acceptedAt, $lapseDays, $timezone);

        return $lapsesOn !== null && $today->toDateString() >= $lapsesOn->toDateString();
    }
}
