<?php

namespace App\Services\Accounting;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\Workspace;
use App\Support\Accounting\AccountingFeedQuery;
use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedCursor;
use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedMerger;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\FeedWatermark;
use App\Support\AgentApi\ChangeWindow;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Runs an accounting feed: reads each source for its share of a page, merges
 * them newest first, and mints the cursor and the watermark.
 *
 * The same semantics as the change feeds of `finance.invoice_payments.list`:
 * the first page of a pass fixes a snapshot watermark (now less
 * {@see ChangeWindow::LAG_SECONDS}, or an earlier one the caller names) that
 * every page returns; follow `next_cursor` until it is null, then start the
 * next pass with `updated_since` set to that watermark.
 */
final class AccountingFeedRunner
{
    /**
     * @param  list<Closure(FeedBounds, ?FeedPosition, int): list<FeedEntry>>  $sources
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, watermark: string}}
     *
     * @throws InvalidAgentApiCursor
     */
    public function run(Workspace $workspace, AccountingFeedQuery $query, array $sources): array
    {
        $key = $query->key().'|updated_since='.($query->window->since() ?? '');
        $cursor = FeedCursor::decode($query->cursor, $workspace->public_id, $key);
        $watermark = FeedWatermark::resolve(
            $query->window->watermark?->format(ChangeWindow::FORMAT),
            $cursor,
            ChangeWindow::latest()->format(ChangeWindow::FORMAT),
        );

        [$page, $more] = $this->page($sources, new FeedBounds($query->window->since(), $watermark), $cursor?->position, $query->perPage);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return [
            'data' => array_map(static fn (FeedEntry $entry): array => $entry->payload, $page),
            'meta' => [
                'next_cursor' => $more && $last !== null ? (new FeedCursor($last->position, $watermark))->encode($workspace->public_id, $key) : null,
                'watermark' => CarbonImmutable::parse($watermark, 'UTC')->toIso8601String(),
            ],
        ];
    }

    /**
     * One page of the merged sources.
     *
     * @param  list<Closure(FeedBounds, ?FeedPosition, int): list<FeedEntry>>  $sources
     * @return array{list<FeedEntry>, bool} the entries newest first, and whether more follow
     */
    public function page(array $sources, FeedBounds $bounds, ?FeedPosition $after, int $limit): array
    {
        $entries = [];
        foreach ($sources as $source) {
            array_push($entries, ...$source($bounds, $after, $limit + 1));
        }

        return FeedMerger::page($entries, $after, $limit);
    }
}
