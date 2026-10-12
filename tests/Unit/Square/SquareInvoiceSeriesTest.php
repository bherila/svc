<?php

namespace Tests\Unit\Square;

use App\Services\Square\SquareInvoiceSeries;
use App\Services\Square\SquarePresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SquareInvoiceSeriesTest extends TestCase
{
    /** @return iterable<string, array{0: string|null, 1: string|null, 2: int|null}> */
    public static function numbers(): iterable
    {
        yield 'first occurrence of a series' => ['000001-R-0001', '000001', 1];
        yield 'later occurrence' => ['123456-R-0042', '123456', 42];
        yield 'occurrence zero padded to four digits' => ['654321-R-1000', '654321', 1000];
        yield 'one-off invoice' => ['000002', null, null];
        yield 'no number' => [null, null, null];
        yield 'empty number' => ['', null, null];
        yield 'five-digit prefix' => ['12345-R-0001', null, null];
        yield 'seven-digit prefix' => ['1234567-R-0001', null, null];
        yield 'three-digit occurrence' => ['123456-R-001', null, null];
        yield 'lower-case marker' => ['123456-r-0001', null, null];
        yield 'surrounding text' => ['#123456-R-0001', null, null];
        yield 'trailing text' => ['123456-R-0001a', null, null];
        yield 'trailing newline' => ["123456-R-0001\n", null, null];
        yield 'custom number' => ['INV-2026-001', null, null];
    }

    #[DataProvider('numbers')]
    public function test_the_series_key_is_the_prefix_of_a_recurring_number_only(?string $number, ?string $key, ?int $occurrence): void
    {
        $this->assertSame(['series_key' => $key, 'series_occurrence' => $occurrence], SquareInvoiceSeries::of($number));
    }

    /**
     * Two independent recurrence signals: a Subscriptions API invoice carries
     * Square's subscription id whatever its number, and a dashboard series is
     * known by its number alone. Neither stands in for the other.
     */
    public function test_a_presented_invoice_keeps_the_subscription_id_beside_the_series_key(): void
    {
        $presenter = new SquarePresenter;
        $pick = static fn (array $invoice): array => array_intersect_key($invoice, array_flip(['series_key', 'series_occurrence', 'subscription_id']));

        $this->assertSame(
            ['series_key' => null, 'series_occurrence' => null, 'subscription_id' => 'sub_fixture_1'],
            $pick($presenter->invoice(['invoice_number' => '000123', 'subscription_id' => 'sub_fixture_1'])),
        );
        $this->assertSame(
            ['series_key' => '000124', 'series_occurrence' => 3, 'subscription_id' => null],
            $pick($presenter->invoice(['invoice_number' => '000124-R-0003'])),
        );
        $this->assertSame(
            ['series_key' => '000125', 'series_occurrence' => 1, 'subscription_id' => 'sub_fixture_2'],
            $pick($presenter->invoice(['invoice_number' => '000125-R-0001', 'subscription_id' => 'sub_fixture_2'])),
        );
        $this->assertNull($presenter->invoice(['subscription_id' => ['not' => 'an id']])['subscription_id']);
    }
}
