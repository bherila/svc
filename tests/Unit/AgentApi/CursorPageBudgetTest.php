<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Collection;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(CursorPage::class)]
#[CoversClass(AgentPageBudget::class)]
#[CoversClass(AgentApiCursor::class)]
final class CursorPageBudgetTest extends TestCase
{
    private const string QUERY = 'synthetic-byte-budget';

    public function test_an_exact_json_byte_boundary_includes_the_row_and_continues_after_it(): void
    {
        $rows = $this->rows(['Quote " and backslash \\ and 🚀', "Newline\n and tab\t and 🧭", 'Third synthetic row']);
        $firstTwo = [$this->present($rows[0]), $this->present($rows[1])];
        $boundary = strlen(json_encode($firstTwo, JSON_THROW_ON_ERROR));

        $page = $this->page($rows, limit: 25, budget: $boundary);
        $this->assertSame($firstTwo, $page['data']);
        $this->assertSame($boundary, strlen(json_encode($page['data'], JSON_THROW_ON_ERROR)));
        $this->assertIsString($page['meta']['next_cursor']);
        $this->assertSame(2, AgentApiCursor::decode($page['meta']['next_cursor'], $this->workspace()->public_id, self::QUERY));
        $next = $this->page($rows, limit: 25, cursor: $page['meta']['next_cursor'], budget: $boundary);
        $this->assertSame([3], array_column($next['data'], 'id'));
        $this->assertNull($next['meta']['next_cursor']);

        $oneByteShort = $this->page($rows, limit: 25, budget: $boundary - 1);
        $this->assertSame([1], array_column($oneByteShort['data'], 'id'));
        $this->assertSame(1, AgentApiCursor::decode($oneByteShort['meta']['next_cursor'], $this->workspace()->public_id, self::QUERY));
    }

    public function test_byte_only_splits_traverse_every_row_when_the_row_limit_exceeds_the_dataset(): void
    {
        $rows = $this->rows(array_fill(0, 7, "Synthetic \\ quoted \" 🚀\n"));
        $budget = strlen(json_encode([$this->present($rows[0]), $this->present($rows[1])], JSON_THROW_ON_ERROR));
        $cursor = null;
        $seen = [];
        $sizes = [];
        do {
            $page = $this->page($rows, limit: 100, cursor: $cursor, budget: $budget);
            $sizes[] = count($page['data']);
            $this->assertNotEmpty($page['data']);
            $this->assertLessThanOrEqual($budget, strlen(json_encode($page['data'], JSON_THROW_ON_ERROR)));
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(7, count($sizes), 'A continuation must advance.');
        } while ($cursor !== null);

        $this->assertSame([2, 2, 2, 1], $sizes);
        $this->assertSame(range(1, 7), $seen);
        $this->assertCount(7, array_unique($seen));
    }

    public function test_an_empty_result_has_no_continuation(): void
    {
        $this->assertSame(['data' => [], 'meta' => ['next_cursor' => null]], $this->page($this->rows([]), limit: 25, budget: 1));
    }

    public function test_omitting_the_budget_preserves_legacy_row_limit_pagination(): void
    {
        $rows = $this->rows(array_fill(0, 3, str_repeat('🚀', 1000)));
        $first = $this->page($rows, limit: 2);
        $this->assertSame([1, 2], array_column($first['data'], 'id'));
        $this->assertIsString($first['meta']['next_cursor']);
        $last = $this->page($rows, limit: 2, cursor: $first['meta']['next_cursor']);
        $this->assertSame([3], array_column($last['data'], 'id'));
        $this->assertNull($last['meta']['next_cursor']);
    }

