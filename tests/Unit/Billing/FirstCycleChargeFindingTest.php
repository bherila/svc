<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\FirstCycleChargeFinding;
use App\Support\Billing\FirstCycleChargeOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading what an agreement's first billing period charged its recurring
 * items (#685), without a database.
 */
final class FirstCycleChargeFindingTest extends TestCase
{
    /** @return iterable<string, array{array{public_id: string, status: string, currency: string}|null, list<array{item: int, line_date: string, id: int, total: int}>, FirstCycleChargeOutcome, int, int}> */
    public static function firstPeriods(): iterable
    {
        $invoice = static fn (string $status): array => ['public_id' => 'synthetic-invoice', 'status' => $status, 'currency' => 'USD'];
        $line = static fn (int $item, string $date, int $id, int $total): array => ['item' => $item, 'line_date' => $date, 'id' => $id, 'total' => $total];

        yield 'no invoice' => [null, [], FirstCycleChargeOutcome::NotInvoiced, 0, 0];
        yield 'each item once' => [$invoice('issued'), [$line(1, '2026-01-09', 1, 12000), $line(2, '2026-01-09', 2, 700)], FirstCycleChargeOutcome::ChargedOnce, 0, 0];
        // The later line is the extra one, whatever order the rows come in.
        yield 'an item twice, issued' => [$invoice('partially_paid'), [$line(1, '2026-04-01', 1, 9000), $line(1, '2026-01-09', 2, 12000)], FirstCycleChargeOutcome::IssuedWithDuplicate, 1, 9000];
        yield 'on the same day, the later row is the extra' => [$invoice('paid'), [$line(1, '2026-01-09', 7, 500), $line(1, '2026-01-09', 3, 12000)], FirstCycleChargeOutcome::IssuedWithDuplicate, 1, 500];
        yield 'two items twice, disputed' => [$invoice('disputed'), [$line(1, '2026-01-09', 1, 12000), $line(1, '2026-04-01', 2, 12000), $line(2, '2026-01-09', 3, 700), $line(2, '2026-04-01', 4, 700)], FirstCycleChargeOutcome::IssuedWithDuplicate, 2, 12700];
        yield 'a draft twice' => [$invoice('draft'), [$line(1, '2026-01-09', 1, 12000), $line(1, '2026-04-01', 2, 12000)], FirstCycleChargeOutcome::DraftWithDuplicate, 1, 12000];
    }

    /**
     * @param  array{public_id: string, status: string, currency: string}|null  $invoice
     * @param  list<array{item: int, line_date: string, id: int, total: int}>  $lines
     */
    #[DataProvider('firstPeriods')]
    public function test_the_first_period_is_read_from_its_invoice(?array $invoice, array $lines, FirstCycleChargeOutcome $outcome, int $duplicates, int $overcharge): void
    {
        $finding = FirstCycleChargeFinding::classify(
            ['workspace' => 'synthetic-workspace', 'company' => 'synthetic-company', 'agreement' => 'synthetic-agreement', 'cadence' => 'quarterly', 'starts_on' => '2026-01-09', 'package' => true, 'items' => 2],
            $invoice,
            $lines,
        );

        $this->assertSame([$outcome, $duplicates, $overcharge], [$finding->outcome, $finding->duplicateLines, $finding->overchargeMinor]);
        $this->assertSame([
            'workspace' => 'synthetic-workspace', 'company' => 'synthetic-company', 'agreement' => 'synthetic-agreement', 'cadence' => 'quarterly', 'starts_on' => '2026-01-09', 'package' => true, 'items' => 2,
            'outcome' => $outcome->value, 'invoice' => $invoice['public_id'] ?? null, 'invoice_status' => $invoice['status'] ?? null, 'currency' => $invoice['currency'] ?? null,
            'duplicate_lines' => $duplicates, 'overcharge_minor' => $overcharge,
        ], $finding->toArray());
    }
}
