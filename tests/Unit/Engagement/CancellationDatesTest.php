<?php

namespace Tests\Unit\Engagement;

use App\Services\Billing\BillingCycleResolver;
use App\Support\Billing\BillingCadence;
use App\Support\Engagement\CancellationCycleAnchor;
use App\Support\Engagement\CancellationDates;
use App\Support\Engagement\CancellationEndDates;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The end dates a cancellation may choose, with no database (CAN-2).
 *
 * Expected closes are written out by hand from the engine's own definition of
 * a period - calendar months for monthly, blocks anchored on `starts_on` and
 * chained with Carbon's overflowing `addMonths()` for every other cadence - and
 * one test below checks the calculator against the resolver directly.
 */
final class CancellationDatesTest extends TestCase
{
    private CancellationDates $dates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dates = new CancellationDates;
    }

    /**
     * @return array<string, array{BillingCadence, string, int, int|null, string, string, list<string>}>
     */
    public static function endDates(): array
    {
        $m = BillingCadence::Monthly;
        $q = BillingCadence::Quarterly;
        $s = BillingCadence::SemiAnnual;
        $a = BillingCadence::Annual;

        // cadence, starts_on, notice days, minimum term months, today, earliest, offered
        return [
            // Monthly: calendar months.
            'monthly, no notice, ends this month' => [$m, '2026-01-01', 0, null, '2026-11-09', '2026-11-30', ['2026-11-30', '2026-12-31', '2027-01-31']],
            'monthly, notice into next month' => [$m, '2026-01-01', 30, null, '2026-11-09', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28']],
            'monthly, notice runs out on the close itself' => [$m, '2026-01-01', 21, null, '2026-11-09', '2026-11-30', ['2026-11-30', '2026-12-31', '2027-01-31']],
            'monthly, notice runs out the day after a close' => [$m, '2026-01-01', 22, null, '2026-11-09', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28']],
            'monthly, today is a close and no notice' => [$m, '2026-01-01', 0, null, '2026-11-30', '2026-11-30', ['2026-11-30', '2026-12-31', '2027-01-31']],
            'monthly, notice crosses the year' => [$m, '2026-01-01', 1, null, '2026-12-31', '2027-01-31', ['2027-01-31', '2027-02-28', '2027-03-31']],
            'monthly, Jan 31 plus 30 days, common year' => [$m, '2026-01-01', 30, null, '2027-01-31', '2027-03-31', ['2027-03-31', '2027-04-30', '2027-05-31']],
            'monthly, Jan 31 plus 29 days lands on Feb 29' => [$m, '2026-01-01', 29, null, '2028-01-31', '2028-02-29', ['2028-02-29', '2028-03-31', '2028-04-30']],
            'monthly, Jan 31 plus 30 days, leap year' => [$m, '2026-01-01', 30, null, '2028-01-31', '2028-03-31', ['2028-03-31', '2028-04-30', '2028-05-31']],
            'monthly, today Feb 29' => [$m, '2026-01-01', 0, null, '2028-02-29', '2028-02-29', ['2028-02-29', '2028-03-31', '2028-04-30']],
            'monthly, long notice skips several months' => [$m, '2026-01-01', 90, null, '2026-11-09', '2027-02-28', ['2027-02-28', '2027-03-31', '2027-04-30']],
            'monthly, starts mid-month later on' => [$m, '2026-12-15', 0, null, '2026-11-09', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28']],
            'monthly, six-month minimum from a future start' => [$m, '2026-12-01', 30, 6, '2026-11-09', '2027-05-31', ['2027-05-31', '2027-06-30', '2027-07-31']],
            'monthly, minimum term long over' => [$m, '2025-01-15', 30, 12, '2026-11-09', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28']],
            'monthly, minimum term from mid-month ends mid-month' => [$m, '2026-03-15', 30, 12, '2026-11-09', '2027-03-31', ['2027-03-31', '2027-04-30', '2027-05-31']],
            'monthly, one-month minimum from Jan 31' => [$m, '2027-01-31', 0, 1, '2027-01-31', '2027-02-28', ['2027-02-28', '2027-03-31', '2027-04-30']],
            'monthly, one-month minimum from Jan 31, leap year' => [$m, '2028-01-31', 0, 1, '2028-01-31', '2028-02-29', ['2028-02-29', '2028-03-31', '2028-04-30']],
            'monthly, zero-month minimum is none' => [$m, '2026-01-01', 0, 0, '2026-11-09', '2026-11-30', ['2026-11-30', '2026-12-31', '2027-01-31']],
            'monthly, minimum term ends exactly on a close' => [$m, '2026-01-01', 0, 12, '2026-03-10', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28']],
            'monthly, minimum term ends one day into a period' => [$m, '2026-01-02', 0, 12, '2026-03-10', '2027-01-31', ['2027-01-31', '2027-02-28', '2027-03-31']],

            // Quarterly: three-month blocks from starts_on.
            'quarterly on the calendar' => [$q, '2026-01-01', 0, null, '2026-11-09', '2026-12-31', ['2026-12-31', '2027-03-31', '2027-06-30']],
            'quarterly off the calendar' => [$q, '2026-02-15', 0, null, '2026-11-09', '2026-11-14', ['2026-11-14', '2027-02-14', '2027-05-14']],
            'quarterly, notice runs out on the close' => [$q, '2026-02-15', 5, null, '2026-11-09', '2026-11-14', ['2026-11-14', '2027-02-14', '2027-05-14']],
            'quarterly, notice crosses into the next block' => [$q, '2026-02-15', 6, null, '2026-11-09', '2027-02-14', ['2027-02-14', '2027-05-14', '2027-08-14']],
            'quarterly from Nov 30 overflows February' => [$q, '2026-11-30', 0, null, '2026-11-30', '2027-03-01', ['2027-03-01', '2027-06-01', '2027-09-01']],
            'quarterly from Nov 30 closes on Feb 29' => [$q, '2027-11-30', 0, null, '2027-12-01', '2028-02-29', ['2028-02-29', '2028-05-31', '2028-08-31']],
            'quarterly, a year minimum' => [$q, '2026-01-01', 0, 12, '2026-03-01', '2026-12-31', ['2026-12-31', '2027-03-31', '2027-06-30']],
            'quarterly, a minimum ending inside a block runs to its close' => [$q, '2026-01-01', 0, 4, '2026-01-10', '2026-06-30', ['2026-06-30', '2026-09-30', '2026-12-31']],
            'quarterly, starts after today' => [$q, '2027-01-01', 0, null, '2026-11-09', '2027-03-31', ['2027-03-31', '2027-06-30', '2027-09-30']],

            // Semi-annual: six-month blocks from starts_on.
            'semi-annual, notice into the next block' => [$s, '2026-03-10', 30, null, '2026-11-09', '2027-03-09', ['2027-03-09', '2027-09-09', '2028-03-09']],
            'semi-annual from Aug 31 overflows February' => [$s, '2026-08-31', 0, null, '2026-11-09', '2027-03-02', ['2027-03-02', '2027-09-02', '2028-03-02']],
            'semi-annual, minimum of two blocks' => [$s, '2026-01-01', 0, 12, '2026-02-01', '2026-12-31', ['2026-12-31', '2027-06-30', '2027-12-31']],

            // Annual: twelve-month blocks from starts_on.
            'annual from Feb 29' => [$a, '2028-02-29', 0, null, '2028-06-01', '2029-02-28', ['2029-02-28', '2030-02-28', '2031-02-28']],
            'annual, notice runs out on the anniversary eve' => [$a, '2025-07-01', 46, null, '2026-05-15', '2026-06-30', ['2026-06-30', '2027-06-30', '2028-06-30']],
            'annual, notice crosses the anniversary' => [$a, '2025-07-01', 60, null, '2026-05-15', '2027-06-30', ['2027-06-30', '2028-06-30', '2029-06-30']],
            'annual, two-year minimum' => [$a, '2025-07-01', 0, 24, '2025-08-01', '2027-06-30', ['2027-06-30', '2028-06-30', '2029-06-30']],
            'annual, starts after today' => [$a, '2027-01-01', 0, null, '2026-11-09', '2027-12-31', ['2027-12-31', '2028-12-31', '2029-12-31']],
        ];
    }

    /**
     * @param  list<string>  $offered
     */
    #[DataProvider('endDates')]
    public function test_earliest_and_offered_end_dates(
        BillingCadence $cadence,
        string $startsOn,
        int $noticeDays,
        ?int $minimumTermMonths,
        string $today,
        string $earliest,
        array $offered,
    ): void {
        // Requirement: CAN-2
        $result = $this->dates->offer($cadence, CarbonImmutable::parse($startsOn), $noticeDays, $minimumTermMonths, CarbonImmutable::parse($today));

        $this->assertSame($earliest, $result->earliest->toDateString());
        $this->assertSame($offered, $result->offeredDateStrings());
        $this->assertSame($offered, array_map(static fn (CarbonImmutable $date): string => $date->toDateString(), $result->offered));
        foreach ($result->offered as $date) {
            $this->assertSame('UTC', $date->getTimezone()->getName());
            $this->assertSame('00:00:00', $date->format('H:i:s'));
        }
    }

    /**
     * @param  list<string>  $offered
     */
    #[DataProvider('endDates')]
    public function test_every_offered_date_is_a_close_the_engine_bills_to(
        BillingCadence $cadence,
        string $startsOn,
        int $noticeDays,
        ?int $minimumTermMonths,
        string $today,
        string $earliest,
        array $offered,
    ): void {
        // Requirement: CAN-2
        $resolver = new BillingCycleResolver;
        $anchor = new CancellationCycleAnchor($cadence, CarbonImmutable::parse($startsOn, 'UTC'));
        $closes = [];
        foreach ($resolver->cyclesForAgreement($anchor, Carbon::parse(max([$earliest, ...$offered]))) as $cycle) {
            $closes[] = $cycle->end->toDateString();
        }

        foreach ($offered as $date) {
            $this->assertContains($date, $closes);
        }
        $this->assertGreaterThanOrEqual(CarbonImmutable::parse($today)->addDays($noticeDays)->toDateString(), $earliest);
    }

    public function test_it_reports_when_the_notice_runs_out_and_the_minimum_term_ends(): void
    {
        // Requirement: CAN-2
        $result = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-12-01'), 30, 6, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame('2026-12-09', $result->noticeRunsOutOn->toDateString());
        $this->assertSame('2027-05-31', $result->minimumTermEndsOn?->toDateString());
    }

    public function test_the_minimum_term_ends_the_day_before_its_monthiversary_without_overflowing(): void
    {
        // Requirement: CAN-2
        $common = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2027-01-31'), 0, 1, CarbonImmutable::parse('2027-01-31'));
        $leap = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2028-01-31'), 0, 1, CarbonImmutable::parse('2028-01-31'));

        $this->assertSame('2027-02-27', $common->minimumTermEndsOn?->toDateString());
        $this->assertSame('2028-02-28', $leap->minimumTermEndsOn?->toDateString());
    }

    public function test_no_minimum_term_reports_none(): void
    {
        // Requirement: CAN-2
        $none = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), 0, null, CarbonImmutable::parse('2026-11-09'));
        $zero = $this->dates->offer(BillingCadence::Quarterly, CarbonImmutable::parse('2026-01-01'), 0, 0, CarbonImmutable::parse('2026-11-09'));

        $this->assertNull($none->minimumTermEndsOn);
        $this->assertNull($zero->minimumTermEndsOn);
        $this->assertSame('2026-12-31', $zero->earliest->toDateString());
    }

    public function test_a_zero_month_minimum_on_a_start_today_still_offers_the_first_close(): void
    {
        // Requirement: CAN-2
        $result = $this->dates->offer(BillingCadence::Annual, CarbonImmutable::parse('2026-11-09'), 0, 0, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame('2027-11-08', $result->earliest->toDateString());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function workspaceDays(): array
    {
        return [
            // The same instant is still Nov 30 in New York and already Dec 1 in UTC.
            'west of UTC, before midnight there' => ['2026-12-01T03:00:00Z', 'America/New_York', '2026-11-30'],
            'UTC at the same instant' => ['2026-12-01T03:00:00Z', 'UTC', '2026-12-31'],
            // ...and already Dec 1 in Auckland while UTC is on Nov 30.
            'east of UTC, after midnight there' => ['2026-11-30T12:00:00Z', 'Pacific/Auckland', '2026-12-31'],
            'UTC before midnight' => ['2026-11-30T12:00:00Z', 'UTC', '2026-11-30'],
        ];
    }

    #[DataProvider('workspaceDays')]
    public function test_today_is_read_as_the_workspace_day(string $instant, string $timezone, string $earliest): void
    {
        // Requirement: CAN-2
        $today = CarbonImmutable::parse($instant)->setTimezone($timezone);

        $result = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), 0, null, $today);

        $this->assertSame($earliest, $result->earliest->toDateString());
    }

    public function test_starts_on_is_read_as_the_day_it_names_in_its_own_timezone(): void
    {
        // Requirement: CAN-2
        // 23:00 on Feb 15 in Los Angeles is Feb 16 in UTC; the agreement still starts on the 15th.
        $startsOn = CarbonImmutable::parse('2026-02-15 23:00:00', 'America/Los_Angeles');

        $result = $this->dates->offer(BillingCadence::Quarterly, $startsOn, 0, null, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame('2026-11-14', $result->earliest->toDateString());
    }

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function currentEnds(): array
    {
        return [
            'open-ended' => [null, ['2026-11-30', '2026-12-31', '2027-01-31']],
            'ends after the offers' => ['2027-02-01', ['2026-11-30', '2026-12-31', '2027-01-31']],
            'ends on the last offer' => ['2027-01-31', ['2026-11-30', '2026-12-31']],
            'ends the day after the earliest' => ['2026-12-01', ['2026-11-30']],
            'ends on the earliest' => ['2026-11-30', []],
            'ended before the earliest' => ['2026-11-15', []],
        ];
    }

    /**
     * @param  list<string>  $offered
     */
    #[DataProvider('currentEnds')]
    public function test_no_date_is_offered_on_or_after_the_current_end(?string $currentEndsOn, array $offered): void
    {
        // Requirement: CAN-2
        $result = $this->dates->offer(
            BillingCadence::Monthly,
            CarbonImmutable::parse('2026-01-01'),
            0,
            null,
            CarbonImmutable::parse('2026-11-09'),
            $currentEndsOn === null ? null : CarbonImmutable::parse($currentEndsOn),
        );

        $this->assertSame('2026-11-30', $result->earliest->toDateString());
        $this->assertSame($offered, $result->offeredDateStrings());
    }

    public function test_the_current_end_is_read_as_a_workspace_day(): void
    {
        // Requirement: CAN-2
        // 20:00 on Dec 31 in New York is Jan 1 in UTC; the agreement still ends on Dec 31.
        $currentEndsOn = CarbonImmutable::parse('2026-12-31 20:00:00', 'America/New_York');

        $result = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), 0, null, CarbonImmutable::parse('2026-11-09'), $currentEndsOn);

        $this->assertSame(['2026-11-30'], $result->offeredDateStrings());
    }

    public function test_it_offers_as_many_closes_as_asked(): void
    {
        // Requirement: CAN-2
        $start = CarbonImmutable::parse('2026-01-01');
        $today = CarbonImmutable::parse('2026-11-09');

        $this->assertSame(['2026-11-30'], $this->dates->offer(BillingCadence::Monthly, $start, 0, null, $today, count: 1)->offeredDateStrings());
        $this->assertSame(
            ['2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31'],
            $this->dates->offer(BillingCadence::Monthly, $start, 0, null, $today, count: 5)->offeredDateStrings(),
        );
        $this->assertSame(3, CancellationDates::DEFAULT_OFFERED);
    }

    public function test_offers_accepts_only_an_offered_day(): void
    {
        // Requirement: CAN-2
        $result = $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), 30, null, CarbonImmutable::parse('2026-11-09'));

        $this->assertTrue($result->offers(CarbonImmutable::parse('2026-12-31')));
        $this->assertTrue($result->offers(CarbonImmutable::parse('2027-02-28')));
        // A close before the earliest, a day that is not a close, and a close past the offers.
        $this->assertFalse($result->offers(CarbonImmutable::parse('2026-11-30')));
        $this->assertFalse($result->offers(CarbonImmutable::parse('2026-12-30')));
        $this->assertFalse($result->offers(CarbonImmutable::parse('2027-03-31')));
        // Read as the day named, in its own timezone.
        $this->assertTrue($result->offers(CarbonImmutable::parse('2026-12-31 22:00:00', 'America/New_York')));
    }

    public function test_an_empty_offer_accepts_nothing(): void
    {
        // Requirement: CAN-2
        $result = new CancellationEndDates(CarbonImmutable::parse('2026-11-30'), [], CarbonImmutable::parse('2026-11-09'), null);

        $this->assertSame([], $result->offeredDateStrings());
        $this->assertFalse($result->offers(CarbonImmutable::parse('2026-11-30')));
    }

    /**
     * @return array<string, array{int, int|null, int, string}>
     */
    public static function refusals(): array
    {
        return [
            'negative notice' => [-1, null, 3, 'The notice period cannot be negative.'],
            'negative minimum term' => [0, -1, 3, 'The minimum term cannot be negative.'],
            'no dates asked for' => [0, null, 0, 'At least one end date must be offered.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses_terms_that_cannot_exist(int $noticeDays, ?int $minimumTermMonths, int $count, string $message): void
    {
        // Requirement: CAN-2
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->dates->offer(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'), $noticeDays, $minimumTermMonths, CarbonImmutable::parse('2026-11-09'), count: $count);
    }

    public function test_the_anchor_answers_only_cadence_and_start(): void
    {
        $anchor = new CancellationCycleAnchor(BillingCadence::SemiAnnual, CarbonImmutable::parse('2026-03-10'));

        $this->assertSame(BillingCadence::SemiAnnual, $anchor->effectiveBillingCadence());
        $this->assertSame('2026-03-10', $anchor->retainerStartsOn()->toDateString());
        $this->assertNull($anchor->retainerEndsOn());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonBoundaryTerms(): array
    {
        return array_combine(
            $names = [
                'effectiveFirstCycleProration',
                'retainerMonthlyHours',
                'retainerMonthlyFee',
                'periodRetainerHoursOverride',
                'periodRetainerFeeOverride',
                'periodRetainerHours',
                'retainerHoursPerMonth',
                'periodRetainerFee',
            ],
            array_map(static fn (string $name): array => [$name], $names),
        );
    }

    #[DataProvider('nonBoundaryTerms')]
    public function test_the_anchor_refuses_every_term_that_does_not_place_a_boundary(string $method): void
    {
        $anchor = new CancellationCycleAnchor(BillingCadence::Monthly, CarbonImmutable::parse('2026-01-01'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("{$method}() is not one of them.");

        $anchor->{$method}();
    }
}