    public function test_an_oversized_first_row_still_advances_and_does_not_hide_later_rows(): void
    {
        $rows = $this->rows([str_repeat('🚀', 1000), 'Small synthetic row']);
        $first = $this->page($rows, limit: 25, budget: 1);
        $this->assertSame([1], array_column($first['data'], 'id'));
        $this->assertIsString($first['meta']['next_cursor']);
        $last = $this->page($rows, limit: 25, cursor: $first['meta']['next_cursor'], budget: 1);
        $this->assertSame([2], array_column($last['data'], 'id'));
        $this->assertNull($last['meta']['next_cursor']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function protocolCeilings(): iterable
    {
        yield 'the capability result ceiling is smaller' => [32768, 131072];
        yield 'the HTTP response ceiling is smaller' => [131072, 32768];
    }

    public function test_default_budget_handles_notes_whose_text_copy_needs_maximum_json_escaping(): void
    {
        config(['agent_api.mcp_max_result_bytes' => 2_097_152, 'agent_api.mcp_max_response_body_bytes' => 4_194_304]);
        $rows = $this->rows(array_fill(0, 40, str_repeat('\\', 20000)));
        $page = $this->page($rows, limit: 100, budget: AgentPageBudget::dataBytes());
        $result = new CallToolResult((new ToolResultFormatter)->format($page), structuredContent: $page);
        $this->assertLessThanOrEqual(2_097_152, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
        $this->assertLessThanOrEqual(4_194_304, strlen(json_encode(new Response('synthetic-escaped-notes', $result), JSON_THROW_ON_ERROR)));
        $this->assertGreaterThan(1, count($page['data']));
        $this->assertLessThan(40, count($page['data']));
        $this->assertSame(str_repeat('\\', 20000), $page['data'][0]['text']);
        $this->assertSame(count($page['data']), AgentApiCursor::decode($page['meta']['next_cursor'], $this->workspace()->public_id, self::QUERY));
    }

    #[DataProvider('protocolCeilings')]
    public function test_configured_budget_keeps_real_sdk_results_and_responses_below_both_ceilings(int $resultCeiling, int $responseCeiling): void
    {
        config(['agent_api.mcp_max_result_bytes' => $resultCeiling, 'agent_api.mcp_max_response_body_bytes' => $responseCeiling]);
        $rows = $this->rows(array_fill(0, 40, str_repeat("Backslash \\ quote \" newline\n 🚀", 96)));
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            $page = $this->page($rows, limit: 100, cursor: $cursor, budget: AgentPageBudget::dataBytes());
            $result = new CallToolResult((new ToolResultFormatter)->format($page), structuredContent: $page);
            $response = new Response('synthetic-pagination-request', $result);
            $this->assertLessThanOrEqual($resultCeiling, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
            $this->assertLessThanOrEqual($responseCeiling, strlen(json_encode($response, JSON_THROW_ON_ERROR)));
            $this->assertNotEmpty($page['data']);
            foreach ($page['data'] as $item) {
                $this->assertSame($rows[$item['id'] - 1]->getAttribute('text'), $item['text']);
            }
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(40, ++$pages, 'A continuation must advance.');
        } while ($cursor !== null);

        $this->assertGreaterThan(1, $pages);
        $this->assertSame(range(1, 40), $seen);
        $this->assertCount(40, array_unique($seen));
    }

    /**
     * @param  Collection<int, ClientCompany>  $rows
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    private function page(Collection $rows, int $limit, ?string $cursor = null, ?int $budget = null): array
    {
        return CursorPage::run($this->workspace(), self::QUERY, $limit, $cursor,
            fn (int $take, ?int $after): Collection => new Collection($rows
                ->filter(static fn (ClientCompany $row): bool => $after === null || $row->getKey() > $after)
                ->take($take)->values()->all()),
            $this->present(...), $budget);
    }

    /** @param list<string> $texts
     * @return Collection<int, ClientCompany> */
    private function rows(array $texts): Collection
    {
        return new Collection(array_map(static function (string $text, int $index): ClientCompany {
            $row = new ClientCompany;
            $row->setRawAttributes(['id' => $index + 1, 'text' => $text]);

            return $row;
        }, $texts, array_keys($texts)));
    }

    /** @return array{id: int, text: string} */
    private function present(ClientCompany $row): array
    {
        return ['id' => (int) $row->getKey(), 'text' => $row->getAttribute('text')];
    }

    private function workspace(): Workspace
    {
        $workspace = new Workspace;
        $workspace->setRawAttributes(['public_id' => '00000000-0000-4000-8000-000000000001']);

        return $workspace;
    }
}
