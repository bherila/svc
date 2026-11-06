<?php

declare(strict_types=1);

namespace Tests\Unit\Revenue;

use App\Models\ClientAgreementRecurringItem;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedRecurringItem;
use App\Support\Revenue\ProjectionInputs;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** The agreement and item terms the projection reads, field by field, with no database. */
final class ProjectedTermsTest extends TestCase
{
    public function test_retainer_terms_convert_minutes_and_minor_units_as_the_agreement_model_does(): void
    {
        $plain = $this->agreement(retainerMinutes: 90, retainerAmount: 123456);
        $this->assertSame(1.5, $plain->retainerMonthlyHours());
        $this->assertSame(1234.56, $plain->retainerMonthlyFee());
        $this->assertNull($plain->periodRetainerHoursOverride());
        $this->assertNull($plain->periodRetainerFeeOverride());
        $this->assertSame(1.5, $plain->periodRetainerHours());
        $this->assertSame(1.5, $plain->retainerHoursPerMonth());
        $this->assertSame(1234.56, $plain->periodRetainerFee());

        $empty = $this->agreement();
        $this->assertSame(0.0, (float) $empty->retainerMonthlyHours());
        $this->assertSame(0.0, (float) $empty->retainerMonthlyFee());
        $this->assertSame(0.0, (float) $empty->periodRetainerFee());
        $this->assertSame(0, $empty->monthlyRetainerEquivalent());

        $quarterly = $this->agreement(cadence: 'quarterly', retainerMinutes: 600, retainerAmount: 100000);
        $this->assertSame(30.0, (float) $quarterly->periodRetainerHours());
        $this->assertSame(3000.0, (float) $quarterly->periodRetainerFee());
        $this->assertSame(10.0, (float) $quarterly->retainerHoursPerMonth());

        $override = $this->agreement(cadence: 'quarterly', retainerMinutes: 60, retainerAmount: 100, periodMinutes: 600, periodAmount: 270000);
        $this->assertSame(10.0, (float) $override->periodRetainerHoursOverride());
        $this->assertSame(2700.0, (float) $override->periodRetainerFeeOverride());
        $this->assertSame(10.0, (float) $override->periodRetainerHours());
        $this->assertSame(2700.0, (float) $override->periodRetainerFee());
        // Ten hours a quarter is 3.3333 a month, to four places as the model rounds it.
        $this->assertSame(3.3333, $override->retainerHoursPerMonth());
        $this->assertSame(10.0, (float) $this->agreement(retainerMinutes: 60, periodMinutes: 600)->retainerHoursPerMonth());
    }

    public function test_the_monthly_retainer_equivalent_rounds_to_the_nearest_minor_unit(): void
    {
        // 100000 / 3 = 33333.33 and 100001 / 3 = 33333.67.
        $this->assertSame(33333, $this->agreement(cadence: 'quarterly', periodAmount: 100000)->monthlyRetainerEquivalent());
        $this->assertSame(33334, $this->agreement(cadence: 'quarterly', periodAmount: 100001)->monthlyRetainerEquivalent());
        $this->assertSame(100000, $this->agreement(retainerAmount: 100000)->monthlyRetainerEquivalent());
    }

    public function test_cadence_and_proration_fall_back_as_the_agreement_model_does(): void
    {
        $this->assertSame(BillingCadence::Monthly, $this->agreement(cadence: null)->effectiveBillingCadence());
        $this->assertSame(BillingCadence::Annual, $this->agreement(cadence: 'annual')->effectiveBillingCadence());
        $this->assertSame(BillingCadence::Monthly, $this->agreement(cadence: 'one_time')->effectiveBillingCadence());
        $this->assertSame(FirstCycleProration::ProrateHours, $this->agreement()->effectiveFirstCycleProration());
        $this->assertSame(FirstCycleProration::FullPeriod, $this->agreement(proration: 'full_period')->effectiveFirstCycleProration());

        $this->assertTrue($this->agreement(cadence: null)->billsOnARecurringCadence());
        $this->assertTrue($this->agreement(cadence: 'semi_annual')->billsOnARecurringCadence());
        $this->assertFalse($this->agreement(cadence: 'one_time')->billsOnARecurringCadence());
    }

