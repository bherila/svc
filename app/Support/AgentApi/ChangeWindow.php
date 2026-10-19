<?php

namespace App\Support\AgentApi;

use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The time window of one change-feed request: an optional inclusive lower
 * bound (`updated_since`) and the snapshot watermark that bounds a whole pass
 * from above. Validated facts only; no queries.
 *
 * Both are kept to whole seconds in UTC, the precision `updated_at` is stored
 * with, rounding the lower bound down so a fraction never excludes a row
 * written in that second.
 */
final readonly class ChangeWindow
{
    public const string FORMAT = 'Y-m-d H:i:s';

    private function __construct(
        public ?CarbonImmutable $updatedSince,
        public ?CarbonImmutable $watermark,
    ) {}

    /**
     * How far behind the request a pass's watermark is set, and the latest a
     * caller may name.
     *
     * `updated_at` is stamped when a row is written, not when its transaction
     * commits, so a row stamped just before a pass started can become visible
     * only after it read. A watermark of "now" would put that row below the
     * next pass's `updated_since` for good. Setting it this far back - longer
     * than any request's transaction runs - makes consecutive passes overlap
     * by at least this much, so such a row is read by the next pass. The cost
     * is re-delivery: consumers deduplicate by (id, version).
     */
    public const int LAG_SECONDS = 120;

    /**
     * Rules for the request's `updated_since` and `watermark` query parameters.
     * A watermark later than now less {@see LAG_SECONDS} is refused, for the
     * reason above: a pass bounded above by a time whose writes may still be
     * committing would skip them once the next pass starts from it.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'updated_since' => ['sometimes', 'date'],
            'watermark' => ['sometimes', 'date', 'before_or_equal:'.self::latest()->toIso8601String()],
        ];
    }

    /** The latest watermark a pass may have: now, less the lag. */
    public static function latest(): CarbonImmutable
    {
        return app(WorkspaceClock::class)->now('UTC')->subSeconds(self::LAG_SECONDS)->startOfSecond();
    }

    /**
     * Refuse, naming each, the filters a change feed cannot take: a filter on
     * something a record can change would drop the very change that takes the
     * record out of it, and the synchronizer would keep the stale copy for
     * good. Only filters on what never changes are allowed.
     *
     * @param  array<string, bool>  $given  filter => whether the request named it
     * @param  list<string>  $stable  the filters a change feed may take
     *
     * @throws ValidationException
     */
    public static function refuseMutableFilters(array $given, array $stable): void
    {
        $refused = [];
        foreach ($given as $filter => $named) {
            if ($named && ! in_array($filter, $stable, true)) {
                $refused[$filter] = $filter.' can stop matching a record when it changes, so it cannot filter a change-feed request (updated_since or watermark); sync without it and filter the synced records.';
            }
        }
        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }
    }

    /** @param array<string, mixed> $validated */
    public static function from(array $validated): self
    {
        return new self(
            self::second($validated['updated_since'] ?? null),
            self::second($validated['watermark'] ?? null),
        );
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /** Whether the request names any part of the window: a change-feed request. */
    public function requested(): bool
    {
        return $this->updatedSince !== null || $this->watermark !== null;
    }

    /** The lower bound as stored, or null for a pass over everything. */
    public function since(): ?string
    {
        return $this->updatedSince?->format(self::FORMAT);
    }

    private static function second(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc()->startOfSecond();
    }
}
