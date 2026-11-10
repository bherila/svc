<?php

namespace Tests\Unit\Packages;

use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Packages\PackageAgreementTerms;
use App\Support\Billing\BillingCadence;
use App\Support\Packages\Migration\ContinuedRecurrence;
use App\Support\Packages\Migration\MigrationDates;
use App\Support\Packages\Migration\MigrationRefusal;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationTermChanges;
use App\Support\Packages\PackageTerms;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The boundaries and wording of the package move rules (slice 6), pinned
 * without a database: each case names the mutation it is there to catch.
 */
#[CoversClass(MigrationRefusal::class)]
#[CoversClass(MigrationTermChanges::class)]
#[CoversClass(MigrationRules::class)]
#[CoversClass(MigrationDates::class)]
#[CoversClass(ContinuedRecurrence::class)]
#[CoversClass(PackageAgreementTerms::class)]
#[CoversClass(ServicePackageOperations::class)]
final class MigrationMutationTest extends TestCase
{
    /** @return array<string, array{MigrationRefusal, string}> */
    public static function refusalMessages(): array
    {
        return [
            'NotNewer' => [MigrationRefusal::NotNewer, 'Subscribers can only move to the package\'s published version, from an older version of the same package.'],
            'NotRecurring' => [MigrationRefusal::NotRecurring, 'This package does not bill in periods, so there is no period start for a move to take effect at.'],
            'NoSubscribers' => [MigrationRefusal::NoSubscribers, 'Choose at least one subscriber to move.'],
            'NotASubscriber' => [MigrationRefusal::NotASubscriber, 'A chosen subscriber is not an active service on the version being moved from.'],
            'AlreadyScheduled' => [MigrationRefusal::AlreadyScheduled, 'A chosen subscriber is already part of another scheduled move. Cancel that move first.'],
            'EndsFirst' => [MigrationRefusal::EndsFirst, 'This agreement ends before the earliest date its notice period allows, so it keeps its terms.'],
            'DateNotOffered' => [MigrationRefusal::DateNotOffered, 'That effective date is not a billing period start after every chosen subscriber\'s notice period. Choose one of the offered dates, or leave out the subscribers it does not suit.'],
            'CurrencyChanged' => [MigrationRefusal::CurrencyChanged, 'The new version bills in another currency. A move keeps a subscriber\'s currency; offer the new version as a new service instead.'],
            'NotScheduled' => [MigrationRefusal::NotScheduled, 'This move is no longer scheduled: it has completed or was cancelled.'],
            'BillsByUsage' => [MigrationRefusal::BillsByUsage, 'This agreement bills by usage (an hourly rate or a retainer). Its last period\'s work is invoiced after the period ends, on the invoice that also opens the next period, so the billing engine cannot yet settle it on the old terms beside a new agreement on the same project. End it and offer the new version instead.'],
            'ChangesCadence' => [MigrationRefusal::ChangesCadence, 'The new version bills on another cadence than this agreement. The first longer or shorter period would claim work periods the current terms already invoiced, so the billing engine could not invoice it. A move keeps a subscriber\'s billing cadence; end the agreement and offer the new version as a new service instead.'],
            'OffCycleComponent' => [MigrationRefusal::OffCycleComponent, 'The current or the new version has a recurring charge on another cadence than the service (an annual licence in a monthly service, or a one-time charge). The agreement carrying the new terms would bill it from its own start, charging again what the client already paid for. End the agreement and offer the new version as a new service instead.'],
            'AmbiguousRecurrence' => [MigrationRefusal::AmbiguousRecurrence, 'This agreement\'s recurring charges do not follow one schedule (an item was added or re-anchored by hand, or starts later), so the agreement carrying the new terms could not continue it without charging a period again or skipping one. Align the items, or end the agreement and offer the new version as a new service instead.'],
            'SquareCollected' => [MigrationRefusal::SquareCollected, 'Square collects this agreement\'s invoices, and SVC never reshapes what Square collects. Change it in Square, or end it and offer the new version as a new service instead.'],
        ];
    }

    #[DataProvider('refusalMessages')]
    #[Requirement('MIG-1')]
    public function test_every_refusal_says_why_in_its_own_words(MigrationRefusal $refusal, string $message): void
    {
        $this->assertSame($message, $refusal->message());
    }

