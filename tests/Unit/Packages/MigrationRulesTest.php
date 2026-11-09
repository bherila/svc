<?php

namespace Tests\Unit\Packages;

use App\Services\Billing\BillingCycleResolver;
use App\Support\Billing\BillingCadence;
use App\Support\Engagement\CancellationCycleAnchor;
use App\Support\Engagement\CancellationDates;
use App\Support\Packages\Migration\MigrationDates;
use App\Support\Packages\Migration\MigrationEffectiveDates;
use App\Support\Packages\Migration\MigrationHold;
use App\Support\Packages\Migration\MigrationRefusal;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationSkip;
use App\Support\Packages\Migration\MigrationStatus;
use App\Support\Packages\Migration\MigrationTargetStatus;
use App\Support\Packages\Migration\MigrationTermChanges;
use App\Support\Packages\PackageTerms;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The decisions of a move to a new version, with no database (MIG-1, MIG-3,
 * MIG-4, MIG-5): the dates it may take effect, which subscribers it may take,
 * what happens to each on the date, when it may be cancelled, the autopay
 * flag, the minimum term a move carries over, and what it says changed.
 */
final class MigrationRulesTest extends TestCase
{
    /** @return array<string, array{BillingCadence, string, int, string, string|null, string|null, list<string>}> */
    public static function effectiveDates(): array
    {
        $m = BillingCadence::Monthly;
        $q = BillingCadence::Quarterly;
        $a = BillingCadence::Annual;

        // cadence, starts_on, notice, today, ends_on, invoiced through, offered (first three)
        return [
            'monthly, no notice: next month' => [$m, '2026-01-01', 0, '2026-11-09', null, null, ['2026-12-01', '2027-01-01', '2027-02-01']],
            'monthly, notice into next month' => [$m, '2026-01-01', 30, '2026-11-09', null, null, ['2027-01-01', '2027-02-01', '2027-03-01']],
            'monthly, notice runs out on a period start: the client could only leave at its close' => [$m, '2026-01-01', 22, '2026-11-09', null, null, ['2027-01-01', '2027-02-01', '2027-03-01']],
            'monthly, notice runs out on a close' => [$m, '2026-01-01', 21, '2026-11-09', null, null, ['2026-12-01', '2027-01-01', '2027-02-01']],
            'monthly, today the first, no notice' => [$m, '2026-01-01', 0, '2026-12-01', null, null, ['2027-01-01', '2027-02-01', '2027-03-01']],
            'monthly, February in a leap year' => [$m, '2026-01-01', 29, '2028-01-31', null, null, ['2028-03-01', '2028-04-01', '2028-05-01']],
            'monthly, invoiced through December' => [$m, '2026-01-01', 0, '2026-11-09', null, '2026-12-31', ['2027-01-01', '2027-02-01', '2027-03-01']],
            'monthly, invoiced through a period inside the notice' => [$m, '2026-01-01', 60, '2026-11-09', null, '2026-11-30', ['2027-02-01', '2027-03-01', '2027-04-01']],
            'monthly, starts in the future' => [$m, '2027-03-15', 0, '2026-11-09', null, null, ['2027-04-01', '2027-05-01', '2027-06-01']],
            'monthly, ends on a close: the last start before it' => [$m, '2026-01-01', 0, '2026-11-09', '2027-01-31', null, ['2026-12-01', '2027-01-01']],
            'monthly, ends on a start: one day of new terms' => [$m, '2026-01-01', 0, '2026-11-09', '2026-12-01', null, ['2026-12-01']],
            'monthly, ends before the earliest' => [$m, '2026-01-01', 30, '2026-11-09', '2026-12-31', null, []],
            'quarterly anchored mid-month' => [$q, '2026-01-15', 30, '2026-11-09', null, null, ['2027-01-15', '2027-04-15', '2027-07-15']],
            'quarterly, notice past the quarter' => [$q, '2026-01-15', 70, '2026-11-09', null, null, ['2027-04-15', '2027-07-15', '2027-10-15']],
            'annual' => [$a, '2026-03-01', 30, '2026-11-09', null, null, ['2027-03-01', '2028-03-01', '2029-03-01']],
        ];
    }

