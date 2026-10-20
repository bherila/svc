<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\AgreementHandover;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AgreementHandoverTest extends TestCase
{
    public function test_coverage_uses_inclusive_calendar_days_and_existing_proration_only_when_requested(): void
    {
        $handover = new AgreementHandover('synthetic-successor', '2026-01-15', '2026-01-14');
        $this->assertFalse($handover->overlaps('2026-01-01', '2026-01-14'));
        $this->assertTrue($handover->overlaps('2026-01-01', '2026-01-15'));
        $this->assertTrue($handover->overlaps('2026-01-15', '2026-01-31'));
        $this->assertTrue($handover->overlaps('2026-01-15', '2026-01-15'));
        $this->assertFalse($handover->overlaps('2026-01-15', '2026-01-14'));
        $this->assertFalse($handover->overlaps('2026-01-01', '2026-01-31', true));
        $this->assertFalse($handover->overlaps('2026-02-01', '2026-02-28', true));
        $this->assertTrue((new AgreementHandover('synthetic-successor', '2026-01-15', null))->overlaps('2026-01-01', '2026-01-31', true));
        $this->assertTrue((new AgreementHandover('synthetic-successor', '2026-01-15', '2026-02-01'))->overlaps('2026-01-01', '2026-01-31', true));
        $handover->assertClear('2026-01-01', '2026-01-14');
        $handover->assertClear('2026-01-01', '2026-01-31', true);
        $this->expectException(DomainException::class);
        $handover->assertClear('2026-01-01', '2026-01-31');
    }
}
