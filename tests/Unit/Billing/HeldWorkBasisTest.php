<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\HeldWorkBasis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeldWorkBasisTest extends TestCase
{
    /** @return list<array{int, bool, string|null, bool, bool}> */
    private static function rows(): array
    {
        return [[3, true, null, false, false], [7, false, 'answered', false, true], [9, false, 'written_off', true, false]];
    }

    public function test_the_same_state_gives_the_same_basis_and_round_trips(): void
    {
        $basis = HeldWorkBasis::of(12, '2026-02-28', self::rows());
        $this->assertSame(hash('sha256', "3|d|-|-|-\n7|-|answered|-|a\n9|-|written_off|x|-\n"), $basis->digest);
        $this->assertSame(['version' => 1, 'project_id' => 12, 'through' => '2026-02-28', 'digest' => $basis->digest], $basis->toStored());
        $stored = HeldWorkBasis::fromStored($basis->toStored());
        $this->assertNotNull($stored);
        $this->assertTrue($stored->matches(HeldWorkBasis::of(12, '2026-02-28', self::rows())));
        $this->assertSame(hash('sha256', ''), HeldWorkBasis::digest([]));
    }

    /** @return iterable<string, array{list<array{int, bool, string|null, bool, bool}>}> */
    public static function changes(): iterable
    {
        yield 'a row disputed again' => [[[3, true, null, false, false], [7, true, 'answered', false, true], [9, false, 'written_off', true, false]]];
        yield 'a dispute settled' => [[[3, false, 'answered', false, false], [7, false, 'answered', false, true], [9, false, 'written_off', true, false]]];
        yield 'another outcome' => [[[3, true, null, false, false], [7, false, 'written_off', false, true], [9, false, 'written_off', true, false]]];
        yield 'a row deleted' => [[[3, true, null, true, false], [7, false, 'answered', false, true], [9, false, 'written_off', true, false]]];
        yield 'a row restored' => [[[3, true, null, false, false], [7, false, 'answered', false, true], [9, false, 'written_off', false, false]]];
        yield 'a charged row loses its invoice line, as a void does' => [[[3, true, null, false, false], [7, false, 'answered', false, false], [9, false, 'written_off', true, false]]];
        yield 'a held row gains one' => [[[3, true, null, false, true], [7, false, 'answered', false, true], [9, false, 'written_off', true, false]]];
        yield 'a new row' => [[...self::rows(), [11, true, null, false, false]]];
        yield 'a row gone' => [[[3, true, null, false, false], [7, false, 'answered', false, true]]];
    }

    /** @param list<array{int, bool, string|null, bool, bool}> $rows */
    #[DataProvider('changes')]
    public function test_any_change_to_the_disputed_state_is_a_different_basis(array $rows): void
    {
        $this->assertFalse(HeldWorkBasis::of(12, '2026-02-28', self::rows())->matches(HeldWorkBasis::of(12, '2026-02-28', $rows)));
    }

    public function test_the_scope_and_the_last_day_are_part_of_the_basis(): void
    {
        $basis = HeldWorkBasis::of(12, '2026-02-28', self::rows());
        $this->assertFalse($basis->matches(HeldWorkBasis::of(null, '2026-02-28', self::rows())));
        $this->assertFalse($basis->matches(HeldWorkBasis::of(13, '2026-02-28', self::rows())));
        $this->assertFalse($basis->matches(HeldWorkBasis::of(12, '2026-03-31', self::rows())));
        $this->assertTrue(HeldWorkBasis::of(null, '2026-02-28', [])->matches(HeldWorkBasis::of(null, '2026-02-28', [])));
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function unreadable(): iterable
    {
        yield 'none' => [null];
        yield 'another version' => [['version' => 2, 'project_id' => null, 'through' => '2026-02-28', 'digest' => 'x']];
        yield 'no version' => [['project_id' => null, 'through' => '2026-02-28', 'digest' => 'x']];
        yield 'no day' => [['version' => 1, 'project_id' => null, 'digest' => 'x']];
        yield 'no digest' => [['version' => 1, 'project_id' => null, 'through' => '2026-02-28']];
        yield 'a project that is not an id' => [['version' => 1, 'project_id' => '12', 'through' => '2026-02-28', 'digest' => 'x']];
    }

    /** @param array<string, mixed>|null $stored */
    #[DataProvider('unreadable')]
    public function test_a_basis_this_version_cannot_read_is_none(?array $stored): void
    {
        $this->assertNull(HeldWorkBasis::fromStored($stored));
    }

    public function test_a_company_wide_basis_reads_back(): void
    {
        $stored = HeldWorkBasis::fromStored(['version' => 1, 'project_id' => null, 'through' => '2026-02-28', 'digest' => 'abc']);
        $this->assertNotNull($stored);
        $this->assertNull($stored->projectId);
        $this->assertSame('2026-02-28', $stored->through);
        $this->assertSame('abc', $stored->digest);
    }
}