    /** @param list<string> $offered */
    #[DataProvider('effectiveDates')]
    #[Requirement('MIG-1')]
    public function test_a_move_takes_effect_at_a_period_start_after_the_notice_and_the_invoices(BillingCadence $cadence, string $startsOn, int $notice, string $today, ?string $endsOn, ?string $invoiced, array $offered): void
    {
        $dates = (new MigrationDates)->offer($cadence, self::day($startsOn), $notice, self::day($today), $endsOn === null ? null : self::day($endsOn), $invoiced === null ? null : self::day($invoiced));

        $this->assertSame($offered, array_slice($dates->offeredDateStrings(), 0, 3));
        if ($offered !== []) {
            $this->assertSame($offered[0], $dates->earliest->toDateString());
            $this->assertTrue($dates->offers(self::day($offered[0])));
        }
        $this->assertFalse($dates->offers(self::day('2026-12-02')));
    }

    /**
     * The earliest is always the day after the earliest end a cancellation made today could choose.
     *
     * @param  list<string>  $offered  the provider's expectation for the other test; unused here
     */
    #[DataProvider('effectiveDates')]
    #[Requirement('MIG-1')]
    public function test_a_client_told_today_can_always_leave_before_the_new_terms(BillingCadence $cadence, string $startsOn, int $notice, string $today, ?string $endsOn, ?string $invoiced, array $offered): void
    {
        $move = (new MigrationDates)->offer($cadence, self::day($startsOn), $notice, self::day($today), null, $invoiced === null ? null : self::day($invoiced));
        $leave = (new CancellationDates)->offer($cadence, self::day($startsOn), $notice, null, self::day($today));

        $this->assertGreaterThanOrEqual($leave->earliest->addDay()->toDateString(), $move->earliest->toDateString());
        // Every date offered is the day after one of the engine's period closes.
        $resolver = new BillingCycleResolver;
        foreach ($move->offered as $date) {
            $cycle = $resolver->cycleContaining(new CancellationCycleAnchor($cadence, self::day($startsOn)), Carbon::instance($date));
            $this->assertSame($date->toDateString(), CarbonImmutable::instance($cycle->start)->toDateString());
        }
    }

    #[Requirement('MIG-1')]
    public function test_six_dates_are_offered_unless_asked_otherwise_and_nonsense_is_refused(): void
    {
        $dates = new MigrationDates;
        $this->assertCount(6, $dates->offer(BillingCadence::Monthly, self::day('2026-01-01'), 0, self::day('2026-11-09'))->offered);
        $this->assertCount(2, $dates->offer(BillingCadence::Monthly, self::day('2026-01-01'), 0, self::day('2026-11-09'), count: 2)->offered);
        $this->expectException(InvalidArgumentException::class);
        $dates->offer(BillingCadence::Monthly, self::day('2026-01-01'), -1, self::day('2026-11-09'));
    }

