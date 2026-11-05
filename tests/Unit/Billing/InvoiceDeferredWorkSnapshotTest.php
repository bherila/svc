<?php

namespace Tests\Unit\Billing;

use App\Models\ClientTimeEntry;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvoiceDeferredWorkSnapshot::class)]
final class InvoiceDeferredWorkSnapshotTest extends TestCase
{
    public function test_entries_sort_by_date_then_identity_and_only_explicit_client_text_is_kept(): void
    {
        $entries = [
            $this->entry(3, '2026-05-02', 4, true, '  Visible synthetic work  '),
            $this->entry(2, '2026-05-01', 3, false, 'Hidden synthetic work'),
            $this->entry(1, '2026-05-01', 1, true, '   '),
        ];
        $snapshot = InvoiceDeferredWorkSnapshot::fromEntries($entries);
        self::assertNotNull($snapshot);
        self::assertSame([
            'version' => 1,
            'entries' => [
                ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 1, 'description' => null],
                ['id' => 2, 'workedOn' => '2026-05-01', 'minutes' => 3, 'description' => null],
                ['id' => 3, 'workedOn' => '2026-05-02', 'minutes' => 4, 'description' => 'Visible synthetic work'],
            ],
        ], $snapshot->toArray());
        self::assertSame($snapshot->toArray(), InvoiceDeferredWorkSnapshot::fromArray($snapshot->toArray())?->toArray());
        self::assertSame([
            'entry_count' => 3, 'total_hours' => 0.1333, 'oldest_worked_on' => '2026-05-01',
            'entries' => [
                ['worked_on' => '2026-05-01', 'hours' => 0.0167, 'description' => null],
                ['worked_on' => '2026-05-01', 'hours' => 0.05, 'description' => null],
                ['worked_on' => '2026-05-02', 'hours' => 0.0667, 'description' => 'Visible synthetic work'],
            ],
        ], $snapshot->documentData());
    }

    public function test_total_hours_sum_minutes_before_rounding_rows(): void
    {
        $entries = array_map(fn (int $id): ClientTimeEntry => $this->entry($id, '2026-05-01', 1, true, null), range(1, 60));
        self::assertSame(1.0, InvoiceDeferredWorkSnapshot::fromEntries($entries)?->documentData()['total_hours']);
    }

    public function test_settled_entries_are_removed_and_an_empty_snapshot_stays_absent(): void
    {
        $snapshot = InvoiceDeferredWorkSnapshot::fromEntries([$this->entry(1, '2026-05-01', 60), $this->entry(2, '2026-05-02', 120)]);
        self::assertNotNull($snapshot);
        self::assertSame($snapshot->toArray(), $snapshot->withoutSettledEntries([])?->toArray());
        self::assertSame([['id' => 2, 'workedOn' => '2026-05-02', 'minutes' => 120, 'description' => null]],
            $snapshot->withoutSettledEntries([1, 999])?->toArray()['entries']);
        self::assertNull($snapshot->withoutSettledEntries([1, 2]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromEntries([]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray(['version' => 1, 'entries' => []]));
    }

    public function test_missing_optional_description_reads_as_null_without_a_warning(): void
    {
        $snapshot = InvoiceDeferredWorkSnapshot::fromArray(['version' => 1, 'entries' => [
            ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 0],
        ]]);
        self::assertNotNull($snapshot);
        self::assertSame(['entry_count' => 1, 'total_hours' => 0.0, 'oldest_worked_on' => '2026-05-01',
            'entries' => [['worked_on' => '2026-05-01', 'hours' => 0.0, 'description' => null]]], $snapshot->documentData());
    }

    #[DataProvider('invalidSnapshots')]
    public function test_malformed_snapshots_do_not_reach_documents(?array $stored): void
    {
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray($stored));
    }

    public static function invalidSnapshots(): iterable
    {
        yield [null];
        yield [[]];
        yield [['version' => 2, 'entries' => []]];
        yield [['version' => 1, 'entries' => 'invalid']];
        yield [['version' => 1, 'entries' => [null]]];
        $valid = ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null];
        foreach (['id' => '1', 'workedOn' => 1, 'minutes' => -1, 'description' => []] as $key => $invalid) {
            yield [['version' => 1, 'entries' => [array_replace($valid, [$key => $invalid])]]];
        }
    }

    private function entry(int $id, string $date, int $minutes, bool $visible = false, ?string $description = null): ClientTimeEntry
    {
        $entry = new ClientTimeEntry;
        $entry->forceFill(['id' => $id, 'worked_on' => $date, 'minutes' => $minutes,
            'description' => 'Internal synthetic work', 'is_visible_to_client' => $visible, 'client_visible_description' => $description]);

        return $entry;
    }
}
