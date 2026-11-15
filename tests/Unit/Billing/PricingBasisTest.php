<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\PricingBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingBasisTest extends TestCase
{
    public function test_it_survives_storage_and_matches_the_same_inputs(): void
    {
        $basis = self::basis();
        $stored = $basis->toStored();

        $this->assertSame([
            'version' => 1, 'project_id' => 7, 'through' => '2026-01-31', 'up_to' => 40, 'invoices_up_to' => 9,
            'digest' => hash('sha256', (string) json_encode(['carried' => [], 'invoices' => [[3, '2026-01-31', '1.5000']], 'time' => [[40, '2026-01-10', 600]]])),
        ], $stored);
        $restored = PricingBasis::fromStored($stored);
        $this->assertInstanceOf(PricingBasis::class, $restored);
        $this->assertTrue($restored->matches($basis));
        $this->assertSame([7, '2026-01-31', 40, 9], [$restored->projectId, $restored->through, $restored->upTo, $restored->invoicesUpTo]);
    }

    public function test_the_inputs_digest_ignores_the_order_they_are_named_in(): void
    {
        $reordered = PricingBasis::of(7, '2026-01-31', 40, 9, [
            'time' => [[40, '2026-01-10', 600]], 'invoices' => [[3, '2026-01-31', '1.5000']], 'carried' => [],
        ]);

        $this->assertTrue(self::basis()->matches($reordered));
    }

    /** @return iterable<string, array{PricingBasis}> */
    public static function moved(): iterable
    {
        $inputs = ['carried' => [], 'invoices' => [[3, '2026-01-31', '1.5000']], 'time' => [[40, '2026-01-10', 600]]];
        yield 'a row' => [PricingBasis::of(7, '2026-01-31', 40, 9, ['time' => [[40, '2026-01-10', 300]]] + $inputs)];
        yield 'the scope' => [PricingBasis::of(null, '2026-01-31', 40, 9, $inputs)];
        yield 'the last day' => [PricingBasis::of(7, '2026-02-28', 40, 9, $inputs)];
        yield 'the last time entry' => [PricingBasis::of(7, '2026-01-31', 41, 9, $inputs)];
        yield 'the last invoice' => [PricingBasis::of(7, '2026-01-31', 40, 10, $inputs)];
    }

    #[DataProvider('moved')]
    public function test_any_moved_part_does_not_match(PricingBasis $current): void
    {
        $this->assertFalse(self::basis()->matches($current));
    }

    /** @return iterable<string, array{array<mixed>|null}> */
    public static function unreadable(): iterable
    {
        $stored = self::basis()->toStored();
        yield 'none' => [null];
        yield 'another version' => [['version' => 2] + $stored];
        yield 'no version' => [array_diff_key($stored, ['version' => true])];
        yield 'a text project' => [['project_id' => '7'] + $stored];
        yield 'no last day' => [array_diff_key($stored, ['through' => true])];
        yield 'a text time bound' => [['up_to' => '40'] + $stored];
        yield 'no invoice bound' => [array_diff_key($stored, ['invoices_up_to' => true])];
        yield 'no digest' => [array_diff_key($stored, ['digest' => true])];
    }

    /** @param  array<mixed>|null  $stored */
    #[DataProvider('unreadable')]
    public function test_a_basis_it_cannot_read_is_none(?array $stored): void
    {
        $this->assertNull(PricingBasis::fromStored($stored));
    }

    public function test_a_company_wide_basis_is_read_back(): void
    {
        $basis = PricingBasis::of(null, '2026-01-31', 0, 0, []);

        $restored = PricingBasis::fromStored($basis->toStored());
        $this->assertInstanceOf(PricingBasis::class, $restored);
        $this->assertNull($restored->projectId);
        $this->assertTrue($restored->matches($basis));
    }

    private static function basis(): PricingBasis
    {
        return PricingBasis::of(7, '2026-01-31', 40, 9, [
            'invoices' => [[3, '2026-01-31', '1.5000']], 'carried' => [], 'time' => [[40, '2026-01-10', 600]],
        ]);
    }
}
