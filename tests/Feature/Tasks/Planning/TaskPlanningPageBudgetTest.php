<?php

namespace Tests\Feature\Tasks\Planning;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentReadService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentPageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class TaskPlanningPageBudgetTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_ordinary_enrichment_uses_constant_query_count_for_one_and_twenty_five_tasks(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $this->seedTasks($workspace, $project, 26, false);
        $reads = app(AgentReadService::class);
        // Warm container services, never response facts, outside the measured reads.
        $reads->tasks($owner, $workspace, $project->public_id, 1, null);
        $counts = [];
        foreach ([1, 25] as $limit) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $page = $reads->tasks($owner, $workspace, $project->public_id, $limit, null);
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount($limit, $page['data']);
            foreach ($page['data'] as $row) {
                $this->assertCount(20, $row['planning_details']['labels']);
                $this->assertArrayNotHasKey('contacts', $row['planning_details']);
            }
            $counts[] = count($queries);
            $this->assertLessThanOrEqual(30, count($queries), 'The complete scoped task read must stay bounded, including base task relations.');
        }
        $this->assertSame($counts[0], $counts[1], 'Attached planning labels and dependency summaries must be batch-loaded rather than queried per task.');
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_hostile_unicode_and_twenty_labels_fit_actual_task_and_project_rest_mcp_sdk_envelopes_without_loss(): void
    {
        config(['agent_api.mcp_max_result_bytes' => 200_000, 'agent_api.mcp_max_response_body_bytes' => 400_000]);
        [$workspace, $project, $owner] = $this->fixture();
        $project->update(['description' => str_repeat('🧭"\\', 150)]);
        [$ids, $labels, $names] = $this->seedTasks($workspace, $project, 7, true);
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read', 'projects:read']);
        $session = $this->initialize();
        foreach (['tasks', 'project'] as $family) {
            $cursor = null;
            $seen = [];
            $pages = 0;
            do {
                $query = $family === 'tasks' ? ['project_id' => $project->public_id] : [];
                if ($cursor !== null) {
                    $query[$family === 'tasks' ? 'cursor' : 'tasks_cursor'] = $cursor;
                }
                $url = '/api/v1/workspaces/'.$workspace->public_id.($family === 'tasks' ? '/tasks' : '/projects/'.$project->public_id);
                $rest = $this->getJson($url.'?'.http_build_query($query))->assertOk();
                $payload = $rest->json();
                $reply = $this->callTool($session, $family === 'tasks' ? 'tasks.list' : 'projects.get',
                    ['workspace_id' => $workspace->public_id] + ($family === 'tasks' ? $query : ['project_id' => $project->public_id] + $query));
                $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply, JSON_THROW_ON_ERROR));
                $restData = $payload['data'];
                $mcpData = $reply['result']['structuredContent']['data'];
                if ($family === 'project') {
                    // Opaque encrypted cursors have random IVs on independent reads.
                    // Compare every fact and the decoded continuation boundary.
                    $restHref = $restData['tasks_next_href'];
                    $mcpHref = $mcpData['tasks_next_href'];
                    if ($restHref === null) {
                        $this->assertNull($mcpHref);
                    } else {
                        $this->assertIsString($mcpHref);
                        parse_str(parse_url($restHref, PHP_URL_QUERY), $restNext);
                        parse_str(parse_url($mcpHref, PHP_URL_QUERY), $mcpNext);
                        $this->assertSame(AgentApiCursor::decode($restNext['tasks_cursor'], $workspace->public_id, 'tasks|project_id='.$project->public_id),
                            AgentApiCursor::decode($mcpNext['tasks_cursor'], $workspace->public_id, 'tasks|project_id='.$project->public_id));
                    }
                    unset($restData['tasks_next_href'], $mcpData['tasks_next_href']);
                }
                $this->assertSame($restData, $mcpData);
                $this->assertLessThanOrEqual(200_000, strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
                $this->assertLessThanOrEqual(400_000, strlen(json_encode($reply, JSON_THROW_ON_ERROR)));
                $sdk = new CallToolResult((new ToolResultFormatter)->format($payload), structuredContent: $payload);
                $this->assertLessThanOrEqual(200_000, strlen(json_encode($sdk, JSON_THROW_ON_ERROR)));
                $this->assertLessThanOrEqual(400_000, strlen(json_encode(new Response('synthetic-task-planning-budget', $sdk), JSON_THROW_ON_ERROR)));
                $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($payload['data'], JSON_THROW_ON_ERROR)));
                $rows = $family === 'tasks' ? $payload['data'] : $payload['data']['tasks'];
                $this->assertNotEmpty($rows);
                $this->assertLessThan(25, count($rows), 'The byte budget, not the default row cap, must split these seven enriched tasks.');
                foreach ($rows as $row) {
                    $this->assertSame($labels, array_column($row['planning_details']['labels'], 'id'));
                    $this->assertSame($names, array_column($row['planning_details']['labels'], 'name'));
                    $this->assertSame(str_repeat('🚀"\\', 800), $row['description']);
                    $this->assertArrayNotHasKey('contacts', $row['planning_details']);
                    $seen[] = $row['id'];
                }
                if ($family === 'tasks') {
                    $cursor = $payload['meta']['next_cursor'];
                } else {
                    $href = $payload['data']['tasks_next_href'];
                    $cursor = null;
                    if ($href !== null) {
                        $this->assertStringStartsWith(route('agent-api.v1.projects.show', [$workspace, $project->public_id]).'?', $href);
                        parse_str(parse_url($href, PHP_URL_QUERY), $next);
                        $cursor = $next['tasks_cursor'];
                    }
                }
                $this->assertLessThanOrEqual(count($ids), ++$pages, 'Every continuation must advance.');
            } while ($cursor !== null);
            $this->assertGreaterThan(1, $pages);
            $this->assertSame($ids, $seen);
            $this->assertCount(count($ids), array_unique($seen));
        }
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    /** @return array{Workspace,ClientProject,User} */
    private function fixture(): array
    {
        config(['task_planning_details.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic enriched task budget', 'slug' => 'synthetic-enriched-task-budget']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic budget company', 'slug' => 'synthetic-budget-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic budget project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner];
    }

    /** @return array{list<string>,list<string>,list<string>} */
    private function seedTasks(Workspace $workspace, ClientProject $project, int $count, bool $unicode): array
    {
        $labels = [];
        $names = [];
        $labelIds = [];
        for ($index = 0; $index < 20; $index++) {
            $name = $unicode ? str_repeat('🚀"\\', 25).' '.$index : 'Synthetic label '.$index;
            $label = ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'name_key' => hash('sha256', $name)]);
            $labels[] = $label->public_id;
            $labelIds[] = $label->id;
            $names[] = $name;
        }
        $ids = [];
        $assignments = [];
        for ($index = 0; $index < $count; $index++) {
            $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'title' => $unicode ? str_repeat('🧭"\\', 75).' '.$index : 'Synthetic task '.$index,
                'description' => $unicode ? str_repeat('🚀"\\', 800) : 'Synthetic task description']);
            $ids[] = $task->public_id;
            foreach ($labelIds as $labelId) {
                $assignments[] = ['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'client_task_label_id' => $labelId];
            }
        }
        DB::table('client_task_label_assignments')->insert($assignments);

        return [$ids, $labels, $names];
    }
}
