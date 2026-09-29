<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\CatchUpBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The earlier catch-up a monthly invoice records it was sized against, without a database. */
final class CatchUpBasisTest extends TestCase
{
    public function test_charges_are_rounded_kept_in_id_order_and_zero_charges_are_left_out(): void
    {
        $basis = CatchUpBasis::of('2026-02-28', [
            ['id' => 9, 'number' => 'INV-9', 'hours' => 2.00004],
            ['id' => 3, 'number' => 'INV-3', 'hours' => 1.5],
            ['id' => 5, 'number' => 'INV-5', 'hours' => 0.00001],
        ]);

        $this->assertSame([
            'version' => CatchUpBasis::VERSION,
            'through' => '2026-02-28',
            'invoices' => [
                ['id' => 3, 'number' => 'INV-3', 'hours' => 1.5],
                ['id' => 9, 'number' => 'INV-9', 'hours' => 2.0],
            ],
        ], $basis->toArray());
        $this->assertSame(0.0, $basis->hoursFrom(5));
    }

    public function test_the_stored_shape_round_trips_through_json(): void
    {
        $basis = CatchUpBasis::of('2026-02-28', [
            ['id' => 3, 'number' => 'INV-3', 'hours' => 1.0],
            ['id' => 4, 'number' => 'INV-4', 'hours' => 2.25],
        ]);

        $decoded = json_decode((string) json_encode($basis->toArray()), true);

        $this->assertEquals($basis, CatchUpBasis::fromArray($decoded));
    }

    public function test_a_stored_whole_number_of_hours_is_read(): void
    {
        $basis = CatchUpBasis::fromArray([
            'version' => CatchUpBasis::VERSION,
            'through' => '2026-02-28',
            'invoices' => [['id' => 3, 'number' => 'INV-3', 'hours' => 2]],
        ]);

        $this->assertSame(2.0, $basis?->hoursFrom(3));
        $this->assertSame('2026-02-28', $basis->through);
    }

    public function test_an_empty_basis_is_a_basis(): void
    {
        $basis = CatchUpBasis::fromArray(['version' => CatchUpBasis::VERSION, 'through' => '2026-02-28', 'invoices' => []]);

        $this->assertInstanceOf(CatchUpBasis::class, $basis);
        $this->assertSame([], $basis->charges);
    }

    /** @param  array<array-key, mixed>|null  $stored */
    #[DataProvider('unreadable')]
    public function test_an_unreadable_shape_reads_as_nothing(?array $stored): void
    {
        $this->assertNull(CatchUpBasis::fromArray($stored));
    }

    /** @return array<string, array{array<array-key, mixed>|null}> */
    public static function unreadable(): array
    {
        $entry = ['id' => 3, 'number' => 'INV-3', 'hours' => 1.0];
        $shape = static fn (array $invoices, mixed $version = CatchUpBasis::VERSION, mixed $through = '2026-02-28'): array => [
            'version' => $version, 'through' => $through, 'invoices' => $invoices,
        ];

        return [
            'nothing stored' => [null],
            'another version' => [$shape([$entry], version: CatchUpBasis::VERSION + 1)],
            'no version' => [['through' => '2026-02-28', 'invoices' => [$entry]]],
            'no through' => [['version' => CatchUpBasis::VERSION, 'invoices' => [$entry]]],
            'a through that is not text' => [$shape([$entry], through: 20260228)],
            'no invoices' => [['version' => CatchUpBasis::VERSION, 'through' => '2026-02-28']],
            'invoices that are not a list' => [['version' => CatchUpBasis::VERSION, 'through' => '2026-02-28', 'invoices' => 'INV-3']],
            'an entry that is not a record' => [$shape(['INV-3'])],
            'an entry with no id' => [$shape([['number' => 'INV-3', 'hours' => 1.0]])],
            'an id that is not a whole number' => [$shape([['id' => '3', 'number' => 'INV-3', 'hours' => 1.0]])],
            'an entry with no number' => [$shape([['id' => 3, 'hours' => 1.0]])],
            'a number that is not text' => [$shape([['id' => 3, 'number' => 3, 'hours' => 1.0]])],
            'an entry with no hours' => [$shape([['id' => 3, 'number' => 'INV-3']])],
            'hours that are not a number' => [$shape([['id' => 3, 'number' => 'INV-3', 'hours' => '1.0']])],
            'one bad entry among good ones' => [$shape([$entry, ['id' => 4, 'number' => 'INV-4']])],
        ];
    }

    public function test_the_same_charges_are_no_change(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'INV-3', 'hours' => 1.0]]);
        $current = CatchUpBasis::of('2026-02-28', [
            ['id' => 3, 'number' => 'INV-3', 'hours' => 1.00001],
            ['id' => 4, 'number' => 'INV-4', 'hours' => 0.0],
        ]);

        $this->assertNull($recorded->firstChangeIn($current));
    }

    public function test_a_charge_that_moved_is_a_change(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'INV-3', 'hours' => 1.0]]);
        $current = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'INV-3', 'hours' => 3.0]]);

        $this->assertSame(['number' => 'INV-3', 'recorded' => 1.0, 'current' => 3.0], $recorded->firstChangeIn($current));
    }

    public function test_a_charge_that_appeared_is_a_change(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', []);
        $current = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'INV-3', 'hours' => 1.0]]);

        $this->assertSame(['number' => 'INV-3', 'recorded' => 0.0, 'current' => 1.0], $recorded->firstChangeIn($current));
    }

    public function test_a_charge_that_disappeared_is_a_change_named_from_the_record(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'INV-3', 'hours' => 1.0]]);
        $current = CatchUpBasis::of('2026-02-28', []);

        $this->assertSame(['number' => 'INV-3', 'recorded' => 1.0, 'current' => 0.0], $recorded->firstChangeIn($current));
    }

    public function test_the_current_number_names_a_change_when_both_have_one(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'OLD-3', 'hours' => 1.0]]);
        $current = CatchUpBasis::of('2026-02-28', [['id' => 3, 'number' => 'NEW-3', 'hours' => 2.0]]);

        $this->assertSame('NEW-3', $recorded->firstChangeIn($current)['number'] ?? null);
    }

    public function test_the_earliest_changed_invoice_is_named_whichever_side_it_is_on(): void
    {
        $recorded = CatchUpBasis::of('2026-02-28', [
            ['id' => 2, 'number' => 'INV-2', 'hours' => 1.0],
            ['id' => 7, 'number' => 'INV-7', 'hours' => 1.0],
        ]);
        $current = CatchUpBasis::of('2026-02-28', [
            ['id' => 2, 'number' => 'INV-2', 'hours' => 1.0],
            ['id' => 5, 'number' => 'INV-5', 'hours' => 4.0],
        ]);

        $this->assertSame('INV-5', $recorded->firstChangeIn($current)['number'] ?? null);
        $this->assertSame('INV-5', $current->firstChangeIn($recorded)['number'] ?? null);
    }
}