    /** @return array<string, array{array<string, mixed>, string, string}> overrides, term, the words a reader sees */
    public static function words(): array
    {
        $recurring = static fn (string $cadence, int $amount = 1000, string $description = 'Item'): array => ['kind' => 'recurring', 'description' => $description, 'cadence' => $cadence, 'amount' => $amount];

        return [
            'name' => [['name' => 'Gold care'], 'name', 'Gold care'],
            'monthly billing' => [['billing_cadence' => 'monthly'], 'billing_cadence', 'Monthly'],
            'quarterly billing' => [['billing_cadence' => 'quarterly'], 'billing_cadence', 'Quarterly'],
            'semi-annual billing' => [['billing_cadence' => 'semi_annual'], 'billing_cadence', 'Every six months'],
            'annual billing' => [['billing_cadence' => 'annual'], 'billing_cadence', 'Yearly'],
            'one-time billing' => [['billing_cadence' => 'one_time'], 'billing_cadence', 'Once'],
            'a monthly price' => [['billing_cadence' => 'monthly', 'retainer_amount' => 60000], 'cycle_amount', '$600.00 a month'],
            'a quarterly price' => [['billing_cadence' => 'quarterly', 'retainer_amount' => 60000], 'cycle_amount', '$600.00 a quarter'],
            'a semi-annual price' => [['billing_cadence' => 'semi_annual', 'retainer_amount' => 60000], 'cycle_amount', '$600.00 every six months'],
            'an annual price' => [['billing_cadence' => 'annual', 'retainer_amount' => 60000], 'cycle_amount', '$600.00 a year'],
            'a one-time price' => [['billing_cadence' => 'one_time', 'retainer_amount' => 60000], 'cycle_amount', '$600.00 once'],
            'components by cadence' => [['components' => [$recurring('monthly', 100, 'A'), $recurring('quarterly', 200, 'B'), $recurring('semi_annual', 300, 'C'), $recurring('annual', 400, 'D'), $recurring('one_time', 500, 'E')]], 'components', 'A: $1.00 monthly; B: $2.00 quarterly; C: $3.00 every six months; D: $4.00 yearly; E: $5.00 once'],
            'a setup fee is not a recurring component' => [['components' => [['kind' => 'setup_fee', 'description' => 'Onboarding', 'amount' => 5000], $recurring('monthly', 100, 'A')]], 'components', 'A: $1.00 monthly'],
            'nothing recurring' => [['components' => [['kind' => 'setup_fee', 'description' => 'Onboarding', 'amount' => 5000]]], 'components', 'Nothing recurring'],
            'an hourly rate' => [['hourly_rate_amount' => 12500], 'hourly_rate_amount', '$125.00 an hour'],
            'no hourly rate' => [['hourly_rate_amount' => null], 'hourly_rate_amount', 'None'],
            'a retainer' => [['billing_cadence' => 'quarterly', 'retainer_amount' => 90000], 'retainer_amount', '$900.00 a quarter'],
            'no retainer' => [['retainer_amount' => null], 'retainer_amount', 'None'],
            'one hour' => [['retainer_minutes' => 60], 'retainer_minutes', '1 hour a month'],
            'an hour and a half' => [['retainer_minutes' => 90], 'retainer_minutes', '1.5 hours a month'],
            'ten hours' => [['billing_cadence' => 'annual', 'retainer_minutes' => 600], 'retainer_minutes', '10 hours a year'],
            'a third of an hour rounds to two places' => [['retainer_minutes' => 100], 'retainer_minutes', '1.67 hours a month'],
            'half an hour' => [['billing_cadence' => 'semi_annual', 'retainer_minutes' => 30], 'retainer_minutes', '0.5 hours every six months'],
            'no retainer hours' => [['retainer_minutes' => null], 'retainer_minutes', 'None'],
            'no rollover' => [['rollover_months' => 0], 'rollover_months', 'No'],
            'a rollover left unset' => [['rollover_months' => null], 'rollover_months', 'No'],
            'a month of rollover' => [['rollover_months' => 1], 'rollover_months', '1 month'],
            'months of rollover' => [['rollover_months' => 2], 'rollover_months', '2 months'],
            'no notice' => [['notice_days' => 0], 'notice_days', 'None'],
            'a day of notice' => [['notice_days' => 1], 'notice_days', '1 day'],
            'days of notice' => [['notice_days' => 14], 'notice_days', '14 days'],
            'cancelling by contact' => [['cancellation_mode' => 'none'], 'cancellation_mode', 'By contacting us'],
            'cancelling on request' => [['cancellation_mode' => 'request'], 'cancellation_mode', 'On request from the client portal'],
            'cancelling self-serve' => [['cancellation_mode' => 'self_serve'], 'cancellation_mode', 'Self-serve from the client portal'],
            'invoices issued automatically' => [['invoice_issuing' => 'automatic'], 'invoice_issuing', 'Issued automatically'],
            'invoices drafted' => [['invoice_issuing' => 'draft'], 'invoice_issuing', 'Drafted for review'],
            'autopay required' => [['require_autopay' => true, 'autopay_limit_amount' => 75000], 'require_autopay', 'Required, up to $750.00'],
            'autopay required without a limit' => [['require_autopay' => true, 'autopay_limit_amount' => null], 'require_autopay', 'Required, up to $0.00'],
            'autopay not required' => [['require_autopay' => false], 'require_autopay', 'Not required'],
            'a setup fee' => [['components' => [['kind' => 'setup_fee', 'description' => 'Onboarding', 'amount' => 5000]]], 'setup_fee', '$50.00'],
            'no setup fee' => [['components' => []], 'setup_fee', 'None'],
            'no minimum term' => [['minimum_term_months' => 0], 'minimum_term_months', 'None'],
            'a minimum term left unset' => [['minimum_term_months' => null], 'minimum_term_months', 'None'],
            'a one month minimum term' => [['minimum_term_months' => 1], 'minimum_term_months', '1 month'],
            'a twelve month minimum term' => [['minimum_term_months' => 12], 'minimum_term_months', '12 months'],
            'automatic activation' => [['activation_mode' => 'automatic'], 'activation_mode', 'Starts automatically'],
            'reviewed activation' => [['activation_mode' => 'review'], 'activation_mode', 'Reviewed first'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('words')]
    #[Requirement('MIG-2')]
    public function test_the_owner_reads_each_term_in_words(array $overrides, string $term, string $expected): void
    {
        // Two baselines that differ in every term, so the value under test is
        // listed as a change from at least one of them.
        $to = PackageTerms::fromArray([...self::terms(), ...$overrides]);
        $changes = [
            ...MigrationTermChanges::forOwner(PackageTerms::fromArray(self::baselineOne()), $to),
            ...MigrationTermChanges::forOwner(PackageTerms::fromArray(self::baselineTwo()), $to),
        ];
        $values = array_values(array_unique(array_column(array_filter($changes, static fn (array $change): bool => $change['term'] === $term), 'to')));

        $this->assertSame([$expected], $values);
    }

    public function test_the_client_is_shown_a_changed_name_and_the_owner_the_new_terms_too(): void
    {
        $from = PackageTerms::fromArray(self::baselineTwo());
        $to = PackageTerms::fromArray([...self::terms(), 'name' => 'Renamed', 'invoice_issuing' => 'automatic']);

        $client = array_column(MigrationTermChanges::forClient($from, $to), 'to', 'term');
        $owner = array_column(MigrationTermChanges::forOwner($from, $to), 'to', 'term');

        $this->assertSame('Renamed', $client['name']);
        $this->assertArrayNotHasKey('invoice_issuing', $client);
        $this->assertSame('Renamed', $owner['name']);
        $this->assertSame('Issued automatically', $owner['invoice_issuing']);
        $this->assertSame('Service', array_column(MigrationTermChanges::forOwner($from, $to), 'label', 'term')['name']);
    }

    /** @return array<string, mixed> */
    private static function terms(): array
    {
        return ['name' => 'Plan', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'components' => []];
    }

    /** @return array<string, mixed> */
    private static function baselineOne(): array
    {
        return [
            'name' => 'Baseline one', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'hourly_rate_amount' => 1, 'retainer_amount' => 1, 'retainer_minutes' => 1,
            'rollover_months' => 5, 'notice_days' => 5, 'cancellation_mode' => 'none', 'invoice_issuing' => 'automatic', 'require_autopay' => true, 'autopay_limit_amount' => 1,
            'minimum_term_months' => 5, 'activation_mode' => 'automatic',
            'components' => [['kind' => 'recurring', 'description' => 'One', 'cadence' => 'monthly', 'amount' => 1], ['kind' => 'setup_fee', 'description' => 'Fee', 'amount' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private static function baselineTwo(): array
    {
        return [
            'name' => 'Baseline two', 'currency' => 'USD', 'billing_cadence' => 'quarterly', 'hourly_rate_amount' => 2, 'retainer_amount' => 2, 'retainer_minutes' => 2,
            'rollover_months' => 6, 'notice_days' => 6, 'cancellation_mode' => 'request', 'invoice_issuing' => 'draft', 'require_autopay' => false,
            'minimum_term_months' => 6, 'activation_mode' => 'review',
            'components' => [['kind' => 'recurring', 'description' => 'Two', 'cadence' => 'quarterly', 'amount' => 2]],
        ];
    }

    /** @return array<string, array{BillingCadence, string, list<array{cadence: string, effective_on: null, anchor_day: int|null, anchor_month: int|null}>, array{effective_on: string, anchor_day: int|null, anchor_month: int|null, follows: bool}|null}> */
    public static function schedules(): array
    {
        $item = static fn (string $cadence, ?int $day, ?int $month): array => ['cadence' => $cadence, 'effective_on' => null, 'anchor_day' => $day, 'anchor_month' => $month];
        $q = BillingCadence::Quarterly;
        $a = BillingCadence::Annual;

        return [
            'a day of zero is the first' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly', 0, null)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => null, 'follows' => false]],
            'a month of zero is January' => [$q, '2026-01-01', [$item('quarterly', 1, 0)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => 1, 'follows' => false]],
            'December stays December' => [$a, '2026-01-01', [$item('annual', 1, 12)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => 12, 'follows' => false]],
            'a month past December is December' => [$a, '2026-01-01', [$item('annual', 1, 13)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => 12, 'follows' => false]],
            'a day and month that read alike are still two places' => [$a, '2026-01-01', [$item('annual', 1, 11), $item('annual', 11, 1)], null],
            'unanchored items follow the billing periods' => [$q, '2026-01-01', [$item('quarterly', null, null)], ['effective_on' => '2026-01-01', 'anchor_day' => null, 'anchor_month' => null, 'follows' => true]],
            'anchored items do not' => [$q, '2026-01-01', [$item('quarterly', 1, 1)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => 1, 'follows' => false]],
            'a monthly item with no anchor is placed, not following periods' => [BillingCadence::Monthly, '2026-01-01', [$item('monthly', null, null)], ['effective_on' => '2026-01-01', 'anchor_day' => 1, 'anchor_month' => null, 'follows' => false]],
        ];
    }

    /**
     * @param  list<array{cadence: string, effective_on: null, anchor_day: int|null, anchor_month: int|null}>  $items
     * @param  array{effective_on: string, anchor_day: int|null, anchor_month: int|null, follows: bool}|null  $expected
     */
    #[DataProvider('schedules')]
    #[Requirement('MIG-3')]
    public function test_the_continued_schedule_is_clamped_and_told_apart_exactly(BillingCadence $cadence, string $startsOn, array $items, ?array $expected): void
    {
        $recurrence = ContinuedRecurrence::fromItems($cadence, CarbonImmutable::parse($startsOn), CarbonImmutable::parse('2027-01-01'), $items);

        $this->assertSame($expected, $recurrence === null ? null : [...$recurrence->itemColumns(), 'follows' => $recurrence->followsBillingCycles]);
    }

    #[Requirement('MIG-1')]
    public function test_one_effective_date_may_be_asked_for_and_no_more_are_offered_than_asked(): void
    {
        $dates = new MigrationDates;
        $day = static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC');

        $this->assertCount(1, $dates->offer(BillingCadence::Monthly, $day('2026-01-01'), 0, $day('2026-11-09'), count: 1)->offered);
        // A far end date does not lengthen the list.
        $this->assertCount(2, $dates->offer(BillingCadence::Monthly, $day('2026-01-01'), 0, $day('2026-11-09'), endsOn: $day('2030-01-01'), count: 2)->offered);
        $this->assertCount(3, $dates->offer(BillingCadence::Monthly, $day('2026-01-01'), 0, $day('2026-11-09'), endsOn: $day('2027-02-15'), count: 6)->offered);
    }

    #[Requirement('MIG-3')]
    public function test_the_boundaries_of_a_hold_a_deferred_period_and_a_minimum_term(): void
    {
        $day = static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC');

        // Asking to leave on the very day the new terms start does not hold the move.
        $this->assertTrue(MigrationRules::decide(true, true, 'active', null, $day('2027-01-01'), $day('2027-01-01'), false, false, false, false, false, false, false)->applies());
        // A deferred period starting on the agreement's first day is owed.
        $this->assertTrue(MigrationRules::owesDeferredPeriod($day('2027-01-01'), $day('2027-01-31'), $day('2027-01-01'), $day('2027-02-01'), $day('2027-01-01'), null));
        // A one month term, and an agreement that starts after the move with none.
        $this->assertSame(1, MigrationRules::remainingMinimumTerm($day('2026-12-15'), 1, $day('2027-01-01')));
        $this->assertNull(MigrationRules::remainingMinimumTerm($day('2027-06-01'), null, $day('2027-01-01')));
        $this->assertNull(MigrationRules::remainingMinimumTerm($day('2027-06-01'), 0, $day('2027-01-01')));
        $this->assertNull(MigrationRules::remainingMinimumTerm($day('2027-06-01'), -3, $day('2027-01-01')));
        // A negative figure is not usage.
        $this->assertFalse(MigrationRules::billsByUsage(null, -1, -1, -1, -1));
        $this->assertFalse(MigrationRules::billsByUsage(null, null, null, null, -1));
    }

    /** @return array<string, array{string, int|null, int|null, array<string, int|null>}> */
    public static function retainers(): array
    {
        return [
            'monthly: the cycle figures, no period figures' => ['monthly', 200000, 100, ['retainer_amount' => 200000, 'retainer_minutes' => 100, 'period_retainer_amount' => null, 'period_retainer_minutes' => null]],
            'one-time: read as one month' => ['one_time', 200000, 100, ['retainer_amount' => 200000, 'retainer_minutes' => 100, 'period_retainer_amount' => null, 'period_retainer_minutes' => null]],
            'quarterly: a third, rounded to nearest, up' => ['quarterly', 200000, 200, ['retainer_amount' => 66667, 'retainer_minutes' => 67, 'period_retainer_amount' => 200000, 'period_retainer_minutes' => 200]],
            'quarterly: a third, rounded to nearest, down' => ['quarterly', 100000, 100, ['retainer_amount' => 33333, 'retainer_minutes' => 33, 'period_retainer_amount' => 100000, 'period_retainer_minutes' => 100]],
            'annual: a twelfth' => ['annual', 120000, 600, ['retainer_amount' => 10000, 'retainer_minutes' => 50, 'period_retainer_amount' => 120000, 'period_retainer_minutes' => 600]],
            'quarterly with neither' => ['quarterly', null, null, ['retainer_amount' => null, 'retainer_minutes' => null, 'period_retainer_amount' => null, 'period_retainer_minutes' => null]],
        ];
    }

    /** @param array<string, int|null> $expected */
    #[DataProvider('retainers')]
    #[Requirement('PKG-7')]
    public function test_a_retainer_is_split_into_monthly_figures_beside_the_period_ones(string $cadence, ?int $amount, ?int $minutes, array $expected): void
    {
        $terms = PackageTerms::fromArray([...self::terms(), 'billing_cadence' => $cadence, 'retainer_amount' => $amount, 'retainer_minutes' => $minutes]);

        $this->assertSame($expected, PackageAgreementTerms::retainerTerms($terms));
    }

    #[Requirement('MIG-1')]
    public function test_a_decision_field_brings_its_nested_rules_and_no_others(): void
    {
        $this->assertSame(['enrollment_ids', 'enrollment_ids.*'], array_keys(ServicePackageOperations::rulesFor(['enrollment_ids'])));
        $this->assertSame(['effective_on'], array_keys(ServicePackageOperations::rulesFor(['effective_on'])));
        $this->assertSame([], ServicePackageOperations::rulesFor(['unknown']));
    }
}
