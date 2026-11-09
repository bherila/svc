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

    /** The earliest is always the day after the earliest end a cancellation made today could choose. */
    #[DataProvider('effectiveDates')]
    #[Requirement('MIG-1')]
    public function test_a_client_told_today_can_always_leave_before_the_new_terms(BillingCadence $cadence, string $startsOn, int $notice, string $today, ?string $endsOn, ?string $invoiced): void
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

    /** @return array<string, array{array{bool, bool, string, string|null, string|null, bool}, MigrationSkip|MigrationHold|null}> */
    public static function decisions(): array
    {
        // enrollment active, carries the from version, agreement status, ends on, pending end, period invoiced
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
        ];
    }

    /** @param array{bool, bool, string, string|null, string|null, bool} $facts */
    #[DataProvider('decisions')]
    #[Requirement('MIG-3')]
    public function test_on_the_date_each_subscriber_is_moved_skipped_or_held(array $facts, MigrationSkip|MigrationHold|null $expected): void
    {
        [$active, $carries, $status, $endsOn, $pendingEnd, $invoiced] = $facts;
        $decision = MigrationRules::decide($active, $carries, $status, $endsOn === null ? null : self::day($endsOn), self::day('2027-01-01'), $pendingEnd === null ? null : self::day($pendingEnd), $invoiced);

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
    public function test_a_move_may_be_cancelled_only_while_scheduled_and_before_its_date(): void
    {
        $date = self::day('2027-01-01');
        $this->assertNull(MigrationRules::cancelRefusal(MigrationStatus::Scheduled, $date, self::day('2026-12-31')));
        $this->assertSame(MigrationRefusal::EffectiveDateReached, MigrationRules::cancelRefusal(MigrationStatus::Scheduled, $date, $date));
        $this->assertSame(MigrationRefusal::NotScheduled, MigrationRules::cancelRefusal(MigrationStatus::Cancelled, $date, self::day('2026-12-31')));
        $this->assertSame(MigrationRefusal::NotScheduled, MigrationRules::cancelRefusal(MigrationStatus::Completed, $date, self::day('2026-12-31')));
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
