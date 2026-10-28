<?php

namespace Tests\Feature\Tasks\Planning;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskLabel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** Real public bindings: synthetic fixtures, no test routes or replacement catalog. */
final class TaskPlanningRegisteredRoutesTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private ?string $session = null;

    private const array BINDINGS = [
        'get' => ['GET', '/tasks/{task}/planning', 'task'],
        'labels.list' => ['GET', '/task-labels', null],
        'labels.get' => ['GET', '/task-labels/{label}', 'label'],
        'labels.create' => ['POST', '/task-labels', null],
        'labels.update' => ['PATCH', '/task-labels/{label}', 'label'],
        'labels.archive' => ['DELETE', '/task-labels/{label}', 'label'],
        'labels.restore' => ['POST', '/task-labels/{label}/restore', 'label'],
        'labels.options' => ['GET', '/projects/{project}/task-label-options', 'project'],
        'labels.replace' => ['PUT', '/tasks/{task}/labels', 'task'],
        'dependencies.get' => ['GET', '/tasks/{task}/dependencies', 'task'],
        'dependencies.options' => ['GET', '/tasks/{task}/dependency-options', 'task'],
        'dependencies.replace' => ['PUT', '/tasks/{task}/dependencies', 'task'],
        'contacts.get' => ['GET', '/tasks/{task}/contact-responsibilities', 'task'],
        'contacts.options' => ['GET', '/tasks/{task}/contact-responsibility-options', 'task'],
        'contacts.replace' => ['PUT', '/tasks/{task}/contact-responsibilities', 'task'],
    ];

    /** @return iterable<string,array{string}> */
    public static function surfaces(): iterable
    {
        yield 'native' => ['native'];
        yield 'REST' => ['rest'];
        yield 'MCP' => ['mcp'];
    }

    #[DataProvider('surfaces')]
    public function test_all_fifteen_registered_operations_preserve_complete_sets_versions_and_receipt_replays(string $surface): void
    {
        [$workspace, , $project, $owner, $task, $prerequisite, $contact] = $this->fixture();
        $this->connect($surface, $owner);
        if ($surface === 'mcp') {
            $names = $this->toolNames($this->session);
            foreach (array_keys(self::BINDINGS) as $operation) {
                $this->assertContains('task_planning.'.$operation, $names);
            }
        }
        $read = fn (): array => $this->success($surface, $workspace, 'get', target: $task->public_id);
        $initial = $read();
        $this->assertSame([], $initial['labels']);
        $this->assertArrayNotHasKey('contacts', $initial);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $label = $this->success($surface, $workspace, 'labels.create', ['name' => 'Synthetic registered label'], key: 'synthetic-create')['id'];
        $this->assertSame([$label], array_column($this->success($surface, $workspace, 'labels.list'), 'id'));
        $labelRow = $this->success($surface, $workspace, 'labels.get', target: $label);
        $this->success($surface, $workspace, 'labels.update', ['name' => 'Synthetic renamed label', 'expected_version' => $labelRow['version']], $label, 'synthetic-rename');
        $this->assertSame('Synthetic renamed label', $this->success($surface, $workspace, 'labels.get', target: $label)['name']);
        $this->assertSame([$label], array_column($this->success($surface, $workspace, 'labels.options', target: $project->public_id), 'id'));
        $this->success($surface, $workspace, 'labels.replace', ['expected_version' => $initial['version'], 'label_ids' => [$label]], $task->public_id, 'synthetic-labels');
        $this->assertSame([$label], array_column($read()['labels'], 'id'));
        $dependency = $this->success($surface, $workspace, 'dependencies.get', target: $task->public_id);
        $this->assertSame([$prerequisite->public_id], array_column($this->success($surface, $workspace, 'dependencies.options', target: $task->public_id), 'id'));
        $this->success($surface, $workspace, 'dependencies.replace', ['expected_version' => $dependency['version'], 'expected_graph_version' => $dependency['graph_version'], 'prerequisite_ids' => [$prerequisite->public_id]], $task->public_id, 'synthetic-dependencies');
        $this->assertTrue($read()['dependency_summary']['blocked']);
        $this->assertSame([$prerequisite->public_id], array_column($this->success($surface, $workspace, 'dependencies.get', target: $task->public_id)['prerequisites'], 'id'));
        $contacts = $this->success($surface, $workspace, 'contacts.get', target: $task->public_id);
        $this->assertSame([], $contacts['contacts']);
        $choices = $this->success($surface, $workspace, 'contacts.options', target: $task->public_id);
        $this->assertSame([$contact->public_id], array_column($choices, 'id'));
        $this->assertEqualsCanonicalizing(['id', 'display_name', 'is_archived', 'version'], array_keys($choices[0]));
        $this->success($surface, $workspace, 'contacts.replace', ['expected_version' => $contacts['version'], 'contact_ids' => [$contact->public_id]], $task->public_id, 'synthetic-contacts');
        $this->assertSame([$contact->public_id], array_column($this->success($surface, $workspace, 'contacts.get', target: $task->public_id)['contacts'], 'id'));
        $labelRow = $this->success($surface, $workspace, 'labels.get', target: $label);
        $this->success($surface, $workspace, 'labels.archive', ['expected_version' => $labelRow['version'], 'confirm' => true], $label, 'synthetic-archive');
        $this->assertTrue($read()['labels'][0]['is_archived']);
        $this->assertSame([], $this->success($surface, $workspace, 'labels.options', target: $project->public_id));
        $archived = $this->success($surface, $workspace, 'labels.get', target: $label);
        $this->success($surface, $workspace, 'labels.restore', ['expected_version' => $archived['version']], $label, 'synthetic-restore');
        $this->assertFalse($read()['labels'][0]['is_archived']);
        $this->success($surface, $workspace, 'labels.replace', ['expected_version' => $read()['version'], 'label_ids' => []], $task->public_id, 'synthetic-clear-labels');
        $dependency = $this->success($surface, $workspace, 'dependencies.get', target: $task->public_id);
        $this->success($surface, $workspace, 'dependencies.replace', ['expected_version' => $dependency['version'], 'expected_graph_version' => $dependency['graph_version'], 'prerequisite_ids' => []], $task->public_id, 'synthetic-clear-dependencies');
        $this->success($surface, $workspace, 'contacts.replace', ['expected_version' => $read()['version'], 'contact_ids' => []], $task->public_id, 'synthetic-clear-contacts');
        $this->assertSame([], $read()['labels']);
        $this->assertFalse($read()['dependency_summary']['blocked']);
        foreach (['client_task_label_assignments', 'client_task_dependencies', 'client_task_contact_responsibilities'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 10);
        $this->assertDatabaseCount('client_task_graph_states', 1);
        $this->assertSame('open', $task->fresh()->status);
        $this->assertDatabaseCount('client_tasks', 2);
        $this->assertDatabaseCount('client_contacts', 1);
        $this->assertDatabaseCount('client_project_memberships', 0);
        $this->assertDatabaseCount('client_company_memberships', 0);
    }

    public function test_product_off_hides_all_discovery_and_refuses_native_and_first_party_api_without_a_bypass(): void
    {
        [$workspace, , , $owner, $task] = $this->fixture();
        config(['agent_api.writes_enabled' => false]);
        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
        $this->getJson($this->url('rest', $workspace, 'get', $task->public_id))->assertOk();
        config(['task_planning_details.enabled' => false, 'task_organization.enabled' => true]);
        $this->get(route('tasks.planning.labels.page', [$workspace]))->assertNotFound();
        foreach (['native', 'rest'] as $surface) {
            foreach (self::BINDINGS as $operation => [$verb]) {
                $this->withHeader('Idempotency-Key', 'synthetic-disabled-'.$operation)->json($verb,
                    $this->url($surface, $workspace, $operation, $task->public_id), [])->assertNotFound();
            }
        }
        $this->connect('mcp', $owner);
        foreach ($this->toolNames($this->session) as $name) {
            $this->assertStringStartsNotWith('task_planning.', $name);
        }
        foreach (['client_task_labels', 'client_task_graph_states', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_missing_contact_scope_omits_tools_and_data_while_current_project_managers_cannot_access_contact_or_catalog_authority(): void
    {
        [$workspace, , $project, $owner, $task] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read', 'tasks:write']);
        $this->getJson($this->url('rest', $workspace, 'get', $task->public_id))->assertOk()->assertJsonMissingPath('data.contacts');
        foreach (['contacts.get', 'contacts.options', 'contacts.replace'] as $operation) {
            [$verb] = self::BINDINGS[$operation];
            $this->withHeader('Idempotency-Key', 'synthetic-missing-contact-scope')->json($verb,
                $this->url('rest', $workspace, $operation, $task->public_id), [])->assertForbidden();
        }
        $session = $this->initialize();
        $names = $this->toolNames($session);
        foreach (['contacts.get', 'contacts.options', 'contacts.replace'] as $operation) {
            $this->assertNotContains('task_planning.'.$operation, $names);
        }
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->connect('rest', $manager);
        $version = $this->getJson($this->url('rest', $workspace, 'get', $task->public_id))->assertOk()->json('data.version');
        $this->getJson($this->url('rest', $workspace, 'labels.options', $project->public_id))->assertOk();
        foreach (['labels.list', 'labels.get', 'contacts.get', 'contacts.options'] as $operation) {
            $this->getJson($this->url('rest', $workspace, $operation, $task->public_id))->assertForbidden();
        }
        $this->withHeader('Idempotency-Key', 'synthetic-manager-no-contact')->putJson($this->url('rest', $workspace, 'contacts.replace', $task->public_id),
            ['expected_version' => $version, 'contact_ids' => []])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-manager-label-clear')->putJson($this->url('rest', $workspace, 'labels.replace', $task->public_id),
            ['expected_version' => $version, 'label_ids' => []])->assertOk();
    }

    public function test_dual_agent_and_portal_origins_refuse_planning_but_native_dual_staff_retains_trusted_authority(): void
    {
        [$workspace, $company, , $owner, $task] = $this->fixture();
        $portal = User::factory()->create();
        foreach ([$portal, $owner] as $actor) {
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'client']);
            $this->connect('rest', $actor);
            foreach (['get', 'dependencies.get', 'contacts.get', 'labels.list'] as $operation) {
                $response = $this->getJson($this->url('rest', $workspace, $operation, $task->public_id))->assertForbidden();
                $this->assertStringNotContainsString('Synthetic private contact', $response->getContent());
            }
            $this->withHeader('Idempotency-Key', 'synthetic-dual-'.$actor->id)->postJson($this->url('rest', $workspace, 'labels.create'), ['name' => 'Synthetic forbidden label'])->assertForbidden();
            $this->connect('mcp', $actor);
            foreach (['get', 'contacts.get'] as $operation) {
                $reply = $this->callTool($this->session, 'task_planning.'.$operation, ['workspace_id' => $workspace->public_id, 'task_id' => $task->public_id]);
                $this->assertTrue(isset($reply['error']) || ($reply['result']['isError'] ?? false));
                $this->assertArrayNotHasKey('structuredContent', $reply['result'] ?? []);
            }
        }
        $this->connect('native', $owner);
        $this->success('native', $workspace, 'labels.create', ['name' => 'Synthetic native dual label'], key: 'synthetic-trusted-native-dual');
        $this->assertDatabaseCount('client_task_labels', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_rest_and_mcp_share_exact_receipts_but_changed_bodies_and_revoked_current_authority_never_replay(): void
    {
        [$workspace, , , $owner, $task] = $this->fixture();
        $this->connect('rest', $owner);
        $label = $this->success('rest', $workspace, 'labels.create', ['name' => 'Synthetic shared receipt'], key: 'synthetic-shared-label')['id'];
        $snapshot = $this->success('rest', $workspace, 'get', target: $task->public_id);
        $body = ['expected_version' => $snapshot['version'], 'label_ids' => [$label]];
        $url = $this->url('rest', $workspace, 'labels.replace', $task->public_id);
        $this->withHeader('Idempotency-Key', 'synthetic-rest-mcp-shared')->putJson($url, $body)->assertOk();
        $version = $task->fresh()->lock_version;
        $this->connect('mcp', $owner);
        $arguments = ['workspace_id' => $workspace->public_id, 'task_id' => $task->public_id, 'idempotency_key' => 'synthetic-rest-mcp-shared'] + $body;
        $reply = $this->callTool($this->session, 'task_planning.labels.replace', $arguments);
        $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply, JSON_THROW_ON_ERROR));
        $this->assertSame($task->public_id, $reply['result']['structuredContent']['data']['id']);
        $this->assertSame($version, $task->fresh()->lock_version);
        $changed = $this->callTool($this->session, 'task_planning.labels.replace', array_replace($arguments, ['label_ids' => []]));
        $this->assertTrue(isset($changed['error']) || ($changed['result']['isError'] ?? false));
        $this->assertDatabaseCount('client_task_label_assignments', 1);
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->connect('rest', $owner);
        $this->withHeader('Idempotency-Key', 'synthetic-rest-mcp-shared')->putJson($url, $body)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'synthetic-revoked-removal')->putJson($url, ['expected_version' => str_repeat('x', 64), 'label_ids' => []])->assertForbidden();
        $this->assertSame($version, $task->fresh()->lock_version);
        $this->assertDatabaseCount('agent_mutation_receipts', 2);
        $this->assertDatabaseCount('client_task_label_assignments', 1);
    }

    public function test_foreign_tenant_targets_and_associations_fail_without_disclosing_names_or_creating_partial_revisions(): void
    {
        [$workspace, , , $owner, $task] = $this->fixture();
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'synthetic-foreign-workspace']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Synthetic foreign company secret', 'slug' => 'synthetic-foreign-company']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign project secret']);
        $foreignTask = ClientTask::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_project_id' => $foreignProject->id, 'title' => 'Synthetic foreign task secret']);
        $foreignContact = ClientContact::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id, 'first_name' => 'Synthetic foreign contact secret']);
        $foreignLabel = ClientTaskLabel::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Synthetic foreign label secret', 'name_key' => hash('sha256', 'synthetic foreign label secret')]);
        foreach (['native', 'rest'] as $surface) {
            $this->connect($surface, $owner);
            foreach (['get', 'dependencies.get', 'contacts.get'] as $operation) {
                $reply = $this->getJson($this->url($surface, $workspace, $operation, $foreignTask->public_id))->assertNotFound();
                $this->assertStringNotContainsString('Synthetic foreign', $reply->getContent());
            }
            $this->getJson($this->url($surface, $workspace, 'labels.get', $foreignLabel->public_id))->assertNotFound();
            $state = $this->success($surface, $workspace, 'dependencies.get', target: $task->public_id);
            foreach (['labels.replace' => ['label_ids' => [$foreignLabel->public_id]],
                'contacts.replace' => ['contact_ids' => [$foreignContact->public_id]],
                'dependencies.replace' => ['prerequisite_ids' => [$foreignTask->public_id], 'expected_graph_version' => $state['graph_version']]] as $operation => $body) {
                $reply = $this->withHeader('Idempotency-Key', 'synthetic-foreign-'.$surface.'-'.$operation)->putJson($this->url($surface, $workspace, $operation, $task->public_id),
                    ['expected_version' => $state['version']] + $body)->assertUnprocessable();
                foreach (['Synthetic foreign', $foreignTask->public_id, $foreignContact->public_id, $foreignLabel->public_id] as $secret) {
                    $this->assertStringNotContainsString($secret, $reply->getContent());
                }
            }
        }
        foreach (['client_task_label_assignments', 'client_task_contact_responsibilities', 'client_task_dependencies', 'client_task_graph_states', 'agent_mutation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('agent_mutation_audits', 6);
        $this->assertSame(['failed'], DB::table('agent_mutation_audits')->distinct()->pluck('outcome')->all());
        $this->assertSame(['validation'], DB::table('agent_mutation_audits')->distinct()->pluck('error_category')->all());
        $this->assertSame(['[]'], DB::table('agent_mutation_audits')->distinct()->pluck('affected_public_ids')->all());
        $this->assertSame(1, $task->fresh()->lock_version);
    }

    public function test_agent_write_cutover_withholds_new_writers_but_preserves_reads_and_trusted_native_actions(): void
    {
        [$workspace, , , $owner, $task] = $this->fixture();
        config(['agent_api.writes_enabled' => false]);
        $this->connect('rest', $owner);
        $this->getJson($this->url('rest', $workspace, 'get', $task->public_id))->assertOk();
        $this->withHeader('Idempotency-Key', 'synthetic-held-agent-writer')->postJson($this->url('rest', $workspace, 'labels.create'),
            ['name' => 'Synthetic withheld label'])->assertNotFound();
        $this->connect('mcp', $owner);
        $names = $this->toolNames($this->session);
        foreach (self::BINDINGS as $suffix => [$verb]) {
            if ($verb === 'GET') {
                $this->assertContains('task_planning.'.$suffix, $names);
            } else {
                $this->assertNotContains('task_planning.'.$suffix, $names);
            }
        }
        $this->assertDatabaseCount('client_task_labels', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->connect('native', $owner);
        $this->success('native', $workspace, 'labels.create', ['name' => 'Synthetic trusted native label'], key: 'synthetic-native-separate-cutover');
        $this->assertDatabaseCount('client_task_labels', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
    }

    public function test_registered_native_label_catalog_is_real_inertia_with_bounded_finished_capabilities(): void
    {
        [$workspace, , , $owner] = $this->fixture();
        $this->connect('native', $owner);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? ''];
        $this->withHeaders($headers)->get(route('tasks.planning.labels.page', [$workspace]))->assertOk()
            ->assertJsonPath('component', 'clients/task-labels')->assertJsonPath('props.catalog.labels', [])
            ->assertJsonPath('props.catalog.create_href', route('agent-api.v1.task-planning.labels.create', [$workspace]));
    }

    public function test_native_empty_search_and_catalog_continuations_survive_http_empty_string_normalization(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        for ($index = 0; $index < 27; $index++) {
            ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic empty search '.$index, 'name_key' => hash('sha256', 'synthetic empty search '.$index)]);
        }
        $this->connect('native', $owner);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? ''];
        $first = $this->withHeaders($headers)->get(route('tasks.planning.labels.page', [$workspace, 'status' => 'active', 'q' => '']))->assertOk()
            ->assertJsonCount(25, 'props.catalog.labels')->assertJsonPath('props.catalog.filters.q', '');
        $next = $first->json('props.catalog.next_href');
        $this->assertIsString($next);
        $this->get($next)->assertOk()->assertJsonCount(2, 'props.catalog.labels')->assertJsonPath('props.catalog.next_href', null);
        $this->connect('rest', $owner);
        $this->getJson($this->url('rest', $workspace, 'labels.options', $project->public_id).'?q=')->assertOk()->assertJsonCount(25, 'data');
    }

    private function connect(string $surface, User $actor): void
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        if ($surface === 'native') {
            $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));

            return;
        }
        $this->actingAsMcp($actor, ['mcp:use', 'tasks:read', 'tasks:write', 'clients:read']);
        if ($surface === 'mcp') {
            $this->session = $this->initialize();
        }
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private function success(string $surface, Workspace $workspace, string $operation, array $body = [], ?string $target = null, string $key = 'synthetic-read'): array
    {
        [$verb, , $targetName] = self::BINDINGS[$operation];
        if ($surface === 'mcp') {
            $arguments = ['workspace_id' => $workspace->public_id] + $body;
            if ($targetName !== null) {
                $arguments[$targetName.'_id'] = $target;
            }
            if ($verb !== 'GET') {
                $arguments['idempotency_key'] = $key;
            }
            $reply = $this->callTool($this->session, 'task_planning.'.$operation, $arguments);
            $this->assertArrayHasKey('result', $reply, json_encode($reply, JSON_THROW_ON_ERROR));
            $this->assertFalse($reply['result']['isError'] ?? true, json_encode($reply, JSON_THROW_ON_ERROR));
            $data = $reply['result']['structuredContent']['data'];
        } else {
            $url = $this->url($surface, $workspace, $operation, $target);
            $response = $this->withHeader('Idempotency-Key', $key)->json($verb, $url, $body)->assertStatus($operation === 'labels.create' ? 201 : 200);
            $data = $response->json('data');
        }
        $this->assertIsArray($data);
        if ($verb !== 'GET') {
            $before = DB::table('client_tasks')->orderBy('id')->pluck('lock_version', 'public_id')->all();
            $graph = DB::table('client_task_graph_states')->orderBy('id')->get()->toJson();
            if ($surface === 'mcp') {
                $replay = $this->callTool($this->session, 'task_planning.'.$operation, $arguments);
                $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay, JSON_THROW_ON_ERROR));
                $this->assertSame($data, $replay['result']['structuredContent']['data']);
            } else {
                $this->withHeader('Idempotency-Key', $key)->json($verb, $url, $body)->assertStatus($operation === 'labels.create' ? 201 : 200)->assertExactJson(['data' => $data]);
            }
            $this->assertSame($before, DB::table('client_tasks')->orderBy('id')->pluck('lock_version', 'public_id')->all());
            $this->assertSame($graph, DB::table('client_task_graph_states')->orderBy('id')->get()->toJson());
        }

        return $data;
    }

    private function url(string $surface, Workspace $workspace, string $operation, ?string $target = null): string
    {
        $path = self::BINDINGS[$operation][1];

        return '/api/v1'.'/workspaces/'.$workspace->public_id
            .str_replace(['{task}', '{project}', '{label}'], $target ?? '', $path);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,ClientTask,ClientTask,ClientContact} */
    private function fixture(): array
    {
        config(['task_planning_details.enabled' => true, 'agent_api.writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic registered planning', 'slug' => 'synthetic-registered-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic registered company', 'slug' => 'synthetic-registered-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic registered project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic source task', 'is_visible_to_client' => true]);
        $prerequisite = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic prerequisite task', 'is_visible_to_client' => true]);
        $contact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic private contact', 'email' => 'synthetic-contact@example.test']);

        return [$workspace, $company, $project, $owner, $task, $prerequisite, $contact];
    }
}
