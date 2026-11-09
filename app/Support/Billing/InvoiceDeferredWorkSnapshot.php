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
 *
 * An entry may also be marked `disputed`: work a client's dispute holds out of
 * billing (docs/ux-overhaul.md 7b″), listed beside the deferred work so the
 * document says what was left off and why. The key is optional and absent
 * means false, so a document written before it reads unchanged.
 */
final readonly class InvoiceDeferredWorkSnapshot
{
    public const int VERSION = 2;

    /** The legacy shape, written before project lineage was captured. */
    public const int LEGACY_VERSION = 1;

    /**
     * @param  list<array{id: int, workedOn: string, minutes: int, description: string|null, projectId: int|null, lineageKnown: bool, disputed: bool}>  $entries
     */
    private function __construct(public array $entries, public int $version) {}

    /**
     * @param  iterable<ClientTimeEntry>  $entries  deferred work still waiting
     * @param  iterable<ClientTimeEntry>  $disputed  work a client's dispute holds
     */
    public static function fromEntries(iterable $entries, iterable $disputed = []): ?self
    {
        $rows = [];
        foreach ([[$entries, false], [$disputed, true]] as [$group, $isDisputed]) {
            foreach ($group as $entry) {
                $rows[] = self::row($entry, $isDisputed);
            }
        }

        return self::make($rows, self::VERSION);
    }

    /** @return array{id: int, workedOn: string, minutes: int, description: string|null, projectId: int|null, lineageKnown: bool, disputed: bool} */
    private static function row(ClientTimeEntry $entry, bool $disputed): array
    {
        $description = $entry->client_visible_description;

        return [
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
            'disputed' => $disputed,
        ];
    }

    /** @return array{version: int, entries: list<array<string, bool|int|string|null>>} */
    public function toArray(): array
    {
        $legacy = $this->version === self::LEGACY_VERSION;

        return ['version' => $this->version, 'entries' => array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'workedOn' => $entry['workedOn'],
            'minutes' => $entry['minutes'],
            'description' => $entry['description'],
            ...($legacy ? [] : ['projectId' => $entry['projectId']]),
            ...($entry['disputed'] ? ['disputed' => true] : []),
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
                || (! $legacy && ! is_int($entry['projectId'] ?? null))
                || (array_key_exists('disputed', $entry) && ! is_bool($entry['disputed']))) {
                return null;
            }
            $entries[] = [
                'id' => $entry['id'],
                'workedOn' => $entry['workedOn'],
                'minutes' => $entry['minutes'],
                'description' => $entry['description'] ?? null,
                'projectId' => $legacy ? null : $entry['projectId'],
                'lineageKnown' => ! $legacy,
                'disputed' => $entry['disputed'] ?? false,
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
     * Every version 2 entry names its project; one that does not is refused as
     * malformed by {@see fromArray()}. Unknown legacy lineage is not a project
     * and is reported by {@see hasUnverifiableLineage()} instead.
     *
     * @return list<int>
     */
    public function projectIds(): array
    {
        // Only legacy entries lack a project (their lineage is unknown), and
        // those are reported by hasUnverifiableLineage(), not counted here.
        $ids = [];
        foreach ($this->entries as $entry) {
            if ($entry['projectId'] !== null) {
                $ids[] = $entry['projectId'];
            }
        }
        $ids = array_unique($ids);
        sort($ids);

        return $ids;
    }

    /** @return array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null, disputed: bool}>} */
    public function documentData(): array
    {
        $entries = array_map(static fn (array $entry): array => [
            'worked_on' => $entry['workedOn'],
            'hours' => round($entry['minutes'] / 60, 4),
            'description' => $entry['description'],
            'disputed' => $entry['disputed'],
        ], $this->entries);

        return [
            'entry_count' => count($entries),
            'total_hours' => round(array_sum(array_column($this->entries, 'minutes')) / 60, 4),
            'oldest_worked_on' => $entries[0]['worked_on'],
            'entries' => $entries,
        ];
    }

    /** @param list<array{id: int, workedOn: string, minutes: int, description: string|null, projectId: int|null, lineageKnown: bool, disputed: bool}> $rows */
    private static function make(array $rows, int $version): ?self
    {
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $left, array $right): int => [$left['workedOn'], $left['id']] <=> [$right['workedOn'], $right['id']]);

        return new self($rows, $version);
    }
}
