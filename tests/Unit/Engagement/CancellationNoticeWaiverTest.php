<?php

namespace Tests\Unit\Engagement;

use App\Support\Billing\BillingCadence;
use App\Support\Engagement\Cancellation\CancellationTerms;
use App\Support\Packages\CancellationMode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * Waiving notice counts it as no days at all (CAN-2, CAN-4): the terms built
 * directly, so the decision is read without the application.
 */
#[CoversClass(CancellationTerms::class)]
final class CancellationNoticeWaiverTest extends TestCase
{
    #[Requirement('CAN-2', 'CAN-4')]
    public function test_waived_notice_runs_out_today_so_a_period_closing_today_may_be_chosen(): void
    {
        $terms = new CancellationTerms(30, null, CancellationMode::Request, BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), null);
        $lastDayOfPeriod = CarbonImmutable::parse('2026-11-30');

        $waived = $terms->offer($lastDayOfPeriod, waiveNotice: true);

        $this->assertSame('2026-11-30', $waived?->noticeRunsOutOn->toDateString());
        $this->assertSame(['2026-11-30', '2026-12-31', '2027-01-31'], $waived->offeredDateStrings());
        // With notice, the same day offers only the closes after it runs out.
        $this->assertSame(['2026-12-31', '2027-01-31', '2027-02-28'], $terms->offer($lastDayOfPeriod)?->offeredDateStrings());
    }
}
