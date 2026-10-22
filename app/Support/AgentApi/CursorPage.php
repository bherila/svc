<?php

namespace App\Support\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One bounded, workspace-bound page of an id-ordered listing, shared by the
 * agent read services.
 *
 * The caller runs the query and this keeps what is easy to get subtly wrong and
 * identical everywhere: refusing a cursor minted for another workspace or
 * listing, fetching one row past the page to know whether there is a next one,
 * and minting the cursor from the last row actually returned.
 */
final class CursorPage
{
    /**
     * @template TRecord of Model
     *
     * @param  Closure(int, ?int): Collection<int, TRecord>  $fetch  at most `$take` rows with an id above `$after` (null: from the start), ordered by id
     * @param  Closure(TRecord): array<string, mixed>  $present
     * @param  int|null  $maxDataBytes  optional serialized data-array ceiling; a page may contain fewer than $limit rows
     * @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}}
     *
     * @throws InvalidAgentApiCursor
     */
    public static function run(Workspace $workspace, string $queryKey, int $limit, ?string $cursor, Closure $fetch, Closure $present, ?int $maxDataBytes = null): array
    {
        $rows = $fetch($limit + 1, AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey));
        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows->pop();
        }
        $data = [];
        $bytes = 2;
        $last = null;
        foreach ($rows as $row) {
            $item = $present($row);
            if ($maxDataBytes !== null) {
                $size = strlen(json_encode($item, JSON_THROW_ON_ERROR));
                if ($data !== []) {
                    $size++;
                    // Always make progress for a single row; a configured
                    // ceiling too small for it is refused by the transport.
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

        $nextCursor = null;
        if ($hasMore && $last !== null) {
            /** @infection-ignore-all The weak int parameter coerces numeric model keys identically; this cast states the type required by static analysis. */
            $lastId = (int) $last->getKey();
            $nextCursor = AgentApiCursor::encode($lastId, $workspace->public_id, $queryKey);
        }

        return [
            'data' => $data,
            'meta' => ['next_cursor' => $nextCursor],
        ];
    }
}
