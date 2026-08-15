<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\MoneyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyServiceTest extends TestCase
{
    public function test_decimal_quantities_are_multiplied_in_minor_units_with_half_up_rounding(): void
    {
        $this->assertSame(1235, MoneyService::invoiceTotals([
            ['quantity' => '1.2345', 'unit_amount' => 1000, 'tax_amount' => 0],
        ])['subtotal_amount']);
        $this->assertSame(1250, MoneyService::invoiceTotals([
            ['quantity' => '2.5', 'unit_amount' => 500, 'tax_amount' => 0],
        ])['total_amount']);
    }

    public function test_currency_and_fractional_minor_units_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyService::currency('usd');
    }

    public function test_quantity_cannot_be_zero_or_have_more_than_four_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyService::invoiceTotals([
            ['quantity' => '0.00001', 'unit_amount' => 100, 'tax_amount' => 0],
        ]);
    }

    public function test_large_fractional_values_do_not_overflow_before_division(): void
    {
        $this->assertSame(9000000090000000, MoneyService::invoiceTotals([
            ['quantity' => '10000.0001', 'unit_amount' => 900000000000, 'tax_amount' => 0],
        ])['subtotal_amount']);
    }
}
