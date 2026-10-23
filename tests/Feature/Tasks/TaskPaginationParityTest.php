<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskCreateData;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentPageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class TaskPaginationParityTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_unicode_task_pages_traverse_without_loss_in_rest_mcp_and_cli(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $ids = $this->tasks($workspace, $project, $owner, 9);
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read']);
        $session = $this->initialize();
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            $query = ['project_id' => $project->public_id, ...($cursor === null ? [] : ['cursor' => $cursor])];
            $page = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks?'.http_build_query($query))->assertOk()->json();
            $reply = $this->callTool($session, 'tasks.list', ['workspace_id' => $workspace->public_id, ...$query]);
            $this->assertFalse($reply['result']['isError'] ?? true);
            $this->assertSame($page['data'], $reply['result']['structuredContent']['data']);
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
            $this->assertSame(0, Artisan::call('svc:tasks', ['operation' => 'list', 'workspace' => $workspace->public_id,
                '--actor' => $owner->public_id, '--project' => $project->public_id, '--format' => 'json', ...($cursor === null ? [] : ['--cursor' => $cursor])]));
            $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($page['data'], $cli['data']);
            $this->assertNotEmpty($page['data']);
            foreach ($page['data'] as $row) {
                $this->assertSame($this->description(), $row['description']);
                $this->assertSame($owner->public_id, $row['assignees'][0]['id']);
            }
            foreach ([$reply['result']['structuredContent']['meta']['next_cursor'], $cli['meta']['next_cursor']] as $otherCursor) {
                $this->assertSame(AgentApiCursor::decode($page['meta']['next_cursor'], $workspace->public_id, 'tasks|project_id='.$project->public_id),
                    AgentApiCursor::decode($otherCursor, $workspace->public_id, 'tasks|project_id='.$project->public_id));
            }
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(count($ids), ++$pages, 'Continuation must advance after the returned row.');
        } while ($cursor !== null);
        $this->assertGreaterThan(1, $pages, 'Serialized bytes must split a dataset smaller than the default row limit.');
        $this->assertSame($ids, $seen);
        $this->assertCount(count($ids), array_unique($seen));
    }

    public function test_project_task_continuation_budgets_the_whole_project_and_preserves_scope_and_version(): void
    {
        config(['agent_api.mcp_max_result_bytes' => 500000]);
        [$workspace, , $project, $owner] = $this->fixture();
        $project->update(['description' => str_repeat('🧭', 5000)]);
        $ids = $this->tasks($workspace, $project, $owner, 4, str_repeat('🚀', 5000));
        $this->actingAsMcp($owner, ['mcp:use', 'projects:read', 'tasks:read']);
        $session = $this->initialize();
        $href = route('agent-api.v1.projects.show', [$workspace, $project->public_id]);
        $cursor = null;
        $seen = [];
        $version = null;
        $pages = 0;
        do {
            $page = $this->getJson($href)->assertOk()->json('data');
            $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($page, JSON_THROW_ON_ERROR)), 'Base project and tasks share one data allowance.');
            $reply = $this->callTool($session, 'projects.get', ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id,
                ...($cursor === null ? [] : ['tasks_cursor' => $cursor])]);
            $this->assertFalse($reply['result']['isError'] ?? true);
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
            $this->assertSame($page['tasks'], $reply['result']['structuredContent']['data']['tasks']);
            $this->assertSame($project->description, $page['description']);
            $version ??= $page['version'];
            $this->assertSame($version, $page['version']);
            $this->assertCount(1, $page['tasks'], 'The base description must reduce the task page budget.');
            $this->assertSame(str_repeat('🚀', 5000), $page['tasks'][0]['description']);
            $seen = [...$seen, ...array_column($page['tasks'], 'id')];
            $href = $page['tasks_next_href'];
            if ($href !== null) {
                parse_str(parse_url($href, PHP_URL_QUERY), $next);
                $cursor = $next['tasks_cursor'];
                $this->assertStringStartsWith(route('agent-api.v1.projects.show', [$workspace, $project->public_id]).'?', $href);
            }
            $this->assertLessThanOrEqual(count($ids), ++$pages);
        } while ($href !== null);
        $this->assertSame($ids, $seen);
        $this->assertGreaterThan(1, $pages);

        $this->actingAsMcp($owner, ['mcp:use', 'projects:read']);
        $base = $this->getJson(route('agent-api.v1.projects.show', [$workspace, $project->public_id, 'tasks_cursor' => $cursor]))->assertOk()
            ->assertJsonMissingPath('data.tasks')->assertJsonMissingPath('data.tasks_next_href');
        $this->assertSame($version, $base->json('data.version'));
        $session = $this->initialize();
        $reply = $this->callTool($session, 'projects.get', ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'tasks_cursor' => $cursor]);
        $this->assertFalse($reply['result']['isError'] ?? true);
        $this->assertArrayNotHasKey('tasks', $reply['result']['structuredContent']['data']);
        $this->assertArrayNotHasKey('tasks_next_href', $reply['result']['structuredContent']['data']);
    }

    public function test_project_listing_pages_long_unicode_descriptions_without_embedding_tasks(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $project->update(['description' => str_repeat('🧭', 5000)]);
        $expected = [$project->public_id];
        for ($index = 1; $index < 13; $index++) {
            $expected[] = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'name' => 'Synthetic Unicode project '.$index, 'description' => str_repeat('🧭', 5000)])->public_id;
        }
        $this->actingAsMcp($owner, ['mcp:use', 'projects:read']);
        $session = $this->initialize();
        $cursor = null;
        $seen = [];
        $pages = 0;
        do {
            $args = $cursor === null ? [] : ['cursor' => $cursor];
            $page = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/projects?'.http_build_query($args))->assertOk()->json();
            $reply = $this->callTool($session, 'projects.list', ['workspace_id' => $workspace->public_id, ...$args]);
            $this->assertFalse($reply['result']['isError'] ?? true);
            $this->assertSame($page['data'], $reply['result']['structuredContent']['data']);
            $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($reply['result'], JSON_THROW_ON_ERROR)));
            foreach ($page['data'] as $row) {
                $this->assertSame(str_repeat('🧭', 5000), $row['description']);
                $this->assertArrayNotHasKey('tasks', $row);
            }
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
            $this->assertLessThanOrEqual(count($expected), ++$pages);
        } while ($cursor !== null);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame($expected, $seen);
    }

    public function test_task_cursor_cannot_be_reused_for_a_different_project(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->tasks($workspace, $project, $owner, 2, 'Synthetic small description');
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic other project']);
        $cursor = AgentApiCursor::encode(1, $workspace->public_id, 'tasks|project_id='.$project->public_id);
        $this->actingAsMcp($owner, ['mcp:use', 'projects:read', 'tasks:read']);
        $this->getJson(route('agent-api.v1.projects.show', [$workspace, $other->public_id, 'tasks_cursor' => $cursor]))->assertUnprocessable();
        $session = $this->initialize();
        $reply = $this->callTool($session, 'projects.get', ['workspace_id' => $workspace->public_id, 'project_id' => $other->public_id, 'tasks_cursor' => $cursor]);
        $this->assertTrue($reply['result']['isError']);
    }

    public function test_project_embed_stops_at_twenty_five_tasks_even_with_unused_byte_budget(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $ids = $this->tasks($workspace, $project, $owner, 26, 'Synthetic short task description');
        $this->actingAsMcp($owner, ['projects:read', 'tasks:read']);
        $page = $this->getJson(route('agent-api.v1.projects.show', [$workspace, $project->public_id]))->assertOk()->json('data');
        $this->assertCount(25, $page['tasks']);
        $this->assertSame(array_slice($ids, 0, 25), array_column($page['tasks'], 'id'));
        $this->assertIsString($page['tasks_next_href']);
        $next = $this->getJson($page['tasks_next_href'])->assertOk()->json('data');
        $this->assertSame([$ids[25]], array_column($next['tasks'], 'id'));
        $this->assertNull($next['tasks_next_href']);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic task pagination', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic paged company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic paged project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    /** @return list<string> */
    private function tasks(Workspace $workspace, ClientProject $project, User $owner, int $count, ?string $description = null): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated([
                'title' => 'Synthetic Unicode task '.$index, 'description' => $description ?? $this->description(), 'assignee_ids' => [$owner->public_id],
            ]))->public_id;
        }

        return $ids;
    }

    private function description(): string
    {
        return str_repeat('🚀', 9998).'"\\';
    }
}
