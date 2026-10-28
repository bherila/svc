<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\AgentCapabilities;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingGate;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public static function dualManagers(): array
    {
        return [['rest', 'owner'], ['mcp', 'owner'], ['rest', 'admin'], ['mcp', 'admin']];
    }

    #[DataProvider('dualManagers')]
    public function test_dual_workspace_manager_retains_internal_capabilities_and_can_log_time(string $transport, string $workspaceRole): void
    {
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true,
            'agent_api.time_entry_writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true,
            'agent_api.file_writes_enabled' => true, 'agent_api.collaboration_writes_enabled' => true]);
        $this->enableFileSharing();
        $workspace = Workspace::query()->create(['name' => 'Synthetic dual workspace', 'slug' => 'synthetic-dual-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic dual client', 'slug' => 'synthetic-dual-client']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $project = fn (ClientCompany $client, string $name, bool $visible): ClientProject => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $client->id, 'name' => $name, 'is_visible_to_client' => $visible]);
        $assigned = $project($company, 'Synthetic granted', true);
        $ungranted = $project($company, 'Synthetic ungranted', true);
        $private = $project($company, 'Synthetic private', false);
        $other = $project($otherCompany, 'Synthetic other company', true);
        $foreign = Workspace::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'synthetic-foreign-workspace']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-client']);
        ClientProject::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign project']);
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $workspaceRole]);
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert([
            ['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $assigned->id],
            ['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $private->id],
        ]);
        $this->actingAsMcp($user, array_keys(AgentApiScopes::descriptions()));
        $session = $transport === 'mcp' ? $this->initialize() : null;
        $workspaces = $transport === 'mcp'
            ? $this->callTool((string) $session, 'context.get', [])['result']['structuredContent']['data']['workspaces']
            : $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces');
        $this->assertCount(1, $workspaces);
        $context = $workspaces[0];
        $this->assertSame($workspaceRole, $context['workspace_role']);
        $this->assertSame(array_map(fn (ClientProject $project): array => [
            'project_id' => $project->public_id,
            'role' => 'owner',
            'capabilities' => $project->is($assigned)
                ? ['projects:read', 'tasks:read', 'time:read', 'time:write', 'time:approve', 'expenses:read', 'files:read']
                : ['time:read', 'time:write', 'time:approve', 'expenses:read'],
        ], [$assigned, $ungranted, $private, $other]), $context['project_capabilities']);
        foreach (['time:write', 'time:approve', 'billing:write', 'finance:read'] as $internalCapability) {
            $this->assertContains($internalCapability, $context['capabilities']);
        }
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$assigned->public_id}")->assertOk();
        foreach ([$ungranted, $private, $other] as $hidden) {
            $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$hidden->public_id}")->assertNotFound();
        }
        // File adapters deliberately retain the portal audience for dual identities.
        $this->assertNotContains('files:write', $context['capabilities']);
        $this->assertNotContains('tasks:write', $context['capabilities']);

        foreach ([$assigned, $ungranted, $private, $other] as $target) {
            if ($transport === 'mcp') {
                $refused = $this->callTool((string) $session, 'tasks.create', ['workspace_id' => $workspace->public_id,
                    'project_id' => $target->public_id, 'title' => 'Synthetic refused dual task', 'idempotency_key' => 'synthetic-dual-task-'.$target->public_id]);
                $this->assertTrue($refused['result']['isError']);
            } else {
                $this->withHeader('Idempotency-Key', 'synthetic-dual-task-'.$target->public_id)
                    ->postJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$target->public_id}/tasks", ['title' => 'Synthetic refused dual task'])->assertForbidden();
            }
        }
        $this->assertDatabaseCount('client_tasks', 0);

        $entries = array_map(fn (ClientProject $project): array => ['project_id' => $project->public_id,
            'worked_on' => '2026-10-27', 'minutes' => 15, 'description' => 'Synthetic dual-role work'], [$assigned, $ungranted, $private, $other]);
        if ($transport === 'mcp') {
            $result = $this->callTool((string) $session, 'time_entries.log', ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-dual-role-time', 'entries' => $entries])['result'];
            $this->assertFalse($result['isError']);
            $this->assertCount(4, $result['structuredContent']['data']);
        } else {
            $this->withHeader('Idempotency-Key', 'synthetic-dual-role-time')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => $entries])->assertCreated()->assertJsonCount(4, 'data');
        }
        $this->assertSame(4, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->count());

        config(['agent_api.time_entry_writes_enabled' => false, 'agent_api.writes_enabled' => false]);
        $disabled = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (): bool => true);
        foreach (['tasks:write', 'time:write', 'time:approve', 'billing:write'] as $capability) {
            $this->assertNotContains($capability, $disabled['capabilities']);
            foreach ($disabled['project_capabilities'] as $entry) {
                $this->assertNotContains($capability, $entry['capabilities']);
            }
        }
        config(['agent_api.time_entry_writes_enabled' => true, 'agent_api.writes_enabled' => true]);
        $scoped = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (string $scope): bool => $scope === AgentApiScopes::PROJECTS_READ);
        $this->assertSame(['projects:read'], $scoped['capabilities']);
        foreach ($scoped['project_capabilities'] as $entry) {
            $this->assertSame($entry['project_id'] === $assigned->public_id ? ['projects:read'] : [], $entry['capabilities']);
        }
    }

    #[DataProvider('transports')]
    public function test_dual_member_keeps_project_roles_without_promoting_portal_grants(string $transport): void
    {
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic mixed workspace', 'slug' => 'synthetic-mixed-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic mixed client', 'slug' => 'synthetic-mixed-client']);
        $project = fn (string $name, bool $visible): ClientProject => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => $name, 'is_visible_to_client' => $visible]);
        $managed = $project('Synthetic internal managed', false);
        $portal = $project('Synthetic portal only', true);
        $project('Synthetic outside grants', true);
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $managed->id, 'user_id' => $user->id, 'role' => 'manager']);
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $portal->id]);
        $this->actingAsMcp($user, array_keys(AgentApiScopes::descriptions()));
        $context = $transport === 'mcp'
            ? $this->callTool($this->initialize(), 'context.get', [])['result']['structuredContent']['data']['workspaces'][0]
            : $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0');
        $this->assertSame([
            ['project_id' => $managed->public_id, 'role' => 'manager', 'capabilities' => ['time:read', 'time:write', 'time:approve', 'expenses:read']],
            ['project_id' => $portal->public_id, 'role' => 'client', 'capabilities' => ['projects:read', 'tasks:read', 'time:read', 'files:read']],
        ], $context['project_capabilities']);
        $this->assertNotContains('billing:write', $context['capabilities']);
        $this->assertNotContains('finance:read', $context['capabilities']);
        $this->assertNotContains('files:write', $context['capabilities']);
        $this->assertNotContains('tasks:write', $context['capabilities']);
        $entry = fn (ClientProject $project): array => ['project_id' => $project->public_id, 'worked_on' => '2026-10-27', 'minutes' => 15, 'description' => 'Synthetic mixed-role work'];
        $this->withHeader('Idempotency-Key', 'synthetic-mixed-managed-time')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [$entry($managed)]])->assertCreated();
        $this->withHeader('Idempotency-Key', 'synthetic-mixed-portal-time')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [$entry($portal)]])->assertNotFound();
    }

    private function enableFileSharing(): void
    {
        $directory = sys_get_temp_dir().'/svc-capability-files-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        File::put($directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        $this->app->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($directory));
        config(['svc.attachments.sharing_writes_enabled' => true]);
        $this->assertTrue(AttachmentSharingGate::available());
    }

    public function test_scoped_portal_file_capability_requires_both_scope_families(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic paired workspace', 'slug' => 'synthetic-paired-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic paired client', 'slug' => 'synthetic-paired-client']);
        ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic paired project', 'is_visible_to_client' => true]);
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        foreach ([[AgentApiScopes::FILES_READ], [AgentApiScopes::COLLABORATION_READ], [AgentApiScopes::FILES_READ, AgentApiScopes::COLLABORATION_READ]] as $scopes) {
            $data = app(AgentCapabilities::class)->forWorkspace($user, $workspace,
                fn (string $scope): bool => $scope === AgentApiScopes::PROJECTS_READ || in_array($scope, $scopes, true));
            $this->assertSame(count($scopes) === 2, in_array('files:read', $data['capabilities'], true));
            $this->assertSame(count($scopes) === 2, in_array('files:read', $data['project_capabilities'][0]['capabilities'], true));
            $this->assertNotContains('files:write', $data['capabilities']);
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

    public function test_legacy_orphan_portal_membership_still_withholds_task_and_file_writes(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic orphan workspace', 'slug' => 'synthetic-orphan-workspace']);
        $foreign = Workspace::query()->create(['name' => 'Synthetic orphan foreign', 'slug' => 'synthetic-orphan-foreign']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic orphan client', 'slug' => 'synthetic-orphan-client']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Synthetic foreign orphan client', 'slug' => 'synthetic-foreign-orphan-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic orphan project']);
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client']);
        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_company_memberships')->where('id', $membership->id)->update(['client_company_id' => $foreignCompany->id]));
        $data = app(AgentCapabilities::class)->forWorkspace($user, $workspace, fn (): bool => true);
        $this->assertNotContains('tasks:write', $data['capabilities']);
        $this->assertNotContains('files:write', $data['capabilities']);
        $this->assertNotContains('tasks:write', $data['project_capabilities'][0]['capabilities']);
        $this->assertNotContains('files:write', $data['project_capabilities'][0]['capabilities']);
        $this->assertContains('time:write', $data['project_capabilities'][0]['capabilities']);
        $this->actingAsMcp($user, [AgentApiScopes::TASKS_WRITE]);
        $this->withHeader('Idempotency-Key', 'synthetic-orphan-task')->postJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks", ['title' => 'Synthetic orphan task'])->assertForbidden();
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
