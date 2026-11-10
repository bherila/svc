<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\FirstCycleChargeFinding;
use App\Support\Billing\FirstCycleChargeOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading what an agreement's first billing periods charged its recurring
 * items (#685, #695) against what the engine places in them, without a
 * database.
 */
final class FirstCycleChargeFindingTest extends TestCase
{
    /** @return iterable<string, list<mixed>> */
    public static function firstPeriods(): iterable
    {
        $invoice = static fn (string $status, array $lines, array $expected = [1 => 1, 2 => 1], string $id = 'synthetic-invoice'): array => ['public_id' => $id, 'status' => $status, 'currency' => 'USD', 'expected' => $expected, 'lines' => $lines];
        $line = static fn (int $item, string $date, int $id, int $total): array => ['item' => $item, 'line_date' => $date, 'id' => $id, 'total' => $total];

        yield 'no invoice' => [[], FirstCycleChargeOutcome::NotInvoiced, null, 0, 0];
        yield 'each item once' => [[$invoice('issued', [$line(1, '2026-01-09', 1, 12000), $line(2, '2026-01-09', 2, 700)])], FirstCycleChargeOutcome::ChargedOnce, 'issued', 0, 0];
        // The later line is the extra one, whatever order the rows come in.
        yield 'an item twice, issued' => [[$invoice('partially_paid', [$line(1, '2026-04-01', 1, 9000), $line(1, '2026-01-09', 2, 12000)])], FirstCycleChargeOutcome::IssuedWithDuplicate, 'partially_paid', 1, 9000];
        yield 'on the same day, the later row is the extra' => [[$invoice('paid', [$line(1, '2026-01-09', 7, 500), $line(1, '2026-01-09', 3, 12000)])], FirstCycleChargeOutcome::IssuedWithDuplicate, 'paid', 1, 500];
        yield 'two items twice, disputed' => [[$invoice('disputed', [$line(1, '2026-01-09', 1, 12000), $line(1, '2026-04-01', 2, 12000), $line(2, '2026-01-09', 3, 700), $line(2, '2026-04-01', 4, 700)])], FirstCycleChargeOutcome::IssuedWithDuplicate, 'disputed', 2, 12700];
        yield 'a draft twice' => [[$invoice('draft', [$line(1, '2026-01-09', 1, 12000), $line(1, '2026-04-01', 2, 12000)])], FirstCycleChargeOutcome::DraftWithDuplicate, 'draft', 1, 12000];
        // #695: a monthly item in a quarter from the 9th charged four times.
        $monthly = [$line(1, '2026-03-01', 3, 4500), $line(1, '2026-01-09', 1, 4500), $line(1, '2026-04-01', 4, 4600), $line(1, '2026-02-01', 2, 4500)];
        yield 'a monthly item four times in a quarter' => [[$invoice('issued', $monthly, [1 => 3])], FirstCycleChargeOutcome::IssuedWithDuplicate, 'issued', 1, 4600];
        yield 'a monthly item three times in a quarter' => [[$invoice('issued', array_slice($monthly, 0, 2), [1 => 3])], FirstCycleChargeOutcome::ChargedOnce, 'issued', 0, 0];
        // An annual licence in a quarterly service, charged again on 1 January
        // in its fourth quarter, where the engine places none.
        yield 'an annual item again in its fourth quarter' => [[
            $invoice('paid', [$line(1, '2026-01-09', 1, 48000)], [1 => 1], 'synthetic-first'),
            $invoice('draft', [], [], 'synthetic-second'),
            $invoice('issued', [$line(1, '2027-01-01', 9, 48000)], [], 'synthetic-fourth'),
        ], FirstCycleChargeOutcome::IssuedWithDuplicate, 'issued', 1, 48000, 'synthetic-fourth'];
        // An extra line issued outweighs one still in draft, and only the issued one is counted.
        yield 'issued and in draft' => [[
            $invoice('draft', [$line(1, '2026-01-09', 1, 12000), $line(1, '2026-04-01', 2, 12000)], [1 => 1], 'synthetic-draft'),
            $invoice('paid', [$line(1, '2026-07-01', 3, 3000)], [], 'synthetic-paid'),
        ], FirstCycleChargeOutcome::IssuedWithDuplicate, 'paid', 1, 3000, 'synthetic-paid'];
        yield 'two drafts' => [[
            $invoice('issued', [$line(1, '2026-01-09', 1, 12000)], [1 => 1], 'synthetic-issued'),
            $invoice('draft', [$line(1, '2026-07-01', 3, 3000)], [], 'synthetic-draft'),
        ], FirstCycleChargeOutcome::DraftWithDuplicate, 'draft', 1, 3000, 'synthetic-draft'];
    }

    /**
     * @param  list<array{public_id: string, status: string, currency: string, expected: array<int, int>, lines: list<array{item: int, line_date: string, id: int, total: int}>}>  $invoices
     */
    #[DataProvider('firstPeriods')]
    public function test_the_first_periods_are_read_from_their_invoices(array $invoices, FirstCycleChargeOutcome $outcome, ?string $status, int $duplicates, int $overcharge, string $named = 'synthetic-invoice'): void
    {
        $finding = FirstCycleChargeFinding::classify(
            ['workspace' => 'synthetic-workspace', 'company' => 'synthetic-company', 'agreement' => 'synthetic-agreement', 'cadence' => 'quarterly', 'starts_on' => '2026-01-09', 'package' => true, 'items' => 2],
            $invoices,
        );

        $this->assertSame([$outcome, $duplicates, $overcharge], [$finding->outcome, $finding->duplicateLines, $finding->overchargeMinor]);
        $this->assertSame([
            'workspace' => 'synthetic-workspace', 'company' => 'synthetic-company', 'agreement' => 'synthetic-agreement', 'cadence' => 'quarterly', 'starts_on' => '2026-01-09', 'package' => true, 'items' => 2,
            'outcome' => $outcome->value, 'invoice' => $invoices === [] ? null : $named, 'invoice_status' => $status, 'currency' => $invoices === [] ? null : 'USD',
            'duplicate_lines' => $duplicates, 'overcharge_minor' => $overcharge,
        ], $finding->toArray());
    }
}
