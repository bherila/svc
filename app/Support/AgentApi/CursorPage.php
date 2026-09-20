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
     * @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}}
     *
     * @throws InvalidAgentApiCursor
     */
    public static function run(Workspace $workspace, string $queryKey, int $limit, ?string $cursor, Closure $fetch, Closure $present): array
    {
        $rows = $fetch($limit + 1, AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey));
        $next = $rows->count() > $limit ? $rows->pop() : null;
        $data = [];
        foreach ($rows as $row) {
            $data[] = $present($row);
        }

        return [
            'data' => $data,
            'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $rows->last()->getKey(), $workspace->public_id, $queryKey)],
        ];
    }
}
