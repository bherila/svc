<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoiceHoursStatement;
use App\Support\Billing\InvoiceHoursStatementRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The stored shape of an hours statement and how it is laid out, without a database. */
final class InvoiceHoursStatementTest extends TestCase
{
    public function test_the_stored_shape_round_trips_through_json(): void
    {
        $statement = $this->statement();

        $decoded = json_decode((string) json_encode($statement->toArray()), true);

        $this->assertSame(InvoiceHoursStatement::VERSION, $decoded['version']);
        $this->assertEquals($statement, InvoiceHoursStatement::fromArray($decoded));
        // JSON turns 10.0 into 10; it still reads back as hours.
        $this->assertSame(10.0, InvoiceHoursStatement::fromArray($decoded)?->openingRetainerHours);
        $this->assertSame(2, InvoiceHoursStatement::fromArray($decoded)?->deferredAppliedEntries);
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function unreadable(): iterable
    {
        $valid = ['version' => InvoiceHoursStatement::VERSION, 'cadence' => 'monthly', 'workStart' => '2026-01-01', 'workEnd' => '2026-01-31'];

        yield 'absent' => [null];
        yield 'another version' => [['version' => InvoiceHoursStatement::VERSION + 1] + $valid];
        yield 'no version' => [array_diff_key($valid, ['version' => true])];
        yield 'no cadence' => [array_diff_key($valid, ['cadence' => true])];
        yield 'no work start' => [array_diff_key($valid, ['workStart' => true])];
        yield 'no work end' => [array_diff_key($valid, ['workEnd' => true])];
        yield 'work end not text' => [['workEnd' => 20260131] + $valid];
    }

    /** @param array<string, mixed>|null $stored */
    #[DataProvider('unreadable')]
    public function test_an_unreadable_statement_reads_as_none(?array $stored): void
    {
        $this->assertNull(InvoiceHoursStatement::fromArray($stored));
    }

    public function test_missing_or_malformed_figures_read_as_zero(): void
    {
        $statement = InvoiceHoursStatement::fromArray([
            'version' => InvoiceHoursStatement::VERSION, 'cadence' => 'monthly',
            'workStart' => '2026-01-01', 'workEnd' => '2026-01-31',
            'openingRetainerHours' => '10', 'ordinaryHours' => 1.23456, 'deferredAppliedEntries' => 2.5,
            'retainerStart' => 5,
        ]);

        $this->assertNotNull($statement);
        $this->assertSame(0.0, $statement->openingRetainerHours);
        $this->assertSame(1.2346, $statement->ordinaryHours);
        $this->assertSame(0, $statement->deferredAppliedEntries);
        $this->assertNull($statement->retainerStart);
    }

    public function test_the_net_positions_are_retainer_plus_rollover_less_deficit(): void
    {
        $statement = $this->statement();

        $this->assertSame(10.0 + 1.5 - 2.25, $statement->openingNetHours());
        $this->assertSame(10.0 + 4.0 - 9.0, $statement->closingNetHours());
    }

    public function test_the_layout_prints_core_rows_and_only_the_optional_rows_that_carry_hours(): void
    {
        $sections = InvoiceHoursStatementRows::for($this->statement());

        $this->assertSame([
            'Opening pool: January 2026',
            'Work reconciled on this invoice',
            'Catch-up billed at the hourly rate',
            'Carried forward',
            'Closing position: February 2026',
        ], array_column($sections, 'title'));

        $this->assertSame([
            ['label' => 'Retainer hours for January 2026', 'hours' => '10.00', 'kind' => 'row'],
            ['label' => 'Unused hours rolled in from earlier periods', 'hours' => '1.50', 'kind' => 'row'],
            ['label' => 'Hours owed from earlier periods', 'hours' => '-2.25', 'kind' => 'row'],
            ['label' => 'Net hours available at the start of January 2026', 'hours' => '9.25', 'kind' => 'total'],
            ['label' => 'Unused hours that expired at the start of January 2026', 'hours' => '0.50', 'kind' => 'note'],
        ], $sections[0]['rows']);

        $this->assertSame([
            ['label' => 'Hours worked in January 2026', 'hours' => '22.00', 'kind' => 'row'],
            ['label' => 'Applied to the January 2026 pool', 'hours' => '10.00', 'kind' => 'detail'],
            ['label' => 'Applied in advance to the February 2026 retainer', 'hours' => '10.00', 'kind' => 'detail'],
            ['label' => 'Billed at the hourly rate', 'hours' => '2.00', 'kind' => 'detail'],
            ['label' => 'Deferred work applied to free capacity (2 entries)', 'hours' => '3.00', 'kind' => 'row'],
            ['label' => 'Earlier deferred work settled from free capacity', 'hours' => '0.75', 'kind' => 'row'],
            ['label' => 'Deferred work billed at the hourly rate on termination', 'hours' => '1.00', 'kind' => 'row'],
            ['label' => 'Already billed in this cycle by interim invoices', 'hours' => '0.50', 'kind' => 'row'],
        ], $sections[1]['rows']);

        $this->assertSame([
            ['label' => 'Work beyond the available pool', 'hours' => '2.00', 'kind' => 'row'],
            ['label' => 'Minimum availability: restores the agreement\'s 1.00-hour minimum for February 2026', 'hours' => '1.00', 'kind' => 'row'],
            ['label' => 'Total catch-up billed on this invoice', 'hours' => '3.00', 'kind' => 'total'],
        ], $sections[2]['rows']);

        $this->assertSame([
            ['label' => 'Unused hours that expired within January 2026', 'hours' => '0.25', 'kind' => 'row'],
            ['label' => 'Unused hours rolling into February 2026', 'hours' => '4.00', 'kind' => 'row'],
            ['label' => 'Unused hours expiring at the start of February 2026', 'hours' => '0.00', 'kind' => 'row'],
            ['label' => 'Hours still owed, carried into February 2026', 'hours' => '9.00', 'kind' => 'row'],
            ['label' => 'Deferred work waiting for free capacity (1 entry)', 'hours' => '5.00', 'kind' => 'row'],
            ['label' => 'Earlier deferred work still to settle', 'hours' => '1.25', 'kind' => 'row'],
        ], $sections[3]['rows']);

        $this->assertSame([
            ['label' => 'Retainer hours for February 2026', 'hours' => '10.00', 'kind' => 'row'],
            ['label' => 'Unused hours rolled in', 'hours' => '4.00', 'kind' => 'row'],
            ['label' => 'Hours owed carried in', 'hours' => '-9.00', 'kind' => 'row'],
            ['label' => 'Net hours available at the start of February 2026', 'hours' => '5.00', 'kind' => 'total'],
        ], $sections[4]['rows']);
    }

    public function test_a_quiet_period_prints_no_optional_rows(): void
    {
        $sections = InvoiceHoursStatementRows::for($this->statement(quiet: true));

        $this->assertSame(
            ['Retainer hours for January 2026', 'Unused hours rolled in from earlier periods', 'Hours owed from earlier periods', 'Net hours available at the start of January 2026'],
            array_column($sections[0]['rows'], 'label'),
        );
        $this->assertSame(
            ['Hours worked in January 2026', 'Applied to the January 2026 pool', 'Billed at the hourly rate'],
            array_column($sections[1]['rows'], 'label'),
        );
        // No minimum to restore, so no row claiming one.
        $this->assertSame(
            ['Work beyond the available pool', 'Total catch-up billed on this invoice'],
            array_column($sections[2]['rows'], 'label'),
        );
        $this->assertSame(
            ['Unused hours rolling into February 2026', 'Unused hours expiring at the start of February 2026', 'Hours still owed, carried into February 2026'],
            array_column($sections[3]['rows'], 'label'),
        );
    }

    public function test_one_deferred_entry_is_singular_and_a_statement_with_no_next_period_says_so(): void
    {
        $statement = new InvoiceHoursStatement(...[...$this->figures(), 'deferredAppliedEntries' => 1, 'retainerStart' => null]);

        $sections = InvoiceHoursStatementRows::for($statement);

        $this->assertContains('Deferred work applied to free capacity (1 entry)', array_column($sections[1]['rows'], 'label'));
        $this->assertSame('Closing position: the next period', $sections[4]['title']);
    }

    public function test_hours_print_to_two_places_without_a_negative_zero(): void
    {
        $this->assertSame('0.00', InvoiceHoursStatementRows::hours(-0.001));
        $this->assertSame('0.00', InvoiceHoursStatementRows::hours(-0.0));
        $this->assertSame('1,234.57', InvoiceHoursStatementRows::hours(1234.567));
        $this->assertSame('-9.00', InvoiceHoursStatementRows::hours(-9.0));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function periods(): iterable
    {
        yield 'one month' => ['2026-01-01', '2026-01-31', 'January 2026'];
        yield 'a quarter' => ['2026-01-01', '2026-03-31', 'January – March 2026'];
        yield 'across a year' => ['2025-11-01', '2026-01-31', 'November 2025 – January 2026'];
        yield 'a partial month' => ['2026-01-15', '2026-01-31', 'Jan 15, 2026 – Jan 31, 2026'];
        yield 'ending mid-month' => ['2026-01-01', '2026-01-20', 'Jan 1, 2026 – Jan 20, 2026'];
    }

    #[DataProvider('periods')]
    public function test_a_period_is_named_the_way_a_client_names_it(string $start, string $end, string $label): void
    {
        $this->assertSame($label, InvoiceHoursStatementRows::period($start, $end));
    }

    private function statement(bool $quiet = false): InvoiceHoursStatement
    {
        $figures = $this->figures();
        if ($quiet) {
            foreach (['openingExpiredHours', 'expiredWithinPeriodHours', 'ordinaryAppliedToNextRetainer', 'deferredAppliedHours', 'recarriedSettledHours',
                'deferredBilledOnTerminationHours', 'interimBilledHours', 'minimumAvailabilityHours', 'minimumAvailabilityThresholdHours',
                'deferredBacklogHours', 'recarriedRemainingHours'] as $key) {
                $figures[$key] = 0.004;
            }
        }

        return new InvoiceHoursStatement(...$figures);
    }

    /** @return array<string, mixed> */
    private function figures(): array
    {
        return [
            'cadence' => 'monthly', 'workStart' => '2026-01-01', 'workEnd' => '2026-01-31',
            'retainerStart' => '2026-02-01', 'retainerEnd' => '2026-02-28',
            'openingRetainerHours' => 10.0, 'openingRolloverHours' => 1.5, 'openingDeficitHours' => 2.25,
            'openingExpiredHours' => 0.5, 'expiredWithinPeriodHours' => 0.25,
            'ordinaryHours' => 22.0, 'ordinaryAppliedToWorkPool' => 10.0, 'ordinaryAppliedToNextRetainer' => 10.0, 'ordinaryBilledAtRate' => 2.0,
            'deferredAppliedHours' => 3.0, 'deferredAppliedEntries' => 2, 'recarriedSettledHours' => 0.75,
            'deferredBilledOnTerminationHours' => 1.0,
            'catchUpBilledHours' => 3.0, 'minimumAvailabilityHours' => 1.0, 'minimumAvailabilityThresholdHours' => 1.0,
            'interimBilledHours' => 0.5,
            'rolledForwardHours' => 4.0, 'expiringHours' => 0.0, 'deficitCarriedForwardHours' => 9.0,
            'deferredBacklogHours' => 5.0, 'deferredBacklogEntries' => 1, 'recarriedRemainingHours' => 1.25,
            'nextRetainerHours' => 10.0,
        ];
    }
}
