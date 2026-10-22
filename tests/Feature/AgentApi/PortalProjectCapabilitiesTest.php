<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\AgentCapabilities;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class PortalProjectCapabilitiesTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    #[DataProvider('transports')]
    public function test_context_discloses_only_currently_granted_project_capabilities(string $transport): void
    {
        config(['agent_api.mcp_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic capability workspace', 'slug' => 'synthetic-capability-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic capability client', 'slug' => 'synthetic-capability-client']);
        $project = fn (string $name, bool $visible): ClientProject => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => $name, 'is_visible_to_client' => $visible]);
        $assigned = $project('Synthetic assigned', true);
        $other = $project('Synthetic other', true);
        $project('Synthetic private', false);
        $user = User::factory()->create();
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $assigned->id]);
        $principal = $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ]);
        $session = $transport === 'mcp' ? $this->initialize() : null;

        $context = fn (): array => $transport === 'mcp'
            ? $this->callTool((string) $session, 'context.get', [])['result']['structuredContent']['data']['workspaces'][0]['project_capabilities']
            : $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.project_capabilities');
        $this->assertSame([$assigned->public_id], array_column($context(), 'project_id'));
        foreach ([$user, $principal] as $actor) {
            $this->assertTrue(app(AgentAccess::class)->canViewProject($actor, $assigned));
            $this->assertFalse(app(AgentAccess::class)->canViewProject($actor, $other));
        }

        DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->delete();
        $this->assertSame([], $context());

        $membership->update(['access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->assertSame([$assigned->public_id, $other->public_id], array_column($context(), 'project_id'));
    }

    public static function transports(): array
    {
        return [['rest'], ['mcp']];
    }

    #[DataProvider('transports')]
    public function test_dual_workspace_owner_uses_portal_grants_and_client_capabilities(string $transport): void
    {
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic dual workspace', 'slug' => 'synthetic-dual-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic dual client', 'slug' => 'synthetic-dual-client']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $project = fn (ClientCompany $client, string $name, bool $visible): ClientProject => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $client->id, 'name' => $name, 'is_visible_to_client' => $visible]);
        $assigned = $project($company, 'Synthetic granted', true);
        $project($company, 'Synthetic ungranted', true);
        $project($company, 'Synthetic private', false);
        $project($otherCompany, 'Synthetic other company', true);
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $assigned->id]);
        $this->actingAsMcp($user, array_keys(AgentApiScopes::descriptions()));
        $context = $transport === 'mcp'
            ? $this->callTool($this->initialize(), 'context.get', [])['result']['structuredContent']['data']['workspaces'][0]
            : $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0');

        $this->assertSame([[
            'project_id' => $assigned->public_id,
            'role' => 'client',
            'capabilities' => ['projects:read', 'tasks:read', 'time:read'],
        ]], $context['project_capabilities']);
        foreach (['tasks:write', 'time:write', 'time:approve', 'files:read', 'finance:read'] as $internalCapability) {
            $this->assertNotContains($internalCapability, $context['capabilities']);
        }
    }

    public function test_internal_project_role_and_manager_preview_do_not_require_portal_grants(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic internal workspace', 'slug' => 'synthetic-internal-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic internal client', 'slug' => 'synthetic-internal-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic private project', 'is_visible_to_client' => false]);
        $user = User::factory()->create();
        $staff = $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => 'viewer']);
        $this->actingAsMcp($user, [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ]);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.workspaces.0.project_capabilities.0.project_id', $project->public_id);
        ClientProjectMembership::query()->where('workspace_id', $workspace->id)->delete();
        $this->getJson('/api/v1/context')->assertOk()->assertJsonCount(0, 'data.workspaces.0.project_capabilities');
        $staff->update(['role' => 'admin']);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.workspaces.0.project_capabilities.0.project_id', $project->public_id);
    }

    public function test_capability_authorization_queries_do_not_grow_per_project(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic bounded workspace', 'slug' => 'synthetic-bounded-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic bounded client', 'slug' => 'synthetic-bounded-client']);
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $project = fn (int $index) => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic bounded '.$index]);
        $project(0);
        $observe = function () use ($user, $workspace): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $data = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (string $scope): bool => $scope === AgentApiScopes::PROJECTS_READ);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, count($data['project_capabilities'])];
        };
        [$singleQueries, $singleRows] = $observe();
        for ($index = 1; $index < 20; $index++) {
            $project($index);
        }
        [$manyQueries, $manyRows] = $observe();
        $this->assertSame(1, $singleRows);
        $this->assertSame(20, $manyRows);
        $this->assertSame($singleQueries, $manyQueries);
    }

    public function test_legacy_project_membership_without_workspace_membership_grants_no_private_capabilities(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic legacy workspace', 'slug' => 'synthetic-legacy-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic legacy client', 'slug' => 'synthetic-legacy-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic legacy private project', 'is_visible_to_client' => false]);
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        $this->writingLegacyCrossTenantRows(fn () => ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => 'viewer']));
        $this->actingAsMcp($user, [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ]);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonCount(0, 'data.workspaces.0.project_capabilities');
    }

    public function test_a_portal_membership_owned_by_another_tenant_adds_no_capabilities(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic local workspace', 'slug' => 'synthetic-local-workspace']);
        $foreign = Workspace::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'synthetic-foreign-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic local client', 'slug' => 'synthetic-local-client']);
        ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic visible project', 'is_visible_to_client' => true]);
        $user = User::factory()->create();
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_company_memberships')->where('id', $membership->id)->update(['workspace_id' => $foreign->id]));
        $data = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (): bool => true);
        $this->assertSame([], $data['project_capabilities']);
        $this->assertSame([], $data['capabilities']);
    }
}
