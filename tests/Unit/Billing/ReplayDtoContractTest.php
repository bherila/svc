<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use App\Support\Billing\ReplayCadenceAgreement;
use App\Support\Billing\ReplayInvoiceLineSnapshot;
use App\Support\Billing\ReplayInvoiceSnapshot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReplayDtoContractTest extends TestCase
{
    public function test_snapshot_hydration_preserves_missing_null_zero_and_dates(): void
    {
        $missing = ReplayInvoiceLineSnapshot::fromArray([]);
        $this->assertSame('', $missing->type);
        $this->assertSame(0, $missing->totalAmount);
        $this->assertNull($missing->hours);
        $this->assertNull($missing->sourceMinutes);
        $this->assertNull($missing->agreementRateSourceMinutes);

        $zero = ReplayInvoiceLineSnapshot::fromArray([
            'hours' => 0,
            'source_minutes' => 0,
            'source_agreement_rate_minutes' => 0,
        ]);
        $this->assertSame(0.0, $zero->hours);
        $this->assertSame(0, $zero->sourceMinutes);
        $this->assertSame(0, $zero->agreementRateSourceMinutes);

        $invoice = ReplayInvoiceSnapshot::fromArray([
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'cycle_start' => '2026-01-01',
            'cycle_end' => '?',
            'service_period_start' => '',
            'service_period_end' => null,
            'lines' => [[], 'not-an-array'],
        ]);
        $this->assertSame('2026-01-01', $invoice->cycleStart?->toDateString());
        $this->assertNull($invoice->cycleEnd);
        $this->assertNull($invoice->servicePeriodStart);
        $this->assertNull($invoice->servicePeriodEnd);
        $this->assertCount(1, $invoice->lines);
    }

    public function test_hour_conversions_pin_both_sides_of_nearest_minute_rounding(): void
    {
        $roundsDown = ReplayInvoiceLineSnapshot::fromArray(['hours' => 1.0082]);
        $roundsUp = ReplayInvoiceLineSnapshot::fromArray(['hours' => 1.0085]);
        $storedWholeMinute = ReplayInvoiceLineSnapshot::fromArray([
            'hours' => 1.0167,
            'quantity' => '1.0167',
        ]);

        $this->assertSame(60, $roundsDown->roundedHoursMinutes());
        $this->assertSame(61, $roundsUp->roundedHoursMinutes());
        $this->assertNull($roundsDown->hoursMinutes());
        $this->assertNull($roundsUp->hoursMinutes());
        $this->assertSame(61, $storedWholeMinute->hoursMinutes());
        $this->assertSame(61, $storedWholeMinute->quantityMinutes());
        $this->assertTrue($storedWholeMinute->quantityMatchesHours());
    }

    public function test_zero_period_overrides_do_not_fall_back_to_monthly_terms(): void
    {
        $agreement = new ReplayCadenceAgreement(
            companyId: 5,
            agreementId: 7,
            currency: 'USD',
            startsOn: CarbonImmutable::parse('2026-01-01'),
            endsOn: null,
            cadence: BillingCadence::Quarterly,
            firstCycleProration: FirstCycleProration::ProrateHours,
            monthlyHours: 10.0,
            monthlyFee: 1500.0,
            periodHoursOverride: 0.0,
            periodFeeOverride: 0.0,
            hourlyRateAmount: 0,
            catchUpThresholdMinutes: 0,
            rolloverMonths: 0,
            initialRolloverMinutes: 0,
            usesPeriodRetainerTerms: true,
        );

        $this->assertSame(0.0, $agreement->periodRetainerHours());
        $this->assertSame(0.0, $agreement->retainerHoursPerMonth());
        $this->assertSame(0.0, $agreement->periodRetainerFee());
    }
}
