<?php

namespace Tests\Feature\Tasks\Organization;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** Real production routes and request-bound MCP handlers; no synthetic route registration. */
final class TaskOrganizationRegisteredRoutesTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private ?string $session = null;

    private const array BINDINGS = [
        'get' => ['GET', ''],
        'lists.create' => ['POST', '/lists'],
        'lists.update' => ['PATCH', '/lists/{id}'],
        'lists.archive' => ['DELETE', '/lists/{id}'],
        'lists.restore' => ['POST', '/lists/{id}/restore'],
        'lists.reorder' => ['PUT', '/lists/order'],
        'tasks.create' => ['POST', '/tasks'],
        'tasks.move' => ['PATCH', '/tasks/{id}/container'],
        'tasks.reorder' => ['PUT', '/tasks/order'],
    ];

    public static function surfaces(): iterable
    {
        yield 'native JSON' => ['native'];
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('surfaces')]
    public function test_all_nine_registered_bindings_share_project_manager_versions_receipts_and_structural_behavior(string $surface): void
    {
        [$workspace, $company, $project, $manager] = $this->fixture();
        $this->connect($surface, $manager);
        if ($surface === 'native') {
            $this->withHeaders($this->inertiaHeaders())->get(route('tasks.organization.show', [$workspace, $company, $project->public_id]))
                ->assertOk()->assertJsonPath('component', 'clients/task-organization');
            $this->withoutHeader('X-Inertia');
            $this->withoutHeader('X-Inertia-Version');
        }
        if ($surface === 'mcp') {
            $names = $this->toolNames($this->session);
            foreach (array_keys(self::BINDINGS) as $operation) {
                $this->assertContains('task_organization.'.$operation, $names);
            }
        }
        $read = fn (array $query = []): array => $this->success($surface, $workspace, $project, 'get', $query);
        $graph = $read();
        $this->assertSame([], $graph['tree']);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $list = $this->success($surface, $workspace, $project, 'lists.create', ['name' => 'Synthetic registered list', 'expected_graph_version' => $graph['graph_version']], key: 'synthetic-list-create')['affected_ids'][0];
        $graph = $read();
        $this->assertFalse($graph['lists'][0]['is_visible_to_client']);
        $oldGraph = $graph['graph_version'];
        $this->success($surface, $workspace, $project, 'lists.update', ['name' => 'Synthetic renamed list', 'is_visible_to_client' => true,
            'expected_graph_version' => $oldGraph, 'expected_version' => $graph['lists'][0]['version']], $list, 'synthetic-list-update');
        $graph = $read();
        $this->assertSame('Synthetic renamed list', $graph['lists'][0]['name']);
        $this->assertTrue($graph['lists'][0]['is_visible_to_client']);
        $task = $this->success($surface, $workspace, $project, 'tasks.create', ['task' => ['title' => 'Synthetic registered root'], 'parent_id' => null, 'list_id' => $list,
            'expected_graph_version' => $graph['graph_version']], key: 'synthetic-task-create')['affected_ids'][0];
        $graph = $read();
        $this->assertSame($list, $graph['tree'][0]['list_id']);
        $this->assertDatabaseHas('client_tasks', ['public_id' => $task, 'status' => 'open', 'is_visible_to_client' => false]);
        $this->success($surface, $workspace, $project, 'tasks.move', ['parent_id' => null, 'list_id' => null,
            'expected_graph_version' => $graph['graph_version'], 'expected_version' => $graph['tree'][0]['version']], $task, 'synthetic-task-move');
        $graph = $read();
        $secondTask = $this->success($surface, $workspace, $project, 'tasks.create', ['task' => ['title' => 'Synthetic second root'], 'parent_id' => null, 'list_id' => null,
            'expected_graph_version' => $graph['graph_version']], key: 'synthetic-second-task-create')['affected_ids'][0];
        $container = $read(['container' => true])['container'];
        $this->assertSame([$task, $secondTask], array_column($container['tasks'], 'id'));
        $this->success($surface, $workspace, $project, 'tasks.reorder', ['ordered_ids' => [$secondTask, $task], 'parent_id' => null, 'list_id' => null,
            'expected_container_version' => $container['version']], key: 'synthetic-task-order');
        $this->assertSame([$secondTask, $task], array_column($read(['container' => true])['container']['tasks'], 'id'));
        $graph = $read();
        $secondList = $this->success($surface, $workspace, $project, 'lists.create', ['name' => 'Synthetic second list', 'expected_graph_version' => $graph['graph_version']], key: 'synthetic-second-list-create')['affected_ids'][0];
        $graph = $read();
        $this->success($surface, $workspace, $project, 'lists.reorder', ['ordered_ids' => [$secondList, $list], 'expected_container_version' => $graph['lists_version']], key: 'synthetic-list-order');
        $graph = $read();
        $this->assertSame([$secondList, $list], array_column($graph['lists'], 'id'));
        $row = collect($graph['lists'])->firstWhere('id', $list);
        $this->success($surface, $workspace, $project, 'lists.archive', ['confirm' => true, 'expected_graph_version' => $graph['graph_version'], 'expected_version' => $row['version']], $list, 'synthetic-list-archive');
        $graph = $read();
        $row = collect($graph['lists'])->firstWhere('id', $list);
        $this->assertTrue($row['archived']);
        $restore = ['expected_graph_version' => $graph['graph_version'], 'expected_version' => $row['version']];
        $restored = $this->success($surface, $workspace, $project, 'lists.restore', $restore, $list, 'synthetic-list-restore');
        $graph = $read();
        $this->assertFalse(collect($graph['lists'])->firstWhere('id', $list)['archived']);
        $this->assertSame($restored, $this->success($surface, $workspace, $project, 'lists.restore', $restore, $list, 'synthetic-list-restore'));
        $this->assertSame($graph['graph_version'], $read()['graph_version'], 'Exact receipt replay must not advance organization revisions.');
        $this->assertDatabaseCount('client_task_graph_states', 1);
        $this->assertDatabaseCount('client_tasks', 2);
        $this->assertDatabaseCount('client_task_lists', 2);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'task_organization.lists.restore', 'outcome' => 'replay']);

        if ($surface !== 'mcp') {
            $url = $this->url($surface, $workspace, $project, 'lists.update', $list);
            $this->withHeader('Idempotency-Key', 'synthetic-stale-registered')->patchJson($url,
                ['name' => 'Synthetic stale change', 'expected_graph_version' => $oldGraph, 'expected_version' => collect($graph['lists'])->firstWhere('id', $list)['version']])->assertConflict();
            $this->withHeader('Idempotency-Key', 'synthetic-body-identity')->patchJson($url,
                ['list_id' => $list, 'expected_graph_version' => $graph['graph_version'], 'expected_version' => collect($graph['lists'])->firstWhere('id', $list)['version']])->assertUnprocessable();
            $this->assertSame($graph['graph_version'], $read()['graph_version']);
        } else {
            $arguments = ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'list_id' => $list,
                'name' => 'Synthetic stale change', 'expected_graph_version' => $oldGraph, 'expected_version' => collect($graph['lists'])->firstWhere('id', $list)['version'],
                'idempotency_key' => 'synthetic-stale-mcp'];
            $reply = $this->callTool($this->session, 'task_organization.lists.update', $arguments);
            $this->assertTrue(isset($reply['error']) || ($reply['result']['isError'] ?? false));
            $arguments['expected_graph_version'] = $graph['graph_version'];
            $arguments['idempotency_key'] = 'synthetic-mcp-body-identity';
            $reply = $this->callTool($this->session, 'task_organization.lists.update', $arguments + ['body' => ['list_id' => $list]]);
            $this->assertTrue(isset($reply['error']) || ($reply['result']['isError'] ?? false), 'A caller cannot inject an alternative raw REST body through a tool.');
            $this->assertSame($graph['graph_version'], $read()['graph_version']);
        }
    }

    public function test_product_off_refuses_all_registered_routes_and_first_party_bypass_without_persisting_anything(): void
    {
        [$workspace, $company, $project, $manager] = $this->fixture();
        config(['agent_api.writes_enabled' => false]);
        // A real signed-in session reaches the API before the product is disabled.
        $this->actingAs($manager, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->getJson($this->url('rest', $workspace, $project, 'get'))->assertOk();
        config(['task_organization.enabled' => false]);
        $this->actingAs($manager, 'web')->get(route('tasks.organization.show', [$workspace, $company, $project->public_id]))->assertNotFound();
        foreach (['native', 'rest'] as $surface) {
            foreach (self::BINDINGS as $operation => [$verb]) {
                $url = $this->url($surface, $workspace, $project, $operation, '00000000-0000-4000-8000-000000000001');
                $this->withHeader('Idempotency-Key', 'synthetic-disabled-'.$operation)->json($verb, $url, [])->assertNotFound();
            }
        }
        $this->connect('mcp', $manager);
        foreach ($this->toolNames($this->session) as $name) {
            $this->assertStringStartsNotWith('task_organization.', $name);
        }
        foreach (['client_task_graph_states', 'client_tasks', 'client_task_lists', 'client_task_assignments', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_scope_and_agent_cutover_gates_preserve_snapshot_reads_and_native_mutations(): void
    {
        [$workspace, , $project, $manager] = $this->fixture();
        $this->actingAsMcp($manager, ['mcp:use', 'tasks:read']);
        $graph = $this->getJson($this->url('rest', $workspace, $project, 'get'))->assertOk()->json('data');
        $input = ['name' => 'Synthetic cutover list', 'expected_graph_version' => $graph['graph_version']];
        $this->withHeader('Idempotency-Key', 'synthetic-missing-write-scope')->postJson($this->url('rest', $workspace, $project, 'lists.create'), $input)->assertForbidden();
        config(['agent_api.writes_enabled' => false]);
        $this->connect('rest', $manager);
        $this->getJson($this->url('rest', $workspace, $project, 'get'))->assertOk();
        $this->withHeader('Idempotency-Key', 'synthetic-write-cutover')->postJson($this->url('rest', $workspace, $project, 'lists.create'), $input)->assertNotFound();
        $this->connect('mcp', $manager);
        $names = $this->toolNames($this->session);
        $this->assertContains('task_organization.get', $names);
        foreach (array_diff(array_keys(self::BINDINGS), ['get']) as $operation) {
            $this->assertNotContains('task_organization.'.$operation, $names);
        }
        foreach (['client_task_graph_states', 'client_task_lists', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->connect('native', $manager);
        $this->success('native', $workspace, $project, 'lists.create', $input, key: 'synthetic-native-ignores-agent-cutover');
        $this->assertDatabaseCount('client_task_lists', 1);
    }

    public function test_native_project_only_supplies_the_finished_organization_link_to_eligible_staff_while_enabled(): void
    {
        [$workspace, $company, $project, $manager] = $this->fixture();
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $href = route('tasks.organization.show', [$workspace, $company, $project->public_id]);
        foreach ([$owner, $manager] as $actor) {
            $this->actingAs($actor, 'web')->withHeaders($this->inertiaHeaders())->get(route('clients.project', [$workspace, $company, $project]))
                ->assertOk()->assertJsonPath('props.organization_href', $href);
        }
        $this->actingAs($viewer, 'web')->get(route('clients.project', [$workspace, $company, $project]))
            ->assertOk()->assertJsonPath('props.organization_href', null);
        config(['task_organization.enabled' => false]);
        foreach ([$owner, $manager] as $actor) {
            $this->actingAs($actor, 'web')->get(route('clients.project', [$workspace, $company, $project]))
                ->assertOk()->assertJsonPath('props.organization_href', null)->assertJsonPath('props.task_management.projects.0.id', $project->public_id);
        }
        $this->withoutHeader('X-Inertia')->withoutHeader('X-Inertia-Version');
    }

    public function test_registered_agent_origin_denies_portal_dual_nonmanager_and_foreign_lineage_while_native_dual_keeps_staff_authority(): void
    {
        [$workspace, $company, $project, $manager] = $this->fixture();
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $portal = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client']);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'client']);
        foreach ([$viewer, $portal, $manager] as $actor) {
            $this->connect('rest', $actor);
            $this->getJson($this->url('rest', $workspace, $project, 'get'))->assertForbidden();
            $this->withHeader('Idempotency-Key', 'synthetic-forbidden-'.$actor->id)->postJson($this->url('rest', $workspace, $project, 'lists.create'),
                ['name' => 'Synthetic forbidden list', 'expected_graph_version' => str_repeat('a', 64)])->assertForbidden();
            $this->connect('mcp', $actor);
            foreach (['get' => [], 'lists.create' => ['name' => 'Synthetic forbidden list', 'expected_graph_version' => str_repeat('a', 64), 'idempotency_key' => 'synthetic-forbidden-mcp-'.$actor->id]] as $operation => $body) {
                $reply = $this->callTool($this->session, 'task_organization.'.$operation, ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id] + $body);
                $this->assertTrue(isset($reply['error']) || ($reply['result']['isError'] ?? false));
                $this->assertArrayNotHasKey('structuredContent', $reply['result'] ?? []);
            }
        }
        foreach (['client_task_graph_states', 'client_task_lists', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->connect('native', $manager);
        $snapshot = $this->success('native', $workspace, $project, 'get');
        $this->success('native', $workspace, $project, 'lists.create', ['name' => 'Synthetic native dual list', 'expected_graph_version' => $snapshot['graph_version']], key: 'synthetic-native-dual');
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic foreign organization', 'slug' => 'synthetic-foreign-organization']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Synthetic foreign company secret', 'slug' => 'synthetic-foreign-organization-company']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign project secret']);
        $this->getJson($this->url('native', $workspace, $foreignProject, 'get'))->assertNotFound();
        $this->get(route('tasks.organization.show', [$workspace, $company, $foreignProject->public_id]))->assertNotFound();
        $this->get(route('tasks.organization.show', [$workspace, $foreignCompany, $project->public_id]))->assertNotFound();
        $this->assertDatabaseCount('client_task_graph_states', 1);
        $this->assertDatabaseCount('client_task_lists', 1);
    }

    private function connect(string $surface, User $actor): void
    {
        $this->flushHeaders();
        if ($surface === 'native') {
            $this->actingAs($actor, 'web');

            return;
        }
        $this->actingAsMcp($actor, ['mcp:use', 'tasks:read', 'tasks:write']);
        if ($surface === 'mcp') {
            $this->session = $this->initialize();
        }
    }

    /** @return array<string,string> */
    private function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '')];
    }

    /** @return array<string,mixed> */
    private function success(string $surface, Workspace $workspace, ClientProject $project, string $operation, array $body = [], ?string $identity = null, string $key = 'synthetic-read'): array
    {
        if ($surface === 'mcp') {
            $arguments = ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id] + $body;
            if ($identity !== null) {
                $arguments[str_starts_with($operation, 'lists.') ? 'list_id' : 'task_id'] = $identity;
            }
            if ($operation !== 'get') {
                $arguments['idempotency_key'] = $key;
            }
            $reply = $this->callTool($this->session, 'task_organization.'.$operation, $arguments);
            $this->assertArrayHasKey('result', $reply, json_encode($reply, JSON_THROW_ON_ERROR));
            $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply, JSON_THROW_ON_ERROR));

            return $reply['result']['structuredContent']['data'];
        }
        [$verb] = self::BINDINGS[$operation];
        $url = $this->url($surface, $workspace, $project, $operation, $identity);
        if ($operation === 'get') {
            return $this->getJson($url.($body === [] ? '' : '?'.http_build_query($body)))->assertOk()->json('data');
        }

        return $this->withHeader('Idempotency-Key', $key)->json($verb, $url, $body)
            ->assertStatus(str_ends_with($operation, '.create') ? 201 : 200)->json('data');
    }

    private function url(string $surface, Workspace $workspace, ClientProject $project, string $operation, ?string $identity = null): string
    {
        $prefix = $surface === 'native' ? '' : '/api/v1';

        return $prefix.'/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/task-organization'
            .str_replace('{id}', $identity ?? '', self::BINDINGS[$operation][1]);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        config(['task_organization.enabled' => true, 'agent_api.writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic registered organization', 'slug' => 'synthetic-registered-organization']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic registered company', 'slug' => 'synthetic-registered-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic registered project', 'is_visible_to_client' => true]);
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);

        return [$workspace, $company, $project, $manager];
    }
}
