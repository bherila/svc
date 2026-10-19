<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\StripeRefundStatus;
use PHPUnit\Framework\TestCase;

final class StripeRefundStatusTest extends TestCase
{
    public function test_only_a_failed_or_canceled_refund_stops_counting(): void
    {
        $this->assertFalse(StripeRefundStatus::stillStands('failed'));
        $this->assertFalse(StripeRefundStatus::stillStands('canceled'));
        foreach (['pending', 'requires_action', 'succeeded'] as $status) {
            $this->assertTrue(StripeRefundStatus::stillStands($status), $status);
        }
    }

    public function test_an_unknown_or_missing_status_still_counts_as_refunded(): void
    {
        $this->assertTrue(StripeRefundStatus::stillStands('something_new'));
        $this->assertTrue(StripeRefundStatus::stillStands(null));
    }
}
