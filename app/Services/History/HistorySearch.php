<?php

namespace App\Services\History;

use App\Models\HistoricalEntry;
use App\Services\Search\WorkspaceSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** MariaDB FULLTEXT with literal matching for identifiers and unindexed words. */
final class HistorySearch
{
    /** @param Builder<HistoricalEntry> $query */
    public function matching(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $literal = '%'.WorkspaceSearch::escapeForLike($term).'%';
        preg_match_all('/[\pL\pN]+/u', $term, $matches);
        $tokens = array_values(array_unique($matches[0]));
        if (in_array(DB::connection($query->getModel()->getConnectionName())->getDriverName(), ['mysql', 'mariadb'], true) && $tokens !== [] && preg_match('/^[\pL\pN\s]+$/uD', $term) === 1) {
            $boolean = implode(' ', array_map(static fn (string $token): string => '+'.$token.'*', $tokens));
            // No user-provided boolean operators reach AGAINST. Literal title
            // matching keeps invoice numbers and punctuation discoverable.
            $query->where(function (Builder $q) use ($boolean, $literal): void {
                $q->whereFullText(['title', 'search_text'], $boolean, ['mode' => 'boolean'])
                    ->orWhereRaw("title LIKE ? ESCAPE '!'", [$literal])
                    ->orWhereRaw("search_text LIKE ? ESCAPE '!'", [$literal]);
            });

            return;
        }
        $query->where(function (Builder $q) use ($literal): void {
            $q->whereRaw("title LIKE ? ESCAPE '!'", [$literal])->orWhereRaw("search_text LIKE ? ESCAPE '!'", [$literal]);
        });
    }
}
