<?php

namespace Tests\Unit\Billing;

use App\Models\ClientTimeEntry;
use App\Support\Billing\SelectedTimeInvoiceTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelectedTimeInvoiceTermsTest extends TestCase
{
    public function test_preview_uses_integer_minutes_and_the_actual_rate_snapshot(): void
    {
        $terms = SelectedTimeInvoiceTerms::forEntry($this->entry(['minutes' => 1, 'billing_rate_amount' => 12345]));
        $this->assertNotNull($terms);
        $this->assertSame('time', $terms->type);
        $this->assertSame('USD', $terms->currency);
        $this->assertSame(12345, $terms->unitAmount);
        $this->assertSame(206, $terms->totalAmount);
    }

    public function test_flat_hourly_uses_cost_and_its_currency_instead_of_the_billing_rate(): void
    {
        $terms = SelectedTimeInvoiceTerms::forEntry($this->entry([
            'subcontractor_billing_mode' => 'flat_hourly',
            'subcontractor_cost_amount' => 7500,
            'subcontractor_cost_currency' => 'EUR',
            'minutes' => 90,
        ]));
        $this->assertNotNull($terms);
        $this->assertSame('subcontractor', $terms->type);
        $this->assertSame('EUR', $terms->currency);
        $this->assertSame(7500, $terms->unitAmount);
        $this->assertSame(11250, $terms->totalAmount);
    }

    public function test_retainer_subcontractor_uses_the_billing_snapshot_and_zero_is_a_real_rate(): void
    {
        $terms = SelectedTimeInvoiceTerms::forEntry($this->entry([
            'subcontractor_billing_mode' => 'retainer',
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'EUR',
            'billing_rate_amount' => 0,
        ]));
        $this->assertNotNull($terms);
        $this->assertSame('time', $terms->type);
        $this->assertSame('USD', $terms->currency);
        $this->assertSame(0, $terms->unitAmount);
        $this->assertSame(0, $terms->totalAmount);
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('ineligibleEntries')]
    public function test_ineligible_or_invalid_legacy_time_has_no_quote(array $attributes): void
    {
        $this->assertNull(SelectedTimeInvoiceTerms::forEntry($this->entry($attributes)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ineligibleEntries(): iterable
    {
        yield 'draft' => [['status' => 'draft']];
        yield 'non-billable' => [['is_billable' => false]];
        yield 'deferred' => [['is_deferred' => true]];
        yield 'direct billing' => [['subcontractor_billing_mode' => 'direct']];
        yield 'non-string mode' => [['subcontractor_billing_mode' => 17]];
        yield 'unknown mode' => [['subcontractor_billing_mode' => 'synthetic-unknown']];
        yield 'cost without mode' => [['subcontractor_cost_amount' => 5000]];
        yield 'missing rate' => [['billing_rate_amount' => null]];
        yield 'missing currency' => [['currency' => null]];
        yield 'invalid currency' => [['currency' => 'usd']];
        yield 'negative rate' => [['billing_rate_amount' => -1]];
        yield 'zero duration' => [['minutes' => 0]];
        yield 'missing flat cost' => [['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_currency' => 'USD']];
        yield 'missing flat currency' => [['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000]];
        yield 'overflow' => [['minutes' => 120, 'billing_rate_amount' => PHP_INT_MAX]];
    }

    /** @param array<string, mixed> $attributes */
    private function entry(array $attributes): ClientTimeEntry
    {
        return (new ClientTimeEntry)->setRawAttributes($attributes + [
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false,
            'billing_rate_amount' => 10000, 'currency' => 'USD', 'minutes' => 60,
            'subcontractor_billing_mode' => null, 'subcontractor_cost_amount' => null,
            'subcontractor_cost_currency' => null,
        ], true);
    }
}
