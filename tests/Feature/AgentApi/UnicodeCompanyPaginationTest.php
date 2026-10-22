<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\Result\CallToolResult;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class UnicodeCompanyPaginationTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_default_mcp_listing_pages_maximum_unicode_profiles_without_loss(): void
    {
        [$workspace, $expected] = $this->fixture();
        $session = $this->initialize();
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            $arguments = ['workspace_id' => $workspace->public_id];
            if ($cursor !== null) {
                $arguments['cursor'] = $cursor;
            }
            $reply = $this->callTool($session, 'clients.list', $arguments);
            $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply));
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
            $page = $reply['result']['structuredContent'];
            $this->assertProfilesPreserved($page['data']);
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(count($expected), ++$pages, 'Every continuation must advance.');
        } while ($cursor !== null);

        $this->assertGreaterThan(1, $pages, 'The default row limit must yield to the serialized response budget.');
        $this->assertSame($expected, $seen);
        $this->assertCount(count($expected), array_unique($seen));
    }

    public function test_change_feed_pages_maximum_unicode_profiles_with_one_watermark_without_loss(): void
    {
        [$workspace, $expected] = $this->fixture();
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients';
        $watermark = now()->subMinutes(3)->startOfSecond()->toIso8601String();
        $query = ['updated_since' => now()->subDays(2)->startOfSecond()->toIso8601String(), 'watermark' => $watermark];
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            $arguments = $cursor === null ? $query : [...$query, 'cursor' => $cursor];
            $page = $this->getJson($path.'?'.http_build_query($arguments))->assertOk()->json();
            $this->assertSame($watermark, $page['meta']['watermark']);
            // Change feeds are REST-only. Measure the full SDK representation,
            // including its text copy, against the shared MCP result ceiling.
            $result = new CallToolResult((new ToolResultFormatter)->format($page), structuredContent: $page);
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($result, JSON_THROW_ON_ERROR)));
            $this->assertProfilesPreserved($page['data']);
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(count($expected), ++$pages, 'Every continuation must advance.');
        } while ($cursor !== null);

        $this->assertGreaterThan(1, $pages, 'The feed must split large profiles before its row limit.');
        $this->assertSame(array_reverse($expected), $seen);
        $this->assertCount(count($expected), array_unique($seen));
    }

    /** @param list<array<string, mixed>> $companies */
    private function assertProfilesPreserved(array $companies): void
    {
        $this->assertNotEmpty($companies);
        foreach ($companies as $company) {
            $this->assertSame(str_repeat('🚀', 20000), $company['notes']);
            $this->assertSame(str_repeat('🧭', 10000), $company['billing_address']);
        }
    }

    /** @return array{Workspace, list<string>} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Unicode pagination', 'slug' => 'synthetic-unicode-pagination']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $ids = [];
        for ($index = 0; $index < 9; $index++) {
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Synthetic Unicode company '.$index,
                'slug' => 'synthetic-unicode-company-'.$index,
                'notes' => str_repeat('🚀', 20000),
                'billing_address' => str_repeat('🧭', 10000),
            ]);
            // Older than the lagged watermark; three rows share each timestamp
            // so continuation must also preserve the descending id tiebreaker.
            DB::table('client_companies')->where('workspace_id', $workspace->id)->where('id', $company->id)
                ->update(['updated_at' => now()->subDay()->startOfSecond()->addSeconds(intdiv($index, 3))]);
            $ids[] = $company->public_id;
        }
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'clients:read']);

        return [$workspace, $ids];
    }
}
