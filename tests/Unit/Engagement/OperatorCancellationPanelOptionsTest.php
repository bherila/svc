<?php

namespace Tests\Unit\Engagement;

use App\Services\Engagement\OperatorCancellationPanel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The end dates the agreement page's cancellation panel offers an owner or
 * admin (CAN-4): one list, those only waiving notice allows marked so, in
 * date order. Pure, so read without the application.
 */
#[CoversClass(OperatorCancellationPanel::class)]
final class OperatorCancellationPanelOptionsTest extends TestCase
{
    #[Requirement('CAN-4')]
    public function test_the_dates_are_merged_marked_and_in_date_order(): void
    {
        $this->assertSame([
            ['value' => '2026-11-30', 'waives_notice' => true],
            ['value' => '2026-12-31', 'waives_notice' => false],
            ['value' => '2027-01-31', 'waives_notice' => false],
            ['value' => '2027-02-28', 'waives_notice' => false],
        ], OperatorCancellationPanel::options(['2026-12-31', '2027-01-31', '2027-02-28'], ['2026-11-30', '2026-12-31', '2027-01-31']));
    }

    #[Requirement('CAN-4')]
    public function test_the_order_is_the_dates_own_whatever_order_the_lists_come_in(): void
    {
        $this->assertSame([
            ['value' => '2026-11-30', 'waives_notice' => false],
            ['value' => '2026-12-31', 'waives_notice' => true],
            ['value' => '2027-01-31', 'waives_notice' => false],
        ], OperatorCancellationPanel::options(['2027-01-31', '2026-11-30'], ['2026-12-31']));
    }

    public function test_nothing_offered_is_no_options(): void
    {
        $this->assertSame([], OperatorCancellationPanel::options([], []));
    }
}
