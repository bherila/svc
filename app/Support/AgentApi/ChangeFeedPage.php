<?php

namespace App\Support\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\Workspace;
use App\Support\WorkspaceClock;
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
 * A pass starts without a cursor. The first page fixes the watermark (now,
 * unless the caller names an earlier one) and every page of the pass is
 * bounded by it and returns it, so rows changed while the pass is paged are
 * left for the next pass instead of shifting pages under the reader. The next
 * pass sends that watermark as `updated_since`, which is inclusive: a row
 * changed in the watermark's own second is read twice rather than never.
 * Timestamps only locate candidates; a row's `version` says whether it
 * actually changed.
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
    ): array {
        $queryKey .= '|updated_since='.($window->since() ?? '');
        $position = ChangeFeedCursor::decode($cursor, $workspace->public_id, $queryKey);
        $watermark = self::watermark($window, $position);

        $model = $query->getModel();
        $changedAt = $model->qualifyColumn('updated_at');
        $id = $model->qualifyColumn($model->getKeyName());
        $since = $window->since();
        $query
            ->where(fn (Builder $bounded) => $since === null
                ? $bounded->where($changedAt, '<=', $watermark)->orWhereNull($changedAt)
                : $bounded->where($changedAt, '<=', $watermark)->where($changedAt, '>=', $since))
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
        foreach ($rows as $row) {
            $data[] = $present($row);
        }
        $last = $rows->last();

        return [
            'data' => $data,
            'meta' => [
                'next_cursor' => $hasMore && $last !== null
                    ? (new ChangeFeedCursor(self::changedAt($last), (int) $last->getKey(), $watermark))->encode($workspace->public_id, $queryKey)
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
            return $requested ?? app(WorkspaceClock::class)->now('UTC')->startOfSecond()->format(ChangeWindow::FORMAT);
        }
        if ($requested !== null && $requested !== $position->watermark) {
            throw new InvalidAgentApiCursor('watermark does not match the cursor.');
        }

        return $position->watermark;
    }

    private static function changedAt(Model $row): ?string
    {
        $value = $row->getAttribute('updated_at');

        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->utc()->format(ChangeWindow::FORMAT) : null;
    }
}
