<?php

namespace App\Support\Billing;

use App\Models\ClientTimeEntry;

/**
 * The deferred entries still waiting after a cadence invoice was composed.
 *
 * Client-facing wording is snapshotted only when it is explicitly visible;
 * internal descriptions are never stored in this document snapshot.
 *
 * Version 2 also freezes each entry's project at capture (#610), so the
 * invoice's portal grant decision can include the projects whose work the
 * document discloses without reading the live, editable time entry. Version 1
 * stored no project: it still reads, so a legacy document keeps its "Still
 * deferred" section, but each of its entries is marked as unknown lineage and
 * it is written back as version 1. An unknown project is never laundered into
 * "no project" by a later rewrite.
 */
final readonly class InvoiceDeferredWorkSnapshot
{
    public const int VERSION = 2;

    /** The legacy shape, written before project lineage was captured. */
    public const int LEGACY_VERSION = 1;

    /**
     * @param  list<array{id: int, workedOn: string, minutes: int, description: string|null, projectId: int|null, lineageKnown: bool}>  $entries
     */
    private function __construct(public array $entries, public int $version) {}

    /** @param iterable<ClientTimeEntry> $entries */
    public static function fromEntries(iterable $entries): ?self
    {
        $rows = [];
        foreach ($entries as $entry) {
            $description = $entry->client_visible_description;
            $rows[] = [
                'id' => $entry->id,
                'workedOn' => $entry->worked_on->toDateString(),
                'minutes' => $entry->minutes,
                'description' => $entry->is_visible_to_client === true
                    && is_string($description)
                    && trim($description) !== ''
                    ? trim($description)
                    : null,
                'projectId' => $entry->client_project_id,
                'lineageKnown' => true,
            ];
        }

        return self::make($rows, self::VERSION);
    }

    /** @return array{version: int, entries: list<array<string, int|string|null>>} */
    public function toArray(): array
    {
        $legacy = $this->version === self::LEGACY_VERSION;

        return ['version' => $this->version, 'entries' => array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'workedOn' => $entry['workedOn'],
            'minutes' => $entry['minutes'],
            'description' => $entry['description'],
            ...($legacy ? [] : ['projectId' => $entry['projectId']]),
        ], $this->entries)];
    }

    /** @param array<array-key, mixed>|null $stored */
    public static function fromArray(?array $stored): ?self
    {
        $version = $stored['version'] ?? null;
        if (! in_array($version, [self::LEGACY_VERSION, self::VERSION], true) || ! is_array($stored['entries'] ?? null)) {
            return null;
        }
        $legacy = $version === self::LEGACY_VERSION;

        $entries = [];
        foreach ($stored['entries'] as $entry) {
            if (! is_array($entry)
                || ! is_int($entry['id'] ?? null)
                || ! is_string($entry['workedOn'] ?? null)
                || ! is_int($entry['minutes'] ?? null)
                || $entry['minutes'] < 0
                || (! is_string($entry['description'] ?? null) && ($entry['description'] ?? null) !== null)
                || (! $legacy && (! array_key_exists('projectId', $entry) || (! is_int($entry['projectId']) && $entry['projectId'] !== null)))) {
                return null;
            }
            $entries[] = [
                'id' => $entry['id'],
                'workedOn' => $entry['workedOn'],
                'minutes' => $entry['minutes'],
                'description' => $entry['description'] ?? null,
                'projectId' => $legacy ? null : $entry['projectId'],
                'lineageKnown' => ! $legacy,
            ];
        }

        return self::make($entries, $version);
    }

    /** @param list<int> $settledEntryIds */
    public function withoutSettledEntries(array $settledEntryIds): ?self
    {
        $settled = array_fill_keys($settledEntryIds, true);
        $remaining = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => ! isset($settled[$entry['id']]),
        ));

        return self::make($remaining, $this->version);
    }

    /**
     * Whether any disclosed entry's project could not be established.
     *
     * Only a legacy snapshot can be unverifiable, and only while it still lists
     * an entry: an empty snapshot discloses nothing (and is never stored).
     */
    public function hasUnverifiableLineage(): bool
    {
        foreach ($this->entries as $entry) {
            if (! $entry['lineageKnown']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The distinct projects whose work this snapshot discloses, ascending.
     *
     * Entries recorded with no project contribute none, exactly as an invoice
     * line without a project does. Unknown legacy lineage is not a project and
     * is reported by {@see hasUnverifiableLineage()} instead.
     *
     * @return list<int>
     */
    public function projectIds(): array
    {
        $projects = [];
        foreach ($this->entries as $entry) {
            if ($entry['lineageKnown'] && $entry['projectId'] !== null) {
                $projects[$entry['projectId']] = true;
            }
        }
        $ids = array_keys($projects);
        sort($ids);

        return $ids;
    }

    /** @return array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null}>} */
    public function documentData(): array
    {
        $entries = array_map(static fn (array $entry): array => [
            'worked_on' => $entry['workedOn'],
            'hours' => round($entry['minutes'] / 60, 4),
            'description' => $entry['description'],
        ], $this->entries);

        return [
            'entry_count' => count($entries),
            'total_hours' => round(array_sum(array_column($this->entries, 'minutes')) / 60, 4),
            'oldest_worked_on' => $entries[0]['worked_on'],
            'entries' => $entries,
        ];
    }

    /** @param list<array{id: int, workedOn: string, minutes: int, description: string|null, projectId: int|null, lineageKnown: bool}> $rows */
    private static function make(array $rows, int $version): ?self
    {
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $left, array $right): int => [$left['workedOn'], $left['id']] <=> [$right['workedOn'], $right['id']]);

        return new self($rows, $version);
    }
}
