<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A `date` column read as an immutable date and written as a bare `Y-m-d`.
 *
 * `immutable_date` reads the same way, and it writes the model's full datetime
 * format - `2026-01-31 00:00:00`. MariaDB's `DATE` column drops the time, so
 * production never sees it; SQLite, the local suite's driver, keeps the string
 * as written. A range bound such as `<= '2026-01-31'` then compares text, the
 * longer string sorts after the shorter, and the last day of every period
 * silently falls out of the query (#354). A billing bug at month end could pass
 * the local suite and fail only in production.
 *
 * Writing the bare date makes both drivers store the same value, so a
 * date-string bound means the same thing on each. The other half of the
 * property is on the query side: bound such a column with a date string
 * (`toDateString()`), never a Carbon. The query builder formats a Carbon with
 * a time, and on SQLite `'2026-01-01' >= '2026-01-01 00:00:00'` is false.
 *
 * A builder `update()` skips casts, so a writer that uses one normalises the
 * value itself with `DateOnly::toStored()`.
 *
 * Input is converted exactly as `immutable_date` converts it (a timestamp, a
 * date string, a datetime string or an object), and a read returns a
 * `CarbonImmutable` that Eloquent serialises as it did before.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class DateOnly implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A row written before this cast on SQLite may still carry a time.
        return CarbonImmutable::parse(substr((string) $value, 0, 10));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::toStored($value);
    }

    /**
     * The stored form of a date: `Y-m-d`, from whatever `immutable_date` accepts
     * - an object, a Unix timestamp, a date string or a datetime string.
     *
     * Converted here rather than through the model's `fromDateTime()`, which
     * asks the database connection for its date format: a model built in a
     * unit test has none, and the date needs none (#362).
     */
    public static function toStored(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_int($value), is_string($value) && is_numeric($value) => CarbonImmutable::createFromTimestamp((int) $value, date_default_timezone_get()),
            is_string($value) => CarbonImmutable::parse($value),
            default => throw new InvalidArgumentException('A calendar date must be a date, a timestamp or a date string.'),
        };

        return $date->format('Y-m-d');
    }
}
