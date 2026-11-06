<?php

namespace Tests\Unit\Revenue;

use App\Services\Revenue\RevenueActuals;
use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\CollectedPayment;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueBand;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Monthly actuals and the collected basis's band spread, over in-memory amounts. */
final class RevenueActualsTest extends TestCase
{
    public function test_a_payment_is_spread_over_its_invoices_bands_before_tax(): void
    {
        $amounts = RevenueActualsInputs::collectedAmounts(
            [new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-02'), 5500), new CollectedPayment(10, 8, CarbonImmutable::parse('2026-04-03'), 0)],
            // 10000 pre-tax (8000 recurring, 1000 overage, 1000 expenses) plus 1000 tax.
            [7 => ['gross' => 11000, 'bands' => ['recurring' => 8000, 'overage_and_other' => 1000, 'expenses' => 1000]]],
        );

        $byBand = [];
        foreach ($amounts as $amount) {
            $byBand[$amount->band->value] = $amount->amount;
        }
        $this->assertSame(['recurring' => 4000, 'overage_and_other' => 500, 'expenses' => 500], $byBand);
    }

    public function test_rounding_of_a_spread_payment_lands_on_the_largest_band(): void
    {
        $amounts = RevenueActualsInputs::collectedAmounts(
            [new CollectedPayment(10, 7, CarbonImmutable::parse('2026-04-02'), 100)],
            [7 => ['gross' => 300, 'bands' => ['recurring' => 100, 'overage_and_other' => 100, 'expenses' => 100]]],
        );

        $this->assertSame(100, array_sum(array_map(static fn (ActualAmount $amount): int => $amount->amount, $amounts)));
        $this->assertSame(34, $amounts[0]->amount);
    }

    public function test_a_payment_on_an_invoice_with_no_priced_lines_counts_as_other(): void
    {
        $amounts = RevenueActualsInputs::collectedAmounts([new CollectedPayment(10, 9, CarbonImmutable::parse('2026-04-02'), 700)], []);

        $this->assertSame([[RevenueBand::OverageAndOther, 700]], array_map(static fn (ActualAmount $amount): array => [$amount->band, $amount->amount], $amounts));
    }

    public function test_monthly_actuals_are_per_client_per_month_per_band(): void
    {
        $monthly = RevenueActuals::monthly([
            $this->amount('2026-03-01', 100),
            $this->amount('2026-03-31', 50, RevenueBand::Expenses),
            $this->amount('2026-03-15', -30, RevenueBand::OverageAndOther),
            $this->amount('2026-03-15', 9, client: 20),
        ]);

        $this->assertSame([100, -30, 50], [$monthly[10]['2026-03']->recurring, $monthly[10]['2026-03']->overageAndOther, $monthly[10]['2026-03']->expenses]);
        $this->assertSame(129, RevenueActuals::month($monthly, '2026-03')->total(true));
        $this->assertSame(70, RevenueActuals::month($monthly, '2026-03', 10)->total(false));
    }

    private function amount(string $date, int $amount, RevenueBand $band = RevenueBand::Recurring, int $client = 10): ActualAmount
    {
        return new ActualAmount($client, CarbonImmutable::parse($date), $band, $amount);
    }
}