    public function test_an_agreement_is_in_force_from_its_start_through_its_end_inclusive(): void
    {
        $agreement = $this->agreement(startsOn: '2026-03-01', endsOn: '2026-06-30');

        $this->assertFalse($agreement->isInForceOn(CarbonImmutable::parse('2026-02-28')));
        $this->assertTrue($agreement->isInForceOn(CarbonImmutable::parse('2026-03-01')));
        $this->assertTrue($agreement->isInForceOn(CarbonImmutable::parse('2026-06-30 18:00')));
        $this->assertFalse($agreement->isInForceOn(CarbonImmutable::parse('2026-07-01')));
        $this->assertTrue($this->agreement(startsOn: '2026-03-01')->isInForceOn(CarbonImmutable::parse('2040-01-01')));

        $this->assertTrue($agreement->coversAnyOf(CarbonImmutable::parse('2026-06-30'), CarbonImmutable::parse('2026-07-31')));
        $this->assertTrue($agreement->coversAnyOf(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-01')));
        $this->assertFalse($agreement->coversAnyOf(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')));
        $this->assertFalse($agreement->coversAnyOf(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28')));
    }

    public function test_the_effective_end_of_an_ended_agreement_without_an_end_date(): void
    {
        $this->assertSame('2026-09-30 00:00', $this->agreement(endsOn: '2026-09-30 15:00')->effectiveEnd()?->format('Y-m-d H:i'));
        $this->assertNull($this->agreement(status: 'paused')->effectiveEnd());
        $this->assertSame('2026-10-31 00:00', $this->agreement(status: 'expired', terminatedAt: '2026-10-31 17:30')->effectiveEnd()?->format('Y-m-d H:i'));
        $this->assertSame('2026-01-01', $this->agreement(status: 'terminated')->effectiveEnd()?->toDateString());
        $this->assertSame('2026-09-30', $this->agreement(status: 'terminated', endsOn: '2026-09-30', terminatedAt: '2026-10-31')->retainerEndsOn()?->toDateString());
        $this->assertSame('2026-01-01', $this->agreement()->retainerStartsOn()->toDateString());
    }

    public function test_monthly_recurring_counts_only_what_is_in_force_on_the_day(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000, startsOn: '2026-01-01', endsOn: '2026-12-31', items: [
            $this->item('monthly', 1000, effectiveOn: '2026-06-01'),
            $this->item('annual', 12000, expiresOn: '2026-05-31'),
            $this->item('quarterly', 3000, active: false),
        ]);

        $this->assertSame(100000 + 1000, $agreement->monthlyRecurringOn(CarbonImmutable::parse('2026-06-01')));
        $this->assertSame(100000 + 1000, $agreement->monthlyRecurringOn(CarbonImmutable::parse('2026-05-31')));
        $this->assertSame(0, $agreement->monthlyRecurringOn(CarbonImmutable::parse('2027-01-01')));
        $this->assertSame(0, $this->agreement(cadence: 'one_time', retainerAmount: 100000)->monthlyRecurringOn(CarbonImmutable::parse('2026-06-01')));
    }

    public function test_the_biller_model_carries_the_agreements_and_items_terms_unchanged(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', endsOn: '2027-06-30', items: [$this->item('annual', 12000, anchorMonth: 3, anchorDay: 9, effectiveOn: '2026-02-01', expiresOn: '2027-01-31', quantity: '2.000', taxable: true, currency: 'USD')]);
        $model = $agreement->toBillerModel();

        $this->assertSame(['id' => 7, 'workspace_id' => 3, 'client_company_id' => 10, 'starts_on' => '2026-01-01', 'ends_on' => '2027-06-30', 'billing_cadence' => 'quarterly'], $model->getAttributes());
        $this->assertFalse($model->isDirty());
        $item = $model->recurringItems->first();
        $this->assertInstanceOf(ClientAgreementRecurringItem::class, $item);
        $this->assertSame([
            'id' => 100, 'public_id' => 'item-100', 'workspace_id' => 3, 'client_agreement_id' => 7, 'description' => 'Synthetic item',
            'cadence' => 'annual', 'anchor_month' => 3, 'anchor_day' => 9, 'effective_on' => '2026-02-01', 'expires_on' => '2027-01-31',
            'quantity' => '2.000', 'amount' => 12000, 'currency' => 'USD', 'is_taxable' => true, 'is_active' => true,
        ], $item->getAttributes());
        $this->assertFalse($item->isDirty());
        $this->assertSame(['effective_on' => null, 'expires_on' => null], array_intersect_key($this->item('monthly', 1)->toBillerModel(1, 1)->getAttributes(), ['effective_on' => 1, 'expires_on' => 1]));
        $this->assertNull($this->agreement()->toBillerModel()->getAttributes()['ends_on']);
    }

    public function test_an_items_incidence_is_its_amount_times_its_quantity_rounded_and_before_tax(): void
    {
        $this->assertSame(1000, $this->item('monthly', 1000, quantity: null)->incidenceAmount());
        $this->assertSame(2500, $this->item('monthly', 1000, quantity: '2.500')->incidenceAmount());
        // 333 x 1.5 = 499.5 rounds up; 100 x 1.333 = 133.3 rounds down.
        $this->assertSame(500, $this->item('monthly', 333, quantity: '1.500')->incidenceAmount());
        $this->assertSame(133, $this->item('monthly', 100, quantity: '1.333')->incidenceAmount());
        $this->assertSame(1000, $this->item('monthly', 1000, taxable: true)->incidenceAmount());
    }

    public function test_an_items_monthly_equivalent_spreads_its_cadence_and_excludes_one_time(): void
    {
        $cases = [
            ['monthly', 1001, 1001],
            ['quarterly', 1000, 333], ['quarterly', 1001, 334],
            ['semi_annual', 1003, 167], ['semi_annual', 1005, 168],
            ['annual', 1201, 100], ['annual', 1207, 101],
            ['one_time', 99000, 0],
            ['fortnightly', 5000, 0],
        ];
        foreach ($cases as [$cadence, $amount, $monthly]) {
            $this->assertSame($monthly, $this->item($cadence, $amount)->monthlyEquivalent(), "{$cadence} {$amount}");
        }
    }

    public function test_an_item_is_in_force_while_active_between_its_dates(): void
    {
        $agreementStart = CarbonImmutable::parse('2026-01-01');
        $item = $this->item('monthly', 1, effectiveOn: '2026-03-01', expiresOn: '2026-05-31');

        $this->assertFalse($item->isInForceOn(CarbonImmutable::parse('2026-02-28'), $agreementStart));
        $this->assertTrue($item->isInForceOn(CarbonImmutable::parse('2026-03-01'), $agreementStart));
        $this->assertTrue($item->isInForceOn(CarbonImmutable::parse('2026-05-31'), $agreementStart));
        $this->assertFalse($item->isInForceOn(CarbonImmutable::parse('2026-06-01'), $agreementStart));
        $this->assertFalse($this->item('monthly', 1, active: false)->isInForceOn(CarbonImmutable::parse('2026-03-01'), $agreementStart));
        // No effective date: the item starts with its agreement.
        $this->assertFalse($this->item('monthly', 1)->isInForceOn(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-01')));
        $this->assertTrue($this->item('monthly', 1)->isInForceOn(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-01')));
        // Its own effective date wins over the agreement's start.
        $this->assertFalse($this->item('monthly', 1, effectiveOn: '2026-05-01')->isInForceOn(CarbonImmutable::parse('2026-04-15'), CarbonImmutable::parse('2026-01-01')));
    }

    public function test_charge_keys_name_their_kind_owner_and_date(): void
    {
        $this->assertSame('retainer:12:2026-11-01', ProjectionInputs::retainerKey(12, '2026-11-01'));
        $this->assertSame('item:34:2026-11-15', ProjectionInputs::itemKey(34, '2026-11-15'));
        $inputs = new ProjectionInputs(CarbonImmutable::parse('2026-11-06'), [], billedCharges: ['retainer:12:2026-11-01' => true]);
        $this->assertTrue($inputs->isBilled('retainer:12:2026-11-01'));
        $this->assertFalse($inputs->isBilled('item:12:2026-11-01'));
        $this->assertSame(['2026-11-01', '2027-11-30'], [$inputs->windowStart()->toDateString(), $inputs->windowEnd()->toDateString()]);
    }

    public function test_inputs_narrowed_to_a_client_keep_only_its_agreements_and_their_history(): void
    {
        $mine = $this->agreement(id: 1, clientId: 10);
        $theirs = $this->agreement(id: 2, clientId: 20);
        $alsoMine = $this->agreement(id: 3, clientId: 10);
        $inputs = new ProjectionInputs(CarbonImmutable::parse('2026-11-06'), [$theirs, $mine, $alsoMine], [1 => ['2026-10' => 5], 2 => ['2026-10' => 7], 3 => ['2026-09' => 9]], ['k' => true]);

        $narrowed = $inputs->forClient(10);

        $this->assertSame([$mine, $alsoMine], $narrowed->agreements);
        $this->assertSame([1 => ['2026-10' => 5], 3 => ['2026-09' => 9]], $narrowed->overageHistory);
        $this->assertSame(['k' => true], $narrowed->billedCharges);
        $this->assertSame('2026-11-06', $narrowed->asOf->toDateString());
    }

    /** @param list<ProjectedRecurringItem> $items */
    private function agreement(
        int $id = 7,
        int $clientId = 10,
        ?string $cadence = 'monthly',
        string $startsOn = '2026-01-01',
        ?string $endsOn = null,
        string $status = 'active',
        ?string $terminatedAt = null,
        ?int $retainerMinutes = null,
        ?int $retainerAmount = null,
        ?int $periodMinutes = null,
        ?int $periodAmount = null,
        ?string $proration = null,
        array $items = [],
    ): ProjectedAgreement {
        return new ProjectedAgreement(
            id: $id, publicId: 'agreement-'.$id, workspaceId: 3, clientId: $clientId, clientPublicId: 'client-'.$clientId, clientName: 'Synthetic client',
            title: 'Synthetic retainer', status: $status, currency: 'USD', billingCadence: $cadence, firstCycleProration: $proration,
            startsOn: CarbonImmutable::parse($startsOn), endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            terminatedAt: $terminatedAt === null ? null : CarbonImmutable::parse($terminatedAt), signedAt: null,
            retainerMinutes: $retainerMinutes, retainerAmount: $retainerAmount, periodRetainerMinutes: $periodMinutes, periodRetainerAmount: $periodAmount,
            recurringItems: $items,
        );
    }

    private function item(
        string $cadence,
        int $amount,
        ?int $anchorMonth = null,
        ?int $anchorDay = 1,
        ?string $effectiveOn = null,
        ?string $expiresOn = null,
        ?string $quantity = '1.000',
        bool $taxable = false,
        bool $active = true,
        ?string $currency = null,
    ): ProjectedRecurringItem {
        return new ProjectedRecurringItem(
            id: 100, publicId: 'item-100', description: 'Synthetic item', cadence: $cadence, anchorMonth: $anchorMonth, anchorDay: $anchorDay,
            effectiveOn: $effectiveOn === null ? null : CarbonImmutable::parse($effectiveOn), expiresOn: $expiresOn === null ? null : CarbonImmutable::parse($expiresOn),
            amount: $amount, quantity: $quantity, isTaxable: $taxable, isActive: $active, currency: $currency,
        );
    }
}
