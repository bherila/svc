<?php

namespace Tests\Feature\Tasks\Organization;

use App\Http\Controllers\Api\V1\AgentTaskOrganizationController;
use App\Http\Controllers\ClientTaskOrganizationController;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTaskList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Test-only routes prove the additive adapters; production registry wiring is separate. */
final class TaskOrganizationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['api/v1/synthetic-organization', AgentTaskOrganizationController::class, ['api', 'auth:api']],
            ['synthetic-organization', ClientTaskOrganizationController::class, ['web', 'auth:web']]] as [$prefix, $controller, $middleware]) {
            Route::prefix($prefix.'/{workspace}/{project}')->middleware($middleware)->group(function () use ($controller): void {
                Route::get('/', [$controller, 'show']);
                foreach ([['post', '/lists', 'lists.create'], ['patch', '/lists/{list}', 'lists.update'], ['delete', '/lists/{list}', 'lists.archive'],
                    ['post', '/lists/{list}/restore', 'lists.restore'], ['put', '/lists/order', 'lists.reorder'],
                    ['post', '/tasks', 'tasks.create'], ['patch', '/tasks/{task}/container', 'tasks.move'], ['put', '/tasks/order', 'tasks.reorder']] as [$verb, $uri, $operation]) {
                    Route::$verb($uri, [$controller, 'mutate'])->defaults('task_organization_operation', $operation);
                }
            });
        }
    }

    public function test_agent_routes_keep_identities_separate_from_body_and_share_native_snapshot_facts(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $base = '/api/v1/synthetic-organization/'.$workspace->public_id.'/'.$project->public_id;
        $graph = $this->getJson($base)->assertOk()->json('data');
        $created = $this->withHeader('Idempotency-Key', 'synthetic-http-list')->postJson($base.'/lists',
            ['name' => 'Synthetic HTTP private list', 'expected_graph_version' => $graph['graph_version']])->assertCreated();
        $listId = $created->json('data.affected_ids.0');
        $graph = $this->getJson($base)->assertOk()->json('data');
        $this->assertFalse($graph['lists'][0]['is_visible_to_client']);
        $this->withHeader('Idempotency-Key', 'synthetic-http-forged-list')->patchJson($base.'/lists/'.$listId,
            ['list_id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Synthetic refused name',
                'expected_version' => $graph['lists'][0]['version'], 'expected_graph_version' => $graph['graph_version']])->assertUnprocessable();
        $this->assertSame('Synthetic HTTP private list', ClientTaskList::query()->sole()->name);
        $this->withHeader('Idempotency-Key', 'synthetic-http-publish-list')->patchJson($base.'/lists/'.$listId,
            ['is_visible_to_client' => true, 'expected_version' => $graph['lists'][0]['version'], 'expected_graph_version' => $graph['graph_version']])->assertOk();
        $graph = $this->getJson($base)->assertOk()->json('data');
        $taskId = $this->withHeader('Idempotency-Key', 'synthetic-http-root')->postJson($base.'/tasks',
            ['parent_id' => null, 'list_id' => $listId, 'task' => ['title' => 'Synthetic HTTP root', 'description' => null],
                'expected_graph_version' => $graph['graph_version']])->assertCreated()->json('data.affected_ids.0');
        $graph = $this->getJson($base)->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'synthetic-http-unlist-root')->patchJson($base.'/tasks/'.$taskId.'/container',
            ['parent_id' => null, 'list_id' => null, 'expected_version' => $graph['tree'][0]['version'], 'expected_graph_version' => $graph['graph_version']])->assertOk();
        $container = $this->getJson($base.'?container=1')->assertOk()->json('data.container');
        $this->assertSame([$taskId], array_column($container['tasks'], 'id'));
        $this->withHeader('Idempotency-Key', 'synthetic-http-order-root')->putJson($base.'/tasks/order',
            ['parent_id' => null, 'list_id' => null, 'ordered_ids' => [$taskId], 'expected_container_version' => $container['version']])->assertOk();
        $agent = $this->getJson($base.'?container=1')->assertOk()->json('data');
        $this->actingAs($owner, 'web');
        $native = $this->getJson('/synthetic-organization/'.$workspace->public_id.'/'.$project->public_id.'?container=1')->assertOk()->json('data');
        $this->assertSame($agent, $native);
        $this->assertDatabaseCount('client_task_graph_states', 1);
    }

    public function test_disabled_management_and_dual_agent_refusals_create_no_state_while_native_origin_is_server_owned(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $base = '/api/v1/synthetic-organization/'.$workspace->public_id.'/'.$project->public_id;
        $graph = $this->getJson($base)->assertOk()->json('data');
        $input = ['name' => 'Synthetic controlled list', 'expected_graph_version' => $graph['graph_version']];
        config(['task_organization.enabled' => false]);
        $this->getJson($base)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-disabled')->postJson($base.'/lists', $input)->assertNotFound();
        config(['task_organization.enabled' => true]);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        $this->getJson($base)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-dual')->postJson($base.'/lists', $input)->assertForbidden();
        foreach (['client_task_graph_states', 'client_task_lists', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->actingAs($owner, 'web');
        $native = '/synthetic-organization/'.$workspace->public_id.'/'.$project->public_id;
        $this->withHeader('Idempotency-Key', 'synthetic-native-forged')->postJson($native.'/lists', $input + ['origin' => 'Agent'])->assertUnprocessable();
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->flushHeaders();
        $this->postJson($native.'/lists', $input + ['idempotency_key' => 'synthetic-native-body-key'])->assertCreated();
        $this->assertDatabaseCount('client_task_lists', 1);
        $this->assertDatabaseHas('agent_mutation_receipts', ['oauth_client_id' => 'svc-web-task-organization', 'operation' => 'task_organization.lists.create', 'idempotency_key' => 'synthetic-native-body-key']);
    }

    /** @return array{Workspace,ClientProject,User} */
    private function fixture(): array
    {
        config(['task_organization.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic HTTP organization', 'slug' => 'synthetic-http-organization']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic HTTP company', 'slug' => 'synthetic-http-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic HTTP project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner];
    }
}
