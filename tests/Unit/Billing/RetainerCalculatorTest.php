<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Carbon\Carbon;
use Tests\TestCase;

class RetainerCalculatorTest extends TestCase
{
    public function test_cycle_retainer_uses_period_terms_when_available(): void
    {
        $agreement = $this->agreement([
            'billing_cadence' => BillingCadence::Quarterly->value,
            'retainer_hours' => 30,
            'retainer_fee' => 3000,
        ]);
        $cycle = (new BillingCycleResolver)->cycleContaining($agreement, Carbon::parse('2026-02-01'));

        $calculator = new RetainerCalculator;

        $this->assertSame(30.0, $calculator->cycleRetainerHours($agreement, $cycle, [
            'retainer_hours' => 12.0,
        ]));
        $this->assertSame(3000.0, $calculator->cycleRetainerFee($agreement, $cycle, [
            'retainer_multiplier' => 1.5,
        ]));
    }

    public function test_cycle_retainer_falls_back_to_monthly_ledger_terms(): void
    {
        $agreement = $this->agreement([
            'retainer_hours' => null,
            'retainer_fee' => null,
            'monthly_retainer_fee' => 1500,
        ]);
        $cycle = (new BillingCycleResolver)->cycleContaining($agreement, Carbon::parse('2026-01-15'));

        $calculator = new RetainerCalculator;

        $this->assertSame(12.5, $calculator->cycleRetainerHours($agreement, $cycle, [
            'retainer_hours' => 12.5,
        ]));
        $this->assertSame(3750.0, $calculator->cycleRetainerFee($agreement, $cycle, [
            'retainer_multiplier' => 2.5,
        ]));
    }

    /**
     * An agreement carrying no retainer price charges nothing for one.
     *
     * With both the period override and the monthly amount unset, the fee this
     * returns is the fee the retainer line on the invoice asks for. Reading an
     * absent price as anything but zero would invent a charge; reading it as
     * zero is what makes "no retainer recorded" and "no retainer billed" the
     * same statement.
     */
    public function test_an_agreement_with_no_retainer_price_bills_no_retainer_fee(): void
    {
        $unpriced = new ClientAgreement([
            'starts_on' => '2026-01-01',
            'billing_cadence' => BillingCadence::Monthly->value,
        ]);
        $cycle = (new BillingCycleResolver)->cycleContaining($unpriced, Carbon::parse('2026-01-15'));

        $calculator = new RetainerCalculator;

        $this->assertNull($unpriced->retainer_amount);
        $this->assertNull($unpriced->retainer_fee, 'No period override either');
        $this->assertSame(0.0, $calculator->cycleRetainerFee($unpriced, $cycle, ['retainer_multiplier' => 1.0]));

        // The priced alternative, so the assertion above is pinned to the null
        // rather than to the multiplier.
        $priced = $this->agreement(['monthly_retainer_fee' => 1500]);
        $this->assertSame(1500.0, $calculator->cycleRetainerFee($priced, $cycle, ['retainer_multiplier' => 1.0]));
    }

    public function test_cycle_period_multiplier_respects_termination_date(): void
    {
        $agreement = $this->agreement([
            'active_date' => '2026-02-01',
            'termination_date' => '2026-02-28',
            'billing_cadence' => BillingCadence::Quarterly->value,
            'retainer_hours' => 89,
        ]);
        $cycle = (new BillingCycleResolver)->cycleContaining($agreement, Carbon::parse('2026-02-15'));

        $calculator = new RetainerCalculator;

        $this->assertEqualsWithDelta(28 / 89, $calculator->cyclePeriodRetainerMultiplier($agreement, $cycle), 0.000001);
        $this->assertSame(28.0, $calculator->cyclePeriodRetainerHours($agreement, $cycle));
    }

    public function test_month_retainer_multiplier_prorates_partial_months(): void
    {
        $agreement = $this->agreement([
            'active_date' => '2026-01-16',
        ]);

        $multiplier = (new RetainerCalculator)->monthRetainerMultiplier(
            $agreement,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(0.5161, $multiplier);
    }

    public function test_month_retainer_multiplier_honors_full_period_first_cycle(): void
    {
        $agreement = $this->agreement([
            'active_date' => '2026-01-16',
            'first_cycle_proration' => FirstCycleProration::FullPeriod,
        ]);

        $multiplier = (new RetainerCalculator)->monthRetainerMultiplier(
            $agreement,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertSame(1.0, $multiplier);
    }

    /**
     * Builds an agreement from the engine's own vocabulary.
     *
     * Only construction is translated here; every assertion in this file is the
     * one the predecessor implementation shipped.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function agreement(array $attributes = []): ClientAgreement
    {
        $terms = array_merge([
            'active_date' => '2026-01-01',
            'termination_date' => null,
            'billing_cadence' => BillingCadence::Monthly->value,
            'first_cycle_proration' => FirstCycleProration::ProrateHours,
            'retainer_hours' => null,
            'retainer_fee' => null,
            'monthly_retainer_fee' => 1000,
        ], $attributes);

        $proration = $terms['first_cycle_proration'];

        return new ClientAgreement([
            'starts_on' => $terms['active_date'],
            'ends_on' => $terms['termination_date'],
            'billing_cadence' => $terms['billing_cadence'],
            'first_cycle_proration' => $proration instanceof FirstCycleProration ? $proration->value : $proration,
            'period_retainer_minutes' => $terms['retainer_hours'] === null ? null : (int) round((float) $terms['retainer_hours'] * 60),
            'period_retainer_amount' => $terms['retainer_fee'] === null ? null : (int) round((float) $terms['retainer_fee'] * 100),
            'retainer_amount' => (int) round((float) ($terms['monthly_retainer_fee'] ?? 0) * 100),
            'retainer_minutes' => (int) round((float) ($terms['monthly_retainer_hours'] ?? 0) * 60),
        ]);
    }
}
