<?php

namespace App\Support\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One page of an incremental-sync listing: rows ordered by `updated_at desc,
 * id desc` inside a snapshot watermark, the same semantics as the change
 * feeds of the sibling applications.
 *
 * A pass starts without a cursor. The first page fixes the watermark (now
 * less {@see ChangeWindow::LAG_SECONDS}, unless the caller names an earlier
 * one) and every page of the pass is bounded by it and returns it, so rows
 * changed while the pass is paged are left for the next pass instead of
 * shifting pages under the reader. The next pass sends that watermark as
 * `updated_since`, which is inclusive. The lag makes consecutive passes
 * overlap, so a row stamped before a pass started but committed after it read
 * is still read by the next one; rows in the overlap are delivered twice, and
 * a consumer deduplicates by (id, version). Timestamps only locate candidates;
 * a row's `version` says whether it actually changed.
 *
 * A row with no `updated_at` (never written through a model) comes last in a
 * full pass rather than disappearing from it, and never matches
 * `updated_since`. Both database engines sort a null last in descending order.
 */
final class ChangeFeedPage
{
    /**
     * @template TRecord of Model
     *
     * @param  Builder<TRecord>  $query  tenant-scoped and filtered, not yet ordered or limited
     * @param  Closure(Collection<int, TRecord>): void  $load  eager loads for the returned rows
     * @param  Closure(TRecord): array<string, mixed>  $present
     * @param  int|null  $maxDataBytes  optional serialized data-array ceiling; cursor and watermark follow the last returned row
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, watermark: string}}
     *
     * @throws InvalidAgentApiCursor
     */
    public static function run(
        Workspace $workspace,
        string $queryKey,
        Builder $query,
        int $limit,
        ?string $cursor,
        ChangeWindow $window,
        Closure $load,
        Closure $present,
        bool $microseconds = false,
        ?int $maxDataBytes = null,
    ): array {
        $queryKey .= '|updated_since='.($window->since() ?? '');
        $position = ChangeFeedCursor::decode($cursor, $workspace->public_id, $queryKey, $microseconds);
        $watermark = self::watermark($window, $position);

        $model = $query->getModel();
        $changedAt = $model->qualifyColumn('updated_at');
        $id = $model->qualifyColumn($model->getKeyName());
        $since = $window->since();
        // DATETIME(6) compares the exact second inclusively on both engines,
        // including SQLite, whose test timestamps are stored as text.
        $upper = $microseconds ? $watermark.'.000000' : $watermark;
        $lower = $since !== null && $microseconds ? $since.'.000000' : $since;
        $query
            ->where(fn (Builder $bounded) => $since === null
                ? $bounded->where($changedAt, '<=', $upper)->orWhereNull($changedAt)
                : $bounded->where($changedAt, '<=', $upper)->where($changedAt, '>=', $lower))
            ->when($position !== null, fn (Builder $rows) => $rows->where(fn (Builder $after) => self::after($after, $changedAt, $id, $position)))
            ->orderByDesc($changedAt)
            ->orderByDesc($id)
            ->limit($limit + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows->pop();
        }
        $load($rows);
        $data = [];
        $bytes = 2;
        $last = null;
        foreach ($rows as $row) {
            $item = $present($row);
            if ($maxDataBytes !== null) {
                $size = strlen(json_encode($item, JSON_THROW_ON_ERROR));
                if ($data !== []) {
                    $size++;
                    if ($bytes + $size > $maxDataBytes) {
                        $hasMore = true;
                        break;
                    }
                }
                $bytes += $size;
            }
            $data[] = $item;
            $last = $row;
        }

        return [
            'data' => $data,
            'meta' => [
                'next_cursor' => $hasMore && $last !== null
                    ? (new ChangeFeedCursor(self::changedAt($last, $microseconds), (int) $last->getKey(), $watermark, $microseconds))->encode($workspace->public_id, $queryKey)
                    : null,
                'watermark' => CarbonImmutable::parse($watermark, 'UTC')->toIso8601String(),
            ],
        ];
    }

    /**
     * Rows after the cursor's position in `updated_at desc, id desc` order,
     * where a null `updated_at` sorts after every timestamp.
     *
     * @param  Builder<covariant Model>  $after
     */
    private static function after(Builder $after, string $changedAt, string $id, ?ChangeFeedCursor $position): void
    {
        if ($position === null) {
            return;
        }
        if ($position->changedAt === null) {
            $after->whereNull($changedAt)->where($id, '<', $position->id);

            return;
        }
        $after->where($changedAt, '<', $position->changedAt)
            ->orWhere(fn (Builder $tie) => $tie->where($changedAt, $position->changedAt)->where($id, '<', $position->id))
            ->orWhereNull($changedAt);
    }

    /** The pass's watermark: the cursor's, which a resent one must equal, or a new one. */
    private static function watermark(ChangeWindow $window, ?ChangeFeedCursor $position): string
    {
        $requested = $window->watermark?->format(ChangeWindow::FORMAT);
        if ($position === null) {
            return $requested ?? ChangeWindow::latest()->format(ChangeWindow::FORMAT);
        }
        if ($requested !== null && $requested !== $position->watermark) {
            throw new InvalidAgentApiCursor('watermark does not match the cursor.');
        }

        return $position->watermark;
    }

    private static function changedAt(Model $row, bool $microseconds): ?string
    {
        $value = $row->getAttribute('updated_at');

        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->utc()->format($microseconds ? 'Y-m-d H:i:s.u' : ChangeWindow::FORMAT) : null;
    }
}
