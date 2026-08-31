<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\RetainerLineDescription;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RetainerLineDescriptionTest extends TestCase
{
    public function test_it_formats_the_exact_client_facing_cadence_contract(): void
    {
        $this->assertSame(
            'Monthly Retainer (10:00 hours) - Jan 1, 2026 through Jan 31, 2026',
            RetainerLineDescription::for(
                BillingCadence::Monthly,
                10.0,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2026-01-31'),
            ),
        );
    }
}
