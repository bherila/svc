<?php

namespace Tests\Unit\Packages;

use App\Models\ClientAgreement;
use App\Support\Packages\AutoInvoiceOutcome;
use App\Support\Packages\AutoInvoiceRefusal;
use App\Support\Packages\AutoInvoiceResult;
use App\Support\Packages\EnrollmentStatus;
use App\Support\Packages\PackageBillingPeriods;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The automatic invoicing rules that need no database (INV-1): when a
 * package agreement's next billing period begins, and what one attempt
 * records.
 */
final class AutomaticInvoicingRulesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function periods(): iterable
    {
        $monthly = ['billing_cadence' => 'monthly', 'starts_on' => '2026-11-15'];
        $quarterly = ['billing_cadence' => 'quarterly', 'starts_on' => '2026-11-15'];
        yield 'monthly, mid-month: the 1st of the next month' => [$monthly, '2026-11-20', '2026-12-01'];
        yield 'monthly, on the 1st: the 1st of the next month' => [$monthly, '2026-12-01', '2027-01-01'];
        yield 'monthly, on the last day: tomorrow' => [$monthly, '2026-12-31', '2027-01-01'];
        yield 'monthly, across a leap February' => [['billing_cadence' => 'monthly', 'starts_on' => '2028-01-31'], '2028-02-10', '2028-03-01'];
        yield 'quarterly: the day after the quarter anchored on the start' => [$quarterly, '2026-11-15', '2027-02-15'];
        yield 'quarterly, on its last day: tomorrow' => [$quarterly, '2027-02-14', '2027-02-15'];
        yield 'quarterly, on the next quarter\'s first day' => [$quarterly, '2027-02-15', '2027-05-15'];
        yield 'annual' => [['billing_cadence' => 'annual', 'starts_on' => '2026-03-31'], '2026-11-15', '2027-03-31'];
        yield 'a cadence never set bills monthly' => [['billing_cadence' => null, 'starts_on' => '2026-11-15'], '2026-11-20', '2026-12-01'];
        yield 'not started yet: the period containing the start' => [['billing_cadence' => 'quarterly', 'starts_on' => '2026-12-10'], '2026-11-20', '2026-12-10'];
        yield 'not started yet, monthly: the month containing the start' => [['billing_cadence' => 'monthly', 'starts_on' => '2026-12-10'], '2026-11-20', '2026-12-01'];
        yield 'ending on the day before the next period: none' => [[...$monthly, 'ends_on' => '2026-11-30'], '2026-11-20', null];
        yield 'ending on the next period\'s first day: that period' => [[...$monthly, 'ends_on' => '2026-12-01'], '2026-11-20', '2026-12-01'];
        yield 'a one-time agreement: none' => [['billing_cadence' => 'one_time', 'starts_on' => '2026-11-15'], '2026-11-20', null];
        yield 'an agreement no longer active: none' => [[...$monthly, 'status' => 'terminated'], '2026-11-20', null];
    }

    /** @param array<string, mixed> $agreement */
    #[DataProvider('periods')]
    #[Requirement('INV-1')]
    public function test_the_next_period_starts_where_the_engine_starts_it(array $agreement, string $today, ?string $expected): void
    {
        $this->assertSame($expected, PackageBillingPeriods::nextPeriodStart(EnrollmentStatus::Active, $this->agreement($agreement), CarbonImmutable::parse($today)));
    }

    #[Requirement('INV-1')]
    public function test_only_a_running_service_has_a_next_period(): void
    {
        $agreement = $this->agreement(['billing_cadence' => 'monthly', 'starts_on' => '2026-11-15']);
        foreach (EnrollmentStatus::cases() as $status) {
            $next = PackageBillingPeriods::nextPeriodStart($status, $agreement, CarbonImmutable::parse('2026-11-20'));
            $this->assertSame($status === EnrollmentStatus::Active ? '2026-12-01' : null, $next, $status->value);
        }
    }

    #[Requirement('INV-1', 'INV-3')]
    public function test_an_attempt_records_its_outcome_and_a_skip_records_nothing(): void
    {
        $this->assertSame([AutoInvoiceOutcome::NothingToBill, null, null, 'nothing_to_bill'], $this->shape(AutoInvoiceResult::nothingToBill()));
        $this->assertSame([AutoInvoiceOutcome::Covered, null, null, 'covered'], $this->shape(AutoInvoiceResult::covered()));
        $this->assertSame([AutoInvoiceOutcome::Refused, AutoInvoiceRefusal::CollectedInSquare, null, 'refused'], $this->shape(AutoInvoiceResult::refused(AutoInvoiceRefusal::CollectedInSquare)));
        foreach ([AutoInvoiceResult::ALREADY_INVOICED, AutoInvoiceResult::NOT_DUE] as $why) {
            $skip = AutoInvoiceResult::skipped($why);
            $this->assertSame([null, null, null, $why], $this->shape($skip));
            $this->assertSame($why, $skip->skipped);
        }

        // The engine's words are kept, to the column's 500 characters.
        $this->assertSame('An invoice (#SYN-1) already exists', AutoInvoiceResult::refused(AutoInvoiceRefusal::PeriodUnbillable, 'An invoice (#SYN-1) already exists')->detail);
        $long = AutoInvoiceResult::refused(AutoInvoiceRefusal::IssueRefused, 'x'.str_repeat('é', 500).'tail');
        $this->assertSame('x'.str_repeat('é', 499), $long->detail);
    }

    #[Requirement('INV-1', 'INV-3')]
    public function test_every_refusal_says_what_happened(): void
    {
        $this->assertSame([
            'collected_in_square' => 'Square collects this agreement, so SVC does not invoice it.',
            'period_unbillable' => 'The billing engine refused this period.',
            'issue_refused' => 'The invoice could not be issued.',
            'failed' => 'Automatic invoicing failed unexpectedly.',
        ], array_combine(
            array_map(static fn (AutoInvoiceRefusal $refusal): string => $refusal->value, AutoInvoiceRefusal::cases()),
            array_map(static fn (AutoInvoiceRefusal $refusal): string => $refusal->message(), AutoInvoiceRefusal::cases()),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function agreement(array $attributes): ClientAgreement
    {
        return (new ClientAgreement)->forceFill(['status' => 'active', 'ends_on' => null, ...$attributes]);
    }

    /** @return array{AutoInvoiceOutcome|null, AutoInvoiceRefusal|null, string|null, string} */
    private function shape(AutoInvoiceResult $result): array
    {
        return [$result->outcome, $result->refusal, $result->invoice?->public_id, $result->label()];
    }
}
