<?php

namespace App\Support\Billing;

use App\Models\ClientTimeEntry;

/**
 * The deferred entries still waiting after a cadence invoice was composed.
 *
 * Client-facing wording is snapshotted only when it is explicitly visible;
 * internal descriptions are never stored in this document snapshot.
 */
final readonly class InvoiceDeferredWorkSnapshot
{
    public const int VERSION = 1;

    /**
     * @param  list<array{id: int, workedOn: string, minutes: int, description: string|null}>  $entries
     */
    private function __construct(public array $entries) {}

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
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['workedOn'], $left['id']] <=> [$right['workedOn'], $right['id']]);

        return $rows === [] ? null : new self($rows);
    }

    /** @return array{version: int, entries: list<array{id: int, workedOn: string, minutes: int, description: string|null}>} */
    public function toArray(): array
    {
        return ['version' => self::VERSION, 'entries' => $this->entries];
    }

    /** @param array<array-key, mixed>|null $stored */
    public static function fromArray(?array $stored): ?self
    {
        if (($stored['version'] ?? null) !== self::VERSION || ! is_array($stored['entries'] ?? null)) {
            return null;
        }

        $entries = [];
        foreach ($stored['entries'] as $entry) {
            if (! is_array($entry)
                || ! is_int($entry['id'] ?? null)
                || ! is_string($entry['workedOn'] ?? null)
                || ! is_int($entry['minutes'] ?? null)
                || $entry['minutes'] < 0
                || (! is_string($entry['description'] ?? null) && ($entry['description'] ?? null) !== null)) {
                return null;
            }
            $entries[] = [
                'id' => $entry['id'],
                'workedOn' => $entry['workedOn'],
                'minutes' => $entry['minutes'],
                'description' => $entry['description'] ?? null,
            ];
        }

        return $entries === [] ? null : new self($entries);
    }

    /** @param list<int> $settledEntryIds */
    public function withoutSettledEntries(array $settledEntryIds): ?self
    {
        $settled = array_fill_keys($settledEntryIds, true);
        $remaining = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => ! isset($settled[$entry['id']]),
        ));

        return $remaining === [] ? null : new self($remaining);
    }

    /** @return array{entry_count: int, total_hours: float, oldest_worked_on: string, entries: list<array{worked_on: string, hours: float, description: string|null}>} */
    public function documentData(): array
    {
        $entries = array_map(static fn (array $entry): array => [
            'worked_on' => $entry['workedOn'],
            'hours' => round($entry['minutes'] / 60, 4),
            'description' => $entry['description'] ?? null,
        ], $this->entries);

        return [
            'entry_count' => count($entries),
            'total_hours' => round(array_sum(array_column($this->entries, 'minutes')) / 60, 4),
            'oldest_worked_on' => $entries[0]['worked_on'],
            'entries' => $entries,
        ];
    }
}
