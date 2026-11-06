<?php

namespace App\Services\Accounting;

use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The SQL side of reading one source of an accounting feed: the watermark and
 * `updated_since` bounds, the position a cursor names, and the newest-first
 * order, all against the one expression that is the source's instant.
 *
 * Instants compare as the text a column stores, and SQLite stores a
 * whole-second column without fractional digits, so every bound is spelled the
 * way the column spells its values.
 */
final class FeedSql
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  tenant-scoped and filtered, not yet bounded, ordered or limited
     * @param  literal-string  $at  the SQL expression of the source's instant
     * @param  literal-string  $id  the qualified id column of the source's row
     * @param  array{string, int}|null  $below  the (stored instant, id) of the last row already read in this pass
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $at, string $id, FeedBounds $bounds, ?FeedPosition $after, ?array $below, int $rank, int $batch, bool $microseconds): Builder
    {
        $query->whereRaw("{$at} <= ?", [$bounds->ceiling($microseconds)]);
        $lower = $bounds->lower($microseconds);
        if ($lower !== null) {
            $query->whereRaw("{$at} >= ?", [$lower]);
        }
        if ($after !== null) {
            self::narrow($query, $at, $id, $microseconds ? $after->at : substr($after->at, 0, 19), $after->rowCeiling($rank));
        }
        if ($below !== null) {
            self::narrow($query, $at, $id, $below[0], $below[1] - 1);
        }

        return $query->orderByRaw("{$at} desc")->orderByDesc($id)->limit($batch);
    }

    /** The instant a row's stored text names, in the feed's microsecond form. */
    public static function instant(mixed $stored): string
    {
        return CarbonImmutable::parse((string) $stored, 'UTC')->format('Y-m-d H:i:s.u');
    }

    /**
     * Rows stamped before `$instant`, and among those stamped at it, the rows
     * whose id is at most `$ceiling` (none when it is null).
     *
     * @param  Builder<covariant Model>  $query
     * @param  literal-string  $at
     * @param  literal-string  $id
     */
    private static function narrow(Builder $query, string $at, string $id, string $instant, ?int $ceiling): void
    {
        $query->where(static function (Builder $rows) use ($at, $id, $instant, $ceiling): void {
            $rows->whereRaw("{$at} < ?", [$instant]);
            if ($ceiling !== null) {
                $rows->orWhere(static fn (Builder $tie) => $tie->whereRaw("{$at} = ?", [$instant])->where($id, '<=', $ceiling));
            }
        });
    }
}
