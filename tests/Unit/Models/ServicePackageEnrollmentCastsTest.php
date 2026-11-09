<?php

namespace Tests\Unit\Models;

use App\Models\ServicePackageEnrollment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The diff-scoped mutation lane runs the Unit suite alone. The lapse rule and
 * the enrollment presenter read these columns as immutable instants, so each
 * must come back from a database row as one, not as a raw string.
 */
final class ServicePackageEnrollmentCastsTest extends TestCase
{
    public function test_the_enrollment_instants_are_immutable_dates(): void
    {
        $enrollment = (new ServicePackageEnrollment)->setRawAttributes([
            'offered_at' => '2026-11-01 15:00:00',
            'accepted_at' => '2026-11-02 16:00:00',
            'activated_at' => '2026-11-03 17:00:00',
            'lapsed_at' => '2026-11-04 18:00:00',
        ]);

        foreach (['offered_at' => '2026-11-01 15:00:00', 'accepted_at' => '2026-11-02 16:00:00', 'activated_at' => '2026-11-03 17:00:00', 'lapsed_at' => '2026-11-04 18:00:00'] as $column => $expected) {
            $value = $enrollment->getAttribute($column);
            $this->assertInstanceOf(CarbonImmutable::class, $value, $column);
            $this->assertSame($expected, $value->format('Y-m-d H:i:s'), $column);
        }
    }
}
