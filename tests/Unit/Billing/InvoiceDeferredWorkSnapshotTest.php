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
            $this->entry(3, '2026-05-02', 4, true, '  Visible synthetic work  ', project: 7),
            $this->entry(2, '2026-05-01', 3, false, 'Hidden synthetic work', project: 9),
            $this->entry(1, '2026-05-01', 1, true, '   ', project: 7),
        ];
        $snapshot = InvoiceDeferredWorkSnapshot::fromEntries($entries);
        self::assertNotNull($snapshot);
        self::assertSame([
            'version' => 2,
            'entries' => [
                ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 1, 'description' => null, 'projectId' => 7],
                ['id' => 2, 'workedOn' => '2026-05-01', 'minutes' => 3, 'description' => null, 'projectId' => 9],
                ['id' => 3, 'workedOn' => '2026-05-02', 'minutes' => 4, 'description' => 'Visible synthetic work', 'projectId' => 7],
            ],
        ], $snapshot->toArray());
        self::assertSame($snapshot->toArray(), InvoiceDeferredWorkSnapshot::fromArray($snapshot->toArray())?->toArray());
        self::assertSame([7, 9], $snapshot->projectIds());
        self::assertFalse($snapshot->hasUnverifiableLineage());
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
        $snapshot = InvoiceDeferredWorkSnapshot::fromEntries([$this->entry(1, '2026-05-01', 60, project: 4), $this->entry(2, '2026-05-02', 120, project: 5)]);
        self::assertNotNull($snapshot);
        self::assertSame($snapshot->toArray(), $snapshot->withoutSettledEntries([])?->toArray());
        self::assertSame([['id' => 2, 'workedOn' => '2026-05-02', 'minutes' => 120, 'description' => null, 'projectId' => 5]],
            $snapshot->withoutSettledEntries([1, 999])?->toArray()['entries']);
        self::assertNull($snapshot->withoutSettledEntries([1, 2]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromEntries([]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray(['version' => 1, 'entries' => []]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray(['version' => 2, 'entries' => []]));
    }

    public function test_a_legacy_snapshot_still_reads_with_every_entry_of_unknown_lineage(): void
    {
        $legacy = ['version' => 1, 'entries' => [
            ['id' => 2, 'workedOn' => '2026-05-02', 'minutes' => 90, 'description' => 'Legacy synthetic work'],
            ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 30, 'description' => null],
        ]];

        $snapshot = InvoiceDeferredWorkSnapshot::fromArray($legacy);

        self::assertNotNull($snapshot, 'A version 1 document keeps its Still deferred section.');
        self::assertTrue($snapshot->hasUnverifiableLineage());
        self::assertSame([], $snapshot->projectIds(), 'Unknown lineage is never read as no project.');
        self::assertSame(['entry_count' => 2, 'total_hours' => 2.0, 'oldest_worked_on' => '2026-05-01', 'entries' => [
            ['worked_on' => '2026-05-01', 'hours' => 0.5, 'description' => null],
            ['worked_on' => '2026-05-02', 'hours' => 1.5, 'description' => 'Legacy synthetic work'],
        ]], $snapshot->documentData());
        // Rewriting a legacy snapshot (a settlement removing entries) keeps it
        // legacy: it must not be laundered into version 2 with null projects.
        $remaining = $snapshot->withoutSettledEntries([1]);
        self::assertNotNull($remaining);
        self::assertSame(['version' => 1, 'entries' => [
            ['id' => 2, 'workedOn' => '2026-05-02', 'minutes' => 90, 'description' => 'Legacy synthetic work'],
        ]], $remaining->toArray());
        self::assertTrue($remaining->hasUnverifiableLineage());
        self::assertNull($snapshot->withoutSettledEntries([1, 2]));
    }

    /**
     * Every time entry belongs to a project (the column is required), so a
     * version 2 entry without one is corrupt or hand-made. It is refused like
     * any malformed snapshot, so its text never reaches a document, rather
     * than read as known lineage that no grant check would cover.
     */
    public function test_a_modern_entry_without_a_project_is_malformed_and_never_reaches_a_document(): void
    {
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray(['version' => 2, 'entries' => [
            ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => 'Synthetic work', 'projectId' => null],
            ['id' => 2, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null, 'projectId' => 3],
        ]]));
        self::assertNull(InvoiceDeferredWorkSnapshot::fromArray(['version' => 2, 'entries' => [
            ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null],
        ]]));
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
        yield [['version' => 3, 'entries' => [['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null, 'projectId' => 1]]]];
        yield [['version' => '2', 'entries' => [['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null, 'projectId' => 1]]]];
        yield [['version' => 1, 'entries' => 'invalid']];
        yield [['version' => 1, 'entries' => [null]]];
        $valid = ['id' => 1, 'workedOn' => '2026-05-01', 'minutes' => 60, 'description' => null];
        foreach (['id' => '1', 'workedOn' => 1, 'minutes' => -1, 'description' => []] as $key => $invalid) {
            yield [['version' => 1, 'entries' => [array_replace($valid, [$key => $invalid])]]];
            yield [['version' => 2, 'entries' => [array_replace($valid, ['projectId' => 1, $key => $invalid])]]];
        }
        // Version 2 must say which project, even when the answer is none.
        yield [['version' => 2, 'entries' => [$valid]]];
        yield [['version' => 2, 'entries' => [$valid + ['projectId' => '1']]]];
    }

    private function entry(int $id, string $date, int $minutes, bool $visible = false, ?string $description = null, ?int $project = null): ClientTimeEntry
    {
        $entry = new ClientTimeEntry;
        $entry->forceFill(['id' => $id, 'worked_on' => $date, 'minutes' => $minutes, 'client_project_id' => $project,
            'description' => 'Internal synthetic work', 'is_visible_to_client' => $visible, 'client_visible_description' => $description]);

        return $entry;
    }
}
