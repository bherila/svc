<?php

namespace App\Support\AgentApi;

use Carbon\CarbonImmutable;

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
     * Rules for the request's `updated_since` and `watermark` query parameters.
     * A watermark in the future is refused: a pass bounded above by a time
     * not yet reached would skip whatever changes before it, once the next
     * pass starts from it.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'updated_since' => ['sometimes', 'date'],
            'watermark' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
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
