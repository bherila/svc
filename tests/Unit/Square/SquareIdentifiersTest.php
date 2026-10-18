<?php

namespace Tests\Unit\Square;

use App\Support\Square\SquareIdentifiers;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Square identifiers are opaque to SVC: checked for shape, never resolved (#438). */
final class SquareIdentifiersTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function numbers(): iterable
    {
        yield 'a recurring occurrence' => ['000123-R-0004', '000123'];
        yield 'the first occurrence' => ['999999-R-0001', '999999'];
        yield 'a one-off' => ['000123', null];
        yield 'a seven-digit prefix' => ['0001234-R-0001', null];
        yield 'a short suffix' => ['000123-R-01', null];
        yield 'a lower-case marker' => ['000123-r-0001', null];
        yield 'a custom number' => ['SYN-0001', null];
        yield 'trailing text' => ['000123-R-0001 copy', null];
    }

    #[DataProvider('numbers')]
    public function test_the_series_key_is_the_prefix_of_a_recurring_number(string $number, ?string $series): void
    {
        $this->assertSame($series, SquareIdentifiers::seriesKey($number));
    }

    public function test_identifier_shapes(): void
    {
        $this->assertTrue(SquareIdentifiers::isInvoiceId('_synthetic_legacy_invoice'));
        $this->assertTrue(SquareIdentifiers::isInvoiceId('SyntheticLegacyInvoice=='));
        $this->assertTrue(SquareIdentifiers::isInvoiceId('inv:0-fixture_1'));
        $this->assertTrue(SquareIdentifiers::isInvoiceId(str_repeat('a', 191)));
        $this->assertFalse(SquareIdentifiers::isInvoiceId(str_repeat('a', 192)));
        foreach ([' synthetic', 'synthetic ', 'synthetic%3D', 'synthetic?x', 'synthetic#x', 'synthetic+id', 'synthetic/id', 'syntheticé', '='] as $invalid) {
            $this->assertFalse(SquareIdentifiers::isInvoiceId($invalid));
        }
        $this->assertTrue(SquareIdentifiers::isInvoiceId('_'.str_repeat('x', 188).'=='));
        $this->assertFalse(SquareIdentifiers::isInvoiceId('_'.str_repeat('x', 189).'=='));
        $this->assertFalse(SquareIdentifiers::isInvoiceId(''));
        $this->assertFalse(SquareIdentifiers::isInvoiceId(':leading'));
        $this->assertFalse(SquareIdentifiers::isInvoiceId('inv:0 fixture'));
        $this->assertFalse(SquareIdentifiers::isInvoiceId('inv/../fixture'));
        $this->assertFalse(SquareIdentifiers::isInvoiceId("inv:fixture\n"));

        $this->assertTrue(SquareIdentifiers::isCustomerId('CUSTFIXTUREA'));
        $this->assertTrue(SquareIdentifiers::isCustomerId('cust_fixture-a'));
        $this->assertFalse(SquareIdentifiers::isCustomerId('cust:fixture'));
        $this->assertFalse(SquareIdentifiers::isCustomerId(str_repeat('C', 192)));

        $this->assertTrue(SquareIdentifiers::isInvoiceNumber('000001-R-0001'));
        $this->assertTrue(SquareIdentifiers::isInvoiceNumber('A'));
        $this->assertTrue(SquareIdentifiers::isInvoiceNumber('INV 0001'));
        $this->assertTrue(SquareIdentifiers::isInvoiceNumber(str_repeat('9', 191)));
        $this->assertFalse(SquareIdentifiers::isInvoiceNumber(str_repeat('9', 192)));
        $this->assertFalse(SquareIdentifiers::isInvoiceNumber(' 0001'));
        $this->assertFalse(SquareIdentifiers::isInvoiceNumber('0001 '));
        $this->assertFalse(SquareIdentifiers::isInvoiceNumber("0001\n2"));
        $this->assertFalse(SquareIdentifiers::isInvoiceNumber('0001é'));

        $this->assertTrue(SquareIdentifiers::isSeriesKey('000001'));
        $this->assertFalse(SquareIdentifiers::isSeriesKey('00001'));
        $this->assertFalse(SquareIdentifiers::isSeriesKey('0000011'));
        $this->assertFalse(SquareIdentifiers::isSeriesKey("000001\n"));
    }

    public function test_a_reference_refuses_what_validation_would(): void
    {
        $reference = new SquareInvoiceReference('inv:0-fixture1', '000001-R-0002', 'USD', SquareMatchMethod::Suggested, 70, ['exact amount']);
        $this->assertSame('000001', $reference->seriesKey());
        $this->assertNull((new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Manual))->seriesKey());
        $this->assertSame(0, (new SquareInvoiceReference('inv:0-fixture1', '000001', 'EUR', SquareMatchMethod::Suggested, 0))->score);
        $this->assertSame(100, (new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Suggested, 100, []))->score);

        foreach ([
            static fn () => new SquareInvoiceReference('inv 0', '000001', null, SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', ' 000001', null, SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', 'usd', SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', 'USDX', SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', 'XUSD', SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', "USD\n", SquareMatchMethod::Manual),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Manual, 50),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Manual, null, ['reason']),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Suggested, 101),
            static fn () => new SquareInvoiceReference('inv:0-fixture1', '000001', null, SquareMatchMethod::Suggested, -1),
        ] as $index => $make) {
            try {
                $make();
                $this->fail("Case {$index} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
