<?php

namespace App\Services\Accounting;

use App\Models\Workspace;
use App\Support\Accounting\AccountingQuery;
use App\Support\AgentApi\ChangeFeedCursor;
use App\Support\AgentApi\ChangeFeedPage;
use App\Support\AgentApi\ChangeWindow;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class AccountingPage
{
    /** @template T of Model
     * @param  Builder<T>  $rows
     * @param  Closure(T): array<string, mixed>  $present
     * @param  (Closure(Collection<int, T>): void)|null  $load  bounded eager loads for the returned page
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, watermark: string, updated_since: string}}
     */
    public static function run(Workspace $workspace, string $operation, Builder $rows, AccountingQuery $query, Closure $present, bool $microseconds = false, ?Closure $load = null): array
    {
        $since = ChangeFeedCursor::decode($query->updatedSince, $workspace->public_id, $operation.'|sync');
        if ($since !== null) {
            $at = $since->changedAt.($microseconds ? '.000000' : '');
            $rows->where(fn (Builder $after) => $after->where('updated_at', '>', $at)->orWhere(fn (Builder $tie) => $tie->where('updated_at', $at)->where('id', '>', $since->id)));
        }
        $window = ChangeWindow::from($since === null ? [] : ['updated_since' => $since->changedAt]);
        $result = ChangeFeedPage::run($workspace,
            $operation.'|'.json_encode([$query->from, $query->to, $since?->id], JSON_THROW_ON_ERROR),
            $rows, $query->limit, $query->cursor, $window, $load ?? static function (): void {}, $present, microseconds: $microseconds);
        $at = CarbonImmutable::parse($result['meta']['watermark'])->utc()->format(ChangeWindow::FORMAT);
        $result['meta']['updated_since'] = (new ChangeFeedCursor($at, PHP_INT_MAX, $at))->encode($workspace->public_id, $operation.'|sync');

        return $result;
    }
}
