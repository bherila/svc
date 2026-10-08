<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AgentProjectParityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_rest_project_creation_uses_the_public_client_revision_and_replays_with_audits(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::CLIENTS_READ, AgentApiScopes::PROJECTS_WRITE]);
        $client = $this->getJson($this->base($workspace).'/clients/'.$company->public_id)->assertOk()->json('data');
        $this->assertSame($company->public_id, $client['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $client['version']);
        $body = ['company_id' => $client['id'], 'name' => 'Synthetic public REST project', 'expected_version' => $client['version'], 'confirm' => true];
        $path = $this->base($workspace).'/projects';
        $created = $this->withHeader('Idempotency-Key', 'public-rest-project')->postJson($path, $body)->assertCreated()->json('data');
        $replayed = $this->withHeader('Idempotency-Key', 'public-rest-project')->postJson($path, $body)->assertCreated()->json('data');
        $this->assertSame($created, $replayed);
        $this->assertSame($client['id'], $created['company_id']);
        $this->assertDatabaseCount('client_projects', 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertProjectCreateAudits($workspace, $owner, $created['id']);
    }

    public function test_mcp_project_creation_uses_the_public_client_revision_and_replays_with_audits(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::CLIENTS_READ, AgentApiScopes::PROJECTS_WRITE]);
        $client = $this->callProjectTool('clients.get', ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id])['data'];
        $this->assertSame($company->public_id, $client['id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $client['version']);
        $arguments = ['workspace_id' => $workspace->public_id, 'company_id' => $client['id'], 'name' => 'Synthetic public MCP project', 'expected_version' => $client['version'], 'confirm' => true, 'idempotency_key' => 'public-mcp-project'];
        $created = $this->callProjectTool('projects.create', $arguments)['data'];
        $this->assertSame($created, $this->callProjectTool('projects.create', $arguments)['data']);
        $this->assertSame($client['id'], $created['company_id']);
        $this->assertDatabaseCount('client_projects', 2);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertProjectCreateAudits($workspace, $owner, $created['id']);
    }

    public function test_project_create_checks_locked_company_version_normalizes_repository_and_replays(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        $path = $this->base($workspace).'/projects';
        $body = ['company_id' => $company->public_id, 'name' => 'Synthetic new project', 'repository' => 'https://github.com/synthetic/project.git', 'expected_version' => AgentApiVersion::for($company), 'confirm' => true];
        $this->postJson($path, $body)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'create-without-confirm')->postJson($path, [...$body, 'confirm' => false])->assertUnprocessable();
        $company->update(['name' => 'Synthetic changed company']);
        $this->withHeader('Idempotency-Key', 'create-stale')->postJson($path, $body)->assertConflict();
        $body['expected_version'] = AgentApiVersion::for($company->fresh());
        $created = $this->withHeader('Idempotency-Key', 'create-project')->postJson($path, $body)->assertCreated()->assertJsonPath('data.repository', 'github.com/synthetic/project')->assertJsonPath('data.company_id', $company->public_id)->assertJsonPath('data.is_visible_to_client', true)->json('data');
        $this->withHeader('Idempotency-Key', 'create-project')->postJson($path, $body)->assertCreated()->assertJsonPath('data.id', $created['id']);
        $this->assertDatabaseCount('client_projects', 2);
        $this->withHeader('Idempotency-Key', 'create-project')->postJson($path, [...$body, 'name' => 'Different project'])->assertConflict();
        $this->assertSame('Synthetic project', $project->fresh()->name);
        foreach (['success', 'replay', 'failed'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'projects.create', 'outcome' => $outcome]);
        }
    }

    public function test_update_archive_and_restore_preserve_omitted_fields_and_refuse_stale_writes(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        $path = $this->base($workspace).'/projects/'.$project->public_id;
        $oldVersion = AgentApiVersion::for($project);
        $body = ['expected_version' => $oldVersion, 'name' => 'Synthetic renamed', 'confirm' => true];
        $updated = $this->withHeader('Idempotency-Key', 'project-update')->patchJson($path, $body)->assertOk()->assertJsonPath('data.description', 'Synthetic project description')->assertJsonPath('data.repository', 'github.com/synthetic/existing')->assertJsonPath('data.is_visible_to_client', false)->json('data');
        $this->assertNotSame($oldVersion, $updated['version']);
        $this->withHeader('Idempotency-Key', 'project-update')->patchJson($path, $body)->assertOk()->assertJsonPath('data.version', $updated['version']);
        $this->withHeader('Idempotency-Key', 'project-update-stale')->patchJson($path, ['expected_version' => $oldVersion, 'name' => 'Stale overwrite', 'confirm' => true])->assertConflict();
        $cleared = $this->withHeader('Idempotency-Key', 'project-clear')->patchJson($path, ['expected_version' => $updated['version'], 'description' => null, 'repository' => null, 'confirm' => true])->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.repository', null)->json('data');
        $archiveBody = ['expected_version' => $cleared['version'], 'confirm' => true];
        $archived = $this->withHeader('Idempotency-Key', 'project-archive')->postJson($path.'/archive', $archiveBody)->assertOk()->assertJsonPath('data.status', 'archived')->json('data');
        $this->withHeader('Idempotency-Key', 'project-archive')->postJson($path.'/archive', $archiveBody)->assertOk()->assertJsonPath('data.version', $archived['version']);
        $this->withHeader('Idempotency-Key', 'project-restore')->patchJson($path, ['expected_version' => $archived['version'], 'status' => 'active', 'confirm' => true])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseCount('client_projects', 1);
        foreach (['projects.update', 'projects.archive'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'success']);
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'replay']);
        }
    }

    public function test_member_access_lists_eligible_people_and_grants_replaces_revokes_with_project_revision(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $otherMember = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_READ, AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::CLIENTS_READ]);
        $path = $this->base($workspace).'/projects/'.$project->public_id.'/members';
        $first = $this->getJson($path.'?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $member->public_id)->assertJsonPath('data.0.role', 'none')->json();
        $this->assertIsString($first['meta']['next_cursor']);
        $this->getJson($path.'?limit=1&cursor='.urlencode($first['meta']['next_cursor']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $otherMember->public_id)->assertJsonPath('meta.next_cursor', null);
        $this->assertArrayNotHasKey('email', $first['data'][0]);
        $this->assertNotContains($owner->public_id, array_column($this->getJson($path)->assertOk()->json('data'), 'user_id'));
        $version = $first['version'];
        foreach (['viewer', 'contributor', 'manager', 'owner'] as $role) {
            $body = ['user_id' => $member->public_id, 'role' => $role, 'expected_version' => $version, 'confirm' => true];
            $updated = $this->withHeader('Idempotency-Key', 'project-grant-'.$role)->putJson($path, $body)->assertOk()->json('data');
            $this->assertNotSame($version, $updated['version']);
            $this->withHeader('Idempotency-Key', 'project-grant-'.$role)->putJson($path, $body)->assertOk()->assertJsonPath('data.version', $updated['version']);
            $this->withHeader('Idempotency-Key', 'project-stale-'.$role)->putJson($path, [...$body, 'role' => 'none'])->assertConflict();
            $this->getJson($path)->assertOk()->assertJsonPath('data.0.role', $role);
            $version = $updated['version'];
            $this->assertDatabaseCount('client_project_memberships', 1);
        }
        $this->withHeader('Idempotency-Key', 'project-revoke')->putJson($path, ['user_id' => $member->public_id, 'role' => 'none', 'expected_version' => $version, 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('client_project_memberships', 0);
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.role', 'none');
        foreach (['success', 'replay', 'failed'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'projects.members.update', 'outcome' => $outcome]);
        }
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Another synthetic project']);
        $this->getJson($this->base($workspace).'/projects/'.$otherProject->public_id.'/members?cursor='.urlencode($first['meta']['next_cursor']))->assertUnprocessable();
    }

    public static function projectRoles(): iterable
    {
        foreach (['owner', 'manager', 'contributor', 'viewer'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('projectRoles')]
    public function test_project_roles_do_not_grant_workspace_project_administration(string $role): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $actor = $this->member($workspace);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $actor->id, 'role' => $role]);
        $this->actingAsMcp($actor, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::IDENTITY_READ, AgentApiScopes::CLIENTS_READ]);
        foreach ($this->requests($workspace, $company, $project, $actor) as [$method, $path, $body]) {
            $this->withHeader('Idempotency-Key', 'project-denied-'.$method.$path)->json($method, $path, $body)->assertForbidden();
        }
        $this->getJson($this->base($workspace).'/projects/'.$project->public_id.'/members')->assertForbidden();
        $this->assertNotContains('projects:write', $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities'));
    }

    public function test_old_task_and_client_scopes_do_not_admit_project_writes(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_READ, AgentApiScopes::TASKS_WRITE, AgentApiScopes::CLIENTS_WRITE]);
        foreach ($this->requests($workspace, $company, $project, $member) as [$method, $path, $body]) {
            $this->withHeader('Idempotency-Key', 'project-scope-'.$method.$path)->json($method, $path, $body)->assertForbidden();
        }
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE]);
        $this->getJson($this->base($workspace).'/projects/'.$project->public_id.'/members')->assertForbidden();
    }

    public function test_all_writes_require_confirmation_and_current_version(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        foreach ($this->requests($workspace, $company, $project, $member) as [$method, $path, $body]) {
            $this->withHeader('Idempotency-Key', 'project-confirm-'.$method.$path)->json($method, $path, [...$body, 'confirm' => false])->assertUnprocessable();
            unset($body['expected_version']);
            $this->withHeader('Idempotency-Key', 'project-version-'.$method.$path)->json($method, $path, $body)->assertUnprocessable();
        }
        $this->assertSame('active', $project->fresh()->status);
        $this->assertDatabaseCount('client_projects', 1);
        $this->assertDatabaseCount('client_project_memberships', 0);
    }

    public function test_foreign_companies_projects_and_people_never_cross_the_workspace_boundary(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        [$foreignOwner, $foreignWorkspace, $foreignCompany, $foreignProject] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        $this->withHeader('Idempotency-Key', 'foreign-company')->postJson($this->base($workspace).'/projects', ['company_id' => $foreignCompany->public_id, 'name' => 'Synthetic denied', 'expected_version' => AgentApiVersion::for($foreignCompany), 'confirm' => true])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'foreign-project')->patchJson($this->base($workspace).'/projects/'.$foreignProject->public_id, ['name' => 'Synthetic denied', 'expected_version' => AgentApiVersion::for($foreignProject), 'confirm' => true])->assertNotFound();
        $path = $this->base($workspace).'/projects/'.$project->public_id.'/members';
        $this->withHeader('Idempotency-Key', 'foreign-person')->putJson($path, ['user_id' => $foreignOwner->public_id, 'role' => 'viewer', 'expected_version' => AgentApiVersion::for($project), 'confirm' => true])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'workspace-owner')->putJson($path, ['user_id' => $owner->public_id, 'role' => 'viewer', 'expected_version' => AgentApiVersion::for($project), 'confirm' => true])->assertUnprocessable();
        $malformed = $this->writingLegacyCrossTenantRows(fn () => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Legacy synthetic orphan']));
        $this->withHeader('Idempotency-Key', 'foreign-project-company')->postJson($this->base($workspace).'/projects/'.$malformed->public_id.'/archive', ['expected_version' => AgentApiVersion::for($malformed), 'confirm' => true])->assertNotFound();
        $this->getJson($this->base($workspace).'/projects/'.$malformed->public_id.'/members')->assertNotFound();
        $this->getJson($this->base($workspace).'/projects/'.$foreignProject->public_id.'/members')->assertNotFound();
        $this->assertSame('Synthetic project', $foreignProject->fresh()->name);
        $this->assertDatabaseCount('client_project_memberships', 0);
    }

    public function test_project_member_mutation_conceals_foreign_and_unknown_accounts_identically(): void
    {
        config(['app.debug' => false]);
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        [$foreignOwner] = $this->tenant();
        $unaffiliated = User::factory()->create();
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ]);
        $path = $this->base($workspace).'/projects/'.$project->public_id.'/members';
        $body = ['role' => 'viewer', 'expected_version' => AgentApiVersion::for($project), 'confirm' => true];
        $missing = $this->withHeader('Idempotency-Key', 'unknown-project-member')->putJson($path, [...$body, 'user_id' => Str::uuid()->toString()])->assertNotFound();
        foreach ([$foreignOwner, $unaffiliated] as $index => $foreign) {
            $existing = $this->withHeader('Idempotency-Key', 'foreign-project-member-'.$index)->putJson($path, [...$body, 'user_id' => $foreign->public_id])->assertNotFound();
            $this->assertSame($missing->json(), $existing->json());
            $this->assertSame($missing->headers->get('Cache-Control'), $existing->headers->get('Cache-Control'));
        }
        $this->assertStringContainsString('no-store', (string) $missing->headers->get('Cache-Control'));
        $this->assertDatabaseCount('client_project_memberships', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertSame($body['expected_version'], AgentApiVersion::for($project->fresh()));
    }

    public function test_receipt_replay_rechecks_current_workspace_manager_authorization(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        $path = $this->base($workspace).'/projects/'.$project->public_id.'/archive';
        $body = ['expected_version' => AgentApiVersion::for($project), 'confirm' => true];
        $this->withHeader('Idempotency-Key', 'archive-replay-auth')->postJson($path, $body)->assertOk();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->withHeader('Idempotency-Key', 'archive-replay-auth')->postJson($path, $body)->assertForbidden();
    }

    public static function cutovers(): iterable
    {
        yield 'both off' => [false, false, false];
        yield 'global off' => [false, true, false];
        yield 'projects off' => [true, false, false];
        yield 'both on' => [true, true, true];
    }

    #[DataProvider('cutovers')]
    public function test_project_write_cutover_agrees_for_rest_mcp_and_capabilities(bool $writes, bool $projects, bool $enabled): void
    {
        config(['agent_api.writes_enabled' => $writes, 'agent_api.project_writes_enabled' => $projects]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::CLIENTS_READ]);
        $tools = array_column($this->mcp('tools/list', [])['tools'], 'name');
        foreach (['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'] as $tool) {
            $this->assertSame($enabled, in_array($tool, $tools, true));
        }
        $this->assertContains('projects.members.list', $tools);
        $this->assertSame($enabled, in_array('projects:write', $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities'), true));
        foreach ($this->requests($workspace, $company, $project, $member) as [$method, $path, $body]) {
            $body['expected_version'] = str_ends_with($path, '/projects') ? AgentApiVersion::for($company->fresh()) : AgentApiVersion::for($project->fresh());
            $response = $this->withHeader('Idempotency-Key', 'cutover-'.$method.$path)->json($method, $path, $body);
            $enabled ? $response->assertSuccessful() : $response->assertNotFound();
        }
    }

    public function test_mcp_can_create_edit_archive_and_manage_project_access_using_rest_contracts(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::CLIENTS_READ]);
        $createArgs = ['workspace_id' => $workspace->public_id, 'company_id' => $company->public_id, 'name' => 'Synthetic MCP project', 'expected_version' => AgentApiVersion::for($company), 'confirm' => true, 'idempotency_key' => 'mcp-create-project'];
        $created = $this->callProjectTool('projects.create', $createArgs)['data'];
        $this->assertSame($created['id'], $this->callProjectTool('projects.create', $createArgs)['data']['id']);
        $updated = $this->callProjectTool('projects.update', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id'], 'description' => 'Synthetic MCP description', 'repository' => 'https://github.com/synthetic/mcp.git', 'expected_version' => $created['version'], 'confirm' => true, 'idempotency_key' => 'mcp-update-project'])['data'];
        $this->assertSame('github.com/synthetic/mcp', $updated['repository']);
        $cleared = $this->callProjectTool('projects.update', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id'], 'description' => null, 'expected_version' => $updated['version'], 'confirm' => true, 'idempotency_key' => 'mcp-clear-project'])['data'];
        $this->assertNull($cleared['description']);
        $this->assertSame('github.com/synthetic/mcp', $cleared['repository']);
        $listed = $this->callProjectTool('projects.members.list', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id']]);
        $this->assertSame($member->public_id, $listed['data'][0]['user_id']);
        $grant = $this->callProjectTool('projects.members.update', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id'], 'user_id' => $member->public_id, 'role' => 'manager', 'expected_version' => $listed['version'], 'confirm' => true, 'idempotency_key' => 'mcp-project-member'])['data'];
        $this->assertSame('manager', $this->callProjectTool('projects.members.list', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id']])['data'][0]['role']);
        $archived = $this->callProjectTool('projects.archive', ['workspace_id' => $workspace->public_id, 'project_id' => $created['id'], 'expected_version' => $grant['version'], 'confirm' => true, 'idempotency_key' => 'mcp-project-archive'])['data'];
        $this->assertSame('archived', $archived['status']);
        foreach (['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'success']);
        }
    }

    public function test_access_locks_project_before_target_membership(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        LockOrderRecorder::start();
        try {
            $this->withHeader('Idempotency-Key', 'project-lock-order')->putJson($this->base($workspace).'/projects/'.$project->public_id.'/members', ['user_id' => $member->public_id, 'role' => 'viewer', 'expected_version' => AgentApiVersion::for($project), 'confirm' => true])->assertOk();
            $sequence = LockOrderRecorder::sequences()[0];
            $this->assertContains(LockResource::ClientProject, $sequence);
            $this->assertContains(LockResource::WorkspaceMembership, $sequence);
            $this->assertLessThan(array_search(LockResource::WorkspaceMembership, $sequence, true), array_search(LockResource::ClientProject, $sequence, true));
            $this->assertLessThan(LockResource::WorkspaceMembership->rank(), LockResource::ClientProject->rank());
        } finally {
            LockOrderRecorder::stop();
        }
    }

    #[DataProvider('nonLiteralConfirmations')]
    public function test_all_project_mutations_require_literal_true_confirmation(mixed $confirmation): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        foreach ($this->requests($workspace, $company, $project, $member) as $index => [$method, $path, $body]) {
            $body['confirm'] = $confirmation;
            $this->withHeader('Idempotency-Key', 'literal-confirm-'.$index)->json($method, $path, $body)->assertUnprocessable();
        }
        $this->assertSame('Synthetic project', $project->fresh()->name);
        $this->assertSame('active', $project->fresh()->status);
        $this->assertDatabaseCount('client_projects', 1);
        $this->assertDatabaseCount('client_project_memberships', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        foreach (['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => $operation, 'outcome' => 'failed', 'error_category' => 'validation']);
        }
    }

    public static function nonLiteralConfirmations(): iterable
    {
        foreach ([false, 1, '1', 'yes', 'true', 0, null] as $confirmation) {
            yield [$confirmation];
        }
    }

    public function test_project_mutation_bodies_are_closed_like_their_contracts(): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ]);
        foreach ($this->requests($workspace, $company, $project, $member) as $index => [$method, $path, $body]) {
            $this->withHeader('Idempotency-Key', 'closed-body-'.$index)->json($method, $path, [...$body, 'workspace_id' => $workspace->public_id])->assertUnprocessable();
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public static function revisionReadScopes(): iterable
    {
        yield 'write only' => [[], []];
        yield 'client revisions' => [[AgentApiScopes::CLIENTS_READ], ['projects.create']];
        yield 'project revisions' => [[AgentApiScopes::PROJECTS_READ], ['projects.update', 'projects.archive', 'projects.members.update']];
        yield 'all revisions' => [[AgentApiScopes::CLIENTS_READ, AgentApiScopes::PROJECTS_READ], ['projects.create', 'projects.update', 'projects.archive', 'projects.members.update']];
    }

    /** @param list<string> $reads
     * @param  list<string>  $available
     */
    #[DataProvider('revisionReadScopes')]
    public function test_versioned_project_writes_require_the_scope_that_reads_their_revision(array $reads, array $available): void
    {
        $this->enableWrites();
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = $this->member($workspace);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_WRITE, ...$reads]);
        $tools = array_column($this->mcp('tools/list', [])['tools'], 'name');
        $operations = ['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'];
        foreach ($this->requests($workspace, $company, $project, $member) as $index => [$method, $path, $body]) {
            $operation = $operations[$index];
            $allowed = in_array($operation, $available, true);
            $this->assertSame($allowed, in_array($operation, $tools, true), $operation);
            if ($allowed) {
                continue;
            }
            $this->withHeader('Idempotency-Key', 'missing-revision-scope-'.$index)->json($method, $path, $body)->assertForbidden();
            $arguments = [...$body, 'workspace_id' => $workspace->public_id, 'idempotency_key' => 'mcp-missing-revision-scope-'.$index];
            if ($operation !== 'projects.create') {
                $arguments['project_id'] = $project->public_id;
            }
            $result = $this->mcpResponse('tools/call', ['name' => $operation, 'arguments' => $arguments]);
            $this->assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false), $operation);
        }
        $this->assertSame($available !== [], in_array('projects:write', $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities'), true));
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('client_projects', 1);
        $this->assertDatabaseCount('client_project_memberships', 0);
    }

    public static function concealedWorkspaceModes(): iterable
    {
        $scopes = [AgentApiScopes::PROJECTS_WRITE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::CLIENTS_READ];
        yield 'full scopes' => [$scopes, true, true];
        yield 'missing scopes' => [[], true, true];
        yield 'outer writes disabled' => [$scopes, false, true];
        yield 'project writes disabled' => [$scopes, true, false];
    }

    /** @param list<string> $scopes */
    #[DataProvider('concealedWorkspaceModes')]
    public function test_new_project_routes_conceal_missing_and_inaccessible_workspaces_identically(array $scopes, bool $writes, bool $projectWrites): void
    {
        config(['app.debug' => false]);
        $this->enableWrites();
        [$owner] = $this->tenant();
        [, $foreign, $company, $project] = $this->tenant();
        $member = $this->member($foreign);
        config(['agent_api.writes_enabled' => $writes, 'agent_api.project_writes_enabled' => $projectWrites]);
        $this->actingAsMcp($owner, $scopes);
        $missing = Str::uuid()->toString();
        $requests = $this->requests($foreign, $company, $project, $member);
        $requests[] = ['GET', $this->base($foreign).'/projects/'.$project->public_id.'/members', []];
        foreach ($requests as $index => [$method, $path, $body]) {
            $existing = $this->json($method, $path, $body, ['Idempotency-Key' => 'foreign-workspace-'.$index])->assertNotFound();
            $unknown = $this->json($method, str_replace($foreign->public_id, $missing, $path), $body, ['Idempotency-Key' => 'missing-workspace-'.$index])->assertNotFound();
            $this->assertSame($existing->json(), $unknown->json());
            $this->assertSame($existing->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', (string) $unknown->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
        $this->assertSame('Synthetic project', $project->fresh()->name);
        $this->assertDatabaseCount('client_project_memberships', 0);
    }

    private function enableWrites(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.project_writes_enabled' => true]);
    }

    private function assertProjectCreateAudits(Workspace $workspace, User $owner, string $projectId): void
    {
        $audits = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'projects.create')->orderBy('id')->get();
        $this->assertSame(['success', 'replay'], $audits->pluck('outcome')->all());
        foreach ($audits as $audit) {
            $this->assertSame($owner->id, $audit->user_id);
            $this->assertSame([$projectId], $audit->affected_public_ids);
        }
    }

    /** @return array{User,Workspace,ClientCompany,ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic project workspace', 'slug' => 'synthetic-project-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-company-'.str()->random(8)]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project', 'description' => 'Synthetic project description', 'repository' => 'github.com/synthetic/existing', 'is_visible_to_client' => false]);

        return [$owner, $workspace, $company, $project];
    }

    private function member(Workspace $workspace): User
    {
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);

        return $user;
    }

    private function base(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id;
    }

    /** @return list<array{string,string,array<string,mixed>}> */
    private function requests(Workspace $workspace, ClientCompany $company, ClientProject $project, User $member): array
    {
        $path = $this->base($workspace).'/projects';
        $version = AgentApiVersion::for($project);

        return [
            ['POST', $path, ['company_id' => $company->public_id, 'name' => 'Synthetic created', 'expected_version' => AgentApiVersion::for($company), 'confirm' => true]],
            ['PATCH', $path.'/'.$project->public_id, ['name' => 'Synthetic updated', 'expected_version' => $version, 'confirm' => true]],
            ['POST', $path.'/'.$project->public_id.'/archive', ['expected_version' => $version, 'confirm' => true]],
            ['PUT', $path.'/'.$project->public_id.'/members', ['user_id' => $member->public_id, 'role' => 'viewer', 'expected_version' => $version, 'confirm' => true]],
        ];
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed> */
    private function callProjectTool(string $tool, array $arguments): array
    {
        $result = $this->mcp('tools/call', ['name' => $tool, 'arguments' => $arguments]);
        $this->assertFalse($result['isError'] ?? true, json_encode($result, JSON_THROW_ON_ERROR));

        return $result['structuredContent'];
    }

    /** @param array<string,mixed> $params
     * @return array<string,mixed> */
    private function mcp(string $method, array $params): array
    {
        $response = $this->mcpResponse($method, $params);
        $this->assertArrayHasKey('result', $response);

        return $response['result'];
    }

    /** @param array<string,mixed> $params
     * @return array<string,mixed> */
    private function mcpResponse(string $method, array $params): array
    {
        $initialized = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 'initialize', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic projects client', 'version' => '1']]], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk();
        $response = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 'projects-call', 'method' => $method, 'params' => (object) $params], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $initialized->headers->get('Mcp-Session-Id')])->assertOk()->json();
        if ($method === 'tools/list' && array_intersect(['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'], array_column($response['result']['tools'], 'name')) !== []) {
            $this->assertStringContainsString('explicit user confirmation', $initialized->json('result.instructions'));
        }

        return $response;
    }
}