    #[Requirement('MIG-1')]
    public function test_at_least_one_date_must_be_asked_for(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MigrationDates)->offer(BillingCadence::Monthly, self::day('2026-01-01'), 0, self::day('2026-11-09'), count: 0);
    }

    #[Requirement('MIG-1')]
    public function test_which_subscribers_a_move_may_take(): void
    {
        $dates = new MigrationEffectiveDates(self::day('2027-01-01'), [self::day('2027-01-01'), self::day('2027-02-01')]);
        $none = new MigrationEffectiveDates(self::day('2027-01-01'), []);

        $this->assertNull(MigrationRules::eligibility(true, false, $dates, null));
        $this->assertNull(MigrationRules::eligibility(true, false, $dates, self::day('2027-02-01')));
        $this->assertSame(MigrationRefusal::DateNotOffered, MigrationRules::eligibility(true, false, $dates, self::day('2027-01-15')));
        $this->assertSame(MigrationRefusal::NotASubscriber, MigrationRules::eligibility(false, false, $dates, null));
        $this->assertSame(MigrationRefusal::AlreadyScheduled, MigrationRules::eligibility(true, true, $dates, null));
        $this->assertSame(MigrationRefusal::NotRecurring, MigrationRules::eligibility(true, false, null, null));
        $this->assertSame(MigrationRefusal::EndsFirst, MigrationRules::eligibility(true, false, $none, self::day('2027-01-01')));
    }

    /** @return array<string, array{array{0: bool, 1: bool, 2: string, 3: string|null, 4: string|null, 5: bool, 6?: bool, 7?: bool, 8?: bool, 9?: bool, 10?: bool, 11?: bool}, MigrationSkip|MigrationHold|null}> */
    public static function decisions(): array
    {
        // enrollment active, carries the from version, agreement status, ends on, pending end, period invoiced[, Square collects it, cadence changed, first new period ended, off-cycle charge, an earlier deferred period unbilled, no one charge schedule]
        return [
            'an active subscriber moves' => [[true, true, 'active', null, null, false], null],
            'one ending after the date moves' => [[true, true, 'active', '2027-03-31', null, false], null],
            'one ending the day the new terms start moves' => [[true, true, 'active', '2027-01-01', null, false], null],
            'one asking to leave from the date on moves' => [[true, true, 'active', null, '2027-01-31', false], null],
            'the service stopped' => [[false, true, 'active', null, null, true], MigrationSkip::EnrollmentInactive],
            'already moved' => [[true, false, 'active', null, null, false], MigrationSkip::VersionMoved],
            'terminated' => [[true, true, 'terminated', null, '2026-12-31', true], MigrationSkip::AgreementEnded],
            'paused' => [[true, true, 'paused', null, null, false], MigrationSkip::AgreementEnded],
            'ends the day before' => [[true, true, 'active', '2026-12-31', null, true], MigrationSkip::EndsBeforeEffective],
            'asks to leave the day before' => [[true, true, 'active', null, '2026-12-31', true], MigrationHold::CancellationPending],
            'the new period already invoiced' => [[true, true, 'active', null, null, true], MigrationHold::PeriodInvoiced],
            'Square came to collect it' => [[true, true, 'active', null, '2026-12-31', true, true, true], MigrationHold::SquareCollected],
            'its cadence was changed' => [[true, true, 'active', null, '2026-12-31', true, false, true], MigrationHold::CadenceChanged],
            'a Square agreement that ended is still skipped' => [[true, true, 'terminated', null, null, false, true, true, true, true], MigrationSkip::AgreementEnded],
            'the first new period ended while it waited' => [[true, true, 'active', null, '2026-12-31', true, true, true, true, true], MigrationHold::EffectiveDatePassed],
            'one ending before the date is skipped though its first new period ended' => [[true, true, 'active', '2026-12-31', null, false, false, false, true], MigrationSkip::EndsBeforeEffective],
            'it came to carry an off-cycle charge' => [[true, true, 'active', null, '2026-12-31', true, false, false, false, true], MigrationHold::OffCycleComponent],
            'an earlier deferred period is still unbilled' => [[true, true, 'active', null, '2026-12-31', true, true, true, false, true, true], MigrationHold::OwedPeriodUnbilled],
            'its charges follow no one schedule' => [[true, true, 'active', null, null, true, false, false, false, false, false, true], MigrationHold::AmbiguousRecurrence],
            'an off-cycle charge is named before the schedule' => [[true, true, 'active', null, null, false, false, false, false, true, false, true], MigrationHold::OffCycleComponent],
            'its own first period ended too' => [[true, true, 'active', null, null, false, false, false, true, false, true], MigrationHold::EffectiveDatePassed],
            'a stopped service owes nothing it can wait on' => [[false, true, 'active', null, null, false, false, false, false, false, true], MigrationSkip::EnrollmentInactive],
        ];
    }

    /** @param array{0: bool, 1: bool, 2: string, 3: string|null, 4: string|null, 5: bool, 6?: bool, 7?: bool, 8?: bool, 9?: bool, 10?: bool, 11?: bool} $facts */
    #[DataProvider('decisions')]
    #[Requirement('MIG-3')]
    public function test_on_the_date_each_subscriber_is_moved_skipped_or_held(array $facts, MigrationSkip|MigrationHold|null $expected): void
    {
        [$active, $carries, $status, $endsOn, $pendingEnd, $invoiced, $square, $cadence, $ended, $offCycle, $owed, $ambiguous] = $facts + [6 => false, 7 => false, 8 => false, 9 => false, 10 => false, 11 => false];
        $decision = MigrationRules::decide($active, $carries, $status, $endsOn === null ? null : self::day($endsOn), self::day('2027-01-01'), $pendingEnd === null ? null : self::day($pendingEnd), $invoiced, $square, $cadence, $ended, $offCycle, $owed, $ambiguous);

        $this->assertSame($expected === null, $decision->applies());
        $this->assertSame($expected instanceof MigrationSkip ? $expected : null, $decision->skip);
        $this->assertSame($expected instanceof MigrationHold ? $expected : null, $decision->hold);
    }

    #[Requirement('MIG-3')]
    public function test_a_move_is_due_from_its_date_while_scheduled(): void
    {
        $date = self::day('2027-01-01');
        $this->assertFalse(MigrationRules::isDue(MigrationTargetStatus::Scheduled, $date, self::day('2026-12-31')));
        $this->assertTrue(MigrationRules::isDue(MigrationTargetStatus::Scheduled, $date, $date));
        $this->assertTrue(MigrationRules::isDue(MigrationTargetStatus::Scheduled, $date, self::day('2027-01-03')));
        foreach ([MigrationTargetStatus::Applied, MigrationTargetStatus::Skipped, MigrationTargetStatus::Cancelled] as $settled) {
            $this->assertFalse(MigrationRules::isDue($settled, $date, self::day('2027-01-03')));
        }
    }

    #[Requirement('MIG-5')]
    public function test_a_move_may_be_cancelled_only_while_scheduled(): void
    {
        $this->assertNull(MigrationRules::cancelRefusal(MigrationStatus::Scheduled));
        $this->assertSame(MigrationRefusal::NotScheduled, MigrationRules::cancelRefusal(MigrationStatus::Cancelled));
        $this->assertSame(MigrationRefusal::NotScheduled, MigrationRules::cancelRefusal(MigrationStatus::Completed));
    }

    #[Requirement('MIG-1')]
    public function test_an_agreement_billing_by_usage_is_not_moved(): void
    {
        $this->assertFalse(MigrationRules::billsByUsage(null, null, null, null, null));
        $this->assertFalse(MigrationRules::billsByUsage(null, 0, 0, 0, 0));
        $this->assertTrue(MigrationRules::billsByUsage(0, null, null, null, null), 'an hourly rate, even of zero, prices work');
        $this->assertTrue(MigrationRules::billsByUsage(null, 1, null, null, null));
        $this->assertTrue(MigrationRules::billsByUsage(null, null, 1, null, null));
        $this->assertTrue(MigrationRules::billsByUsage(null, null, null, 1, null));
        $this->assertTrue(MigrationRules::billsByUsage(null, null, null, null, 1));
        $dates = new MigrationEffectiveDates(self::day('2027-01-01'), [self::day('2027-01-01')]);
        $this->assertSame(MigrationRefusal::BillsByUsage, MigrationRules::eligibility(true, false, $dates, null, true));
        $this->assertSame(MigrationRefusal::AlreadyScheduled, MigrationRules::eligibility(true, true, $dates, null, true));
    }

    #[Requirement('MIG-3', 'MIG-5')]
    public function test_a_move_deferred_its_first_period_if_it_was_still_scheduled_when_the_period_began(): void
    {
        $date = self::day('2027-01-01');
        $this->assertTrue(MigrationRules::deferredFirstPeriod(MigrationTargetStatus::Scheduled, null, $date));
        $this->assertFalse(MigrationRules::deferredFirstPeriod(MigrationTargetStatus::Applied, self::day('2027-02-01'), $date), 'its successor bills it');
        foreach ([MigrationTargetStatus::Cancelled, MigrationTargetStatus::Skipped] as $settled) {
            $this->assertTrue(MigrationRules::deferredFirstPeriod($settled, self::day('2027-02-01'), $date));
            $this->assertTrue(MigrationRules::deferredFirstPeriod($settled, $date, $date), 'settled on the date: the period had begun');
            $this->assertFalse(MigrationRules::deferredFirstPeriod($settled, self::day('2026-12-31'), $date), 'settled before the date: nothing waited');
            $this->assertFalse(MigrationRules::deferredFirstPeriod($settled, null, $date));
        }
    }

    #[Requirement('MIG-3')]
    public function test_a_deferred_period_is_owed_once_it_has_ended_inside_the_agreement(): void
    {
        $owes = static fn (string $start, string $end, string $today, string $startsOn = '2026-11-09', ?string $endsOn = null): bool => MigrationRules::owesDeferredPeriod(
            self::day($start), self::day($end), self::day('2027-01-01'), self::day($today), self::day($startsOn), $endsOn === null ? null : self::day($endsOn));

        $this->assertTrue($owes('2027-01-01', '2027-01-31', '2027-02-01'));
        $this->assertFalse($owes('2027-01-01', '2027-01-31', '2027-01-31'), 'still current: the move may apply to it');
        $this->assertFalse($owes('2026-12-01', '2026-12-31', '2027-02-01'), 'not the period the move deferred');
        $this->assertFalse($owes('2027-01-01', '2027-01-31', '2027-02-01', '2027-01-15'), 'before the agreement');
        $this->assertFalse($owes('2027-01-01', '2027-01-31', '2027-02-01', endsOn: '2026-12-31'), 'after the agreement');
        $this->assertTrue($owes('2027-01-01', '2027-01-31', '2027-02-01', endsOn: '2027-01-01'));
    }

    #[Requirement('MIG-1')]
    public function test_a_recurring_charge_off_the_billing_cycle_is_found(): void
    {
        $this->assertFalse(MigrationRules::hasOffCycleCharge('monthly', []));
        $this->assertFalse(MigrationRules::hasOffCycleCharge('monthly', ['monthly', 'monthly']));
        $this->assertTrue(MigrationRules::hasOffCycleCharge('monthly', ['monthly', 'annual']));
        $this->assertTrue(MigrationRules::hasOffCycleCharge('monthly', ['one_time']));
        $this->assertTrue(MigrationRules::hasOffCycleCharge('quarterly', ['quarterly', 'monthly']));
        $this->assertFalse(MigrationRules::hasOffCycleCharge('quarterly', ['quarterly']));
    }

    #[Requirement('MIG-1', 'INV-3')]
    public function test_an_agreement_square_collects_or_billing_on_another_cadence_is_not_moved(): void
    {
        $dates = new MigrationEffectiveDates(self::day('2027-01-01'), [self::day('2027-01-01')]);
        $this->assertSame(MigrationRefusal::SquareCollected, MigrationRules::eligibility(true, false, $dates, null, squareCollected: true));
        $this->assertSame(MigrationRefusal::SquareCollected, MigrationRules::eligibility(true, false, $dates, null, true, true, true), 'Square is named first: SVC leaves it alone whatever else holds');
        $this->assertSame(MigrationRefusal::ChangesCadence, MigrationRules::eligibility(true, false, $dates, null, changesCadence: true));
        $this->assertSame(MigrationRefusal::ChangesCadence, MigrationRules::eligibility(true, false, $dates, self::day('2027-01-15'), changesCadence: true));
        $this->assertSame(MigrationRefusal::NotRecurring, MigrationRules::eligibility(true, false, null, null, changesCadence: true));
        $this->assertSame(MigrationRefusal::AlreadyScheduled, MigrationRules::eligibility(true, true, $dates, null, squareCollected: true, changesCadence: true));
        $this->assertNull(MigrationRules::eligibility(true, false, $dates, null, false, false, false));
        $this->assertSame(MigrationRefusal::AmbiguousRecurrence, MigrationRules::eligibility(true, false, $dates, null, recurrenceAmbiguous: true));
        $this->assertSame(MigrationRefusal::ChangesCadence, MigrationRules::eligibility(true, false, $dates, null, changesCadence: true, recurrenceAmbiguous: true));
    }

    #[Requirement('MIG-4')]
    public function test_a_limit_below_the_new_price_is_flagged_and_no_limit_is_not(): void
    {
        $this->assertTrue(MigrationRules::autopayBelowPrice(4999, 5000));
        $this->assertFalse(MigrationRules::autopayBelowPrice(5000, 5000));
        $this->assertFalse(MigrationRules::autopayBelowPrice(6000, 5000));
        $this->assertFalse(MigrationRules::autopayBelowPrice(null, 5000));
    }

    /** @return array<string, array{string, int|null, string, int|null}> */
    public static function minimumTerms(): array
    {
        return [
            'none' => ['2026-11-09', null, '2027-01-01', null],
            'zero' => ['2026-11-09', 0, '2027-01-01', null],
            'twelve months from Nov 9, moved Jan 1: eleven left' => ['2026-11-09', 12, '2027-01-01', 11],
            'already served' => ['2025-01-01', 6, '2027-01-01', null],
            'ends the day before the move' => ['2026-01-01', 12, '2027-01-01', null],
            'ends on the move' => ['2026-01-02', 12, '2027-01-01', 1],
            'a quarter left exactly' => ['2026-04-01', 12, '2027-01-01', 3],
            'from a month end' => ['2026-01-31', 13, '2027-01-01', 2],
        ];
    }

    #[DataProvider('minimumTerms')]
    #[Requirement('MIG-3')]
    public function test_a_move_carries_what_is_left_of_the_minimum_term_and_never_starts_one(string $startsOn, ?int $months, string $effective, ?int $left): void
    {
        $this->assertSame($left, MigrationRules::remainingMinimumTerm(self::day($startsOn), $months, self::day($effective)));
    }

    #[Requirement('MIG-2')]
    public function test_a_client_is_told_each_term_that_changes_in_words(): void
    {
        $from = PackageTerms::fromArray(self::terms());
        $to = PackageTerms::fromArray(self::terms([
            'retainer_amount' => 65000, 'notice_days' => 45, 'agreement_text' => 'Revised synthetic text.',
            'invoice_issuing' => 'automatic', 'minimum_term_months' => 12,
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5000]],
        ]));

        $this->assertSame([
            ['term' => 'cycle_amount', 'label' => 'Price', 'from' => '$645.00 a month', 'to' => '$700.00 a month'],
            ['term' => 'components', 'label' => 'Included', 'from' => 'Managed hosting: $45.00 monthly', 'to' => 'Managed hosting: $50.00 monthly'],
            ['term' => 'retainer_amount', 'label' => 'Retainer', 'from' => '$600.00 a month', 'to' => '$650.00 a month'],
            ['term' => 'notice_days', 'label' => 'Notice to cancel', 'from' => '30 days', 'to' => '45 days'],
            ['term' => 'agreement_text', 'label' => 'Agreement text', 'from' => 'The current text', 'to' => 'Revised: read it with the new terms'],
        ], MigrationTermChanges::forClient($from, $to));
        // The operator also sees their own settings and what only shapes a new sign-up.
        $owner = array_column(MigrationTermChanges::forOwner($from, $to), 'to', 'term');
        $this->assertSame('Issued automatically', $owner['invoice_issuing']);
        $this->assertSame('12 months', $owner['minimum_term_months']);
        $this->assertSame([], MigrationTermChanges::forClient($from, $from));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function terms(array $overrides = []): array
    {
        return [
            'name' => 'Hosting + Care plan', 'currency' => 'USD', 'billing_cadence' => 'monthly',
            'retainer_amount' => 60000, 'retainer_minutes' => 600, 'notice_days' => 30, 'agreement_text' => 'Synthetic text.',
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500]],
            ...$overrides,
        ];
    }

    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }
}
