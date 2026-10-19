<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\AgentApi\AgentPortalContactReadService;
use App\Services\WorkspaceProjectMutationAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AccessAuditFollowupsTest extends TestCase
{
    use BuildsAccessFixtures;
    use CallsMcp;
    use RefreshDatabase;

    public static function transports(): iterable
    {
        yield 'REST' => [false];
        yield 'MCP' => [true];
    }

    #[DataProvider('transports')]
    public function test_assignment_audits_carry_request_correlation_and_replays_do_not_duplicate_them(bool $mcp): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.project_writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Audit', 'slug' => 'synthetic-audit']);
        $owner = $this->staff($workspace, 'owner');
        $member = $this->staff($workspace, 'member');
        $project = $this->project($this->company($workspace, 'synthetic-audit-client'), 'Synthetic Project');
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ, AgentApiScopes::PROJECTS_WRITE]);
        $body = ['user_id' => $member->public_id, 'role' => 'viewer', 'expected_version' => AgentApiVersion::for($project), 'confirm' => true];
        $this->withHeader('X-Request-Id', 'synthetic_access_request_0001');
        if ($mcp) {
            $session = $this->initialize();
            $arguments = $body + ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'idempotency_key' => 'synthetic-assignment'];
            $result = $this->callTool($session, 'projects.members.update', $arguments);
            $this->assertFalse($result['result']['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
            $this->withHeader('X-Request-Id', 'synthetic_access_request_0002');
            $retry = $this->callTool($session, 'projects.members.update', $arguments);
            $this->assertFalse($retry['result']['isError'] ?? false, json_encode($retry, JSON_THROW_ON_ERROR));
        } else {
            $url = "/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/members";
            $this->withHeader('Idempotency-Key', 'synthetic-assignment')->putJson($url, $body)->assertOk();
            $this->withHeader('X-Request-Id', 'synthetic_access_request_0002')->putJson($url, $body)->assertOk();
        }
        $audit = AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'project.assignment_changed')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($member->id, $audit->subject_user_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit->operation_id);
        $this->assertSame('synthetic_access_request_0001', $audit->request_id);
        $this->assertSame(['project' => $project->public_id, 'role' => 'none'], $audit->before_state);
        $this->assertSame(['project' => $project->public_id, 'role' => 'viewer'], $audit->after_state);
    }

    public function test_an_optional_assignment_context_cannot_misattribute_the_actor(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Context', 'slug' => 'synthetic-context']);
        $owner = $this->staff($workspace, 'owner');
        $member = $this->staff($workspace, 'member');
        $project = $this->project($this->company($workspace, 'synthetic-context-client'), 'Synthetic Project');
        try {
            app(WorkspaceProjectMutationAction::class)->updateAccess($owner, $workspace, $project, $member->public_id, 'viewer', context: AccessMutationContext::user($member));
            $this->fail('A mismatched audit actor was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('access_audit_events', 0);
        $this->assertDatabaseCount('client_project_memberships', 0);
    }

    public function test_portal_no_ops_preserve_the_project_set_and_do_not_record_an_audit(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic No-op', 'slug' => 'synthetic-no-op']);
        $owner = $this->staff($workspace, 'owner');
        $person = User::factory()->create(['email' => 'portal-no-op@synthetic.test']);
        $company = $this->company($workspace, 'synthetic-no-op-client');
        $first = $this->project($company, 'First synthetic project');
        $second = $this->project($company, 'Second synthetic project');
        $context = AccessMutationContext::user($owner);
        $access = app(WorkspaceAccess::class);
        $membership = $access->grantPortal($context, $company, $person, PortalAccessScope::Company);
        $access->changePortalScope($context, $company, $person, PortalAccessScope::Company);
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'portal.scope_changed')->count());
        $access->changePortalScope($context, $company, $person, PortalAccessScope::Projects, [$first->public_id, $second->public_id]);
        $before = DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->where('client_company_membership_id', $membership->id)->orderBy('id')->get()->all();
        $access->changePortalScope($context, $company, $person, PortalAccessScope::Projects, [$second->public_id, $first->public_id]);
        $after = DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->where('client_company_membership_id', $membership->id)->orderBy('id')->get()->all();
        $this->assertEquals($before, $after);
        $this->assertSame(1, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'portal.scope_changed')->count());
        $access->changePortalScope($context, $company, $person, PortalAccessScope::Projects, [$second->public_id]);
        $this->assertSame(2, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'portal.scope_changed')->count());
    }

    public function test_the_console_command_suppresses_company_and_reordered_project_no_ops(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Console', 'slug' => 'synthetic-console']);
        $company = $this->company($workspace, 'synthetic-console-client');
        $person = User::factory()->create(['email' => 'portal-console@synthetic.test']);
        $first = $this->project($company, 'First synthetic project');
        $second = $this->project($company, 'Second synthetic project');
        app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::system(), $company, $person, PortalAccessScope::Company);
        $args = fn (): array => ['company' => $company->public_id, 'email' => $person->email, '--confirm' => true,
            '--expected-version' => app(AgentPortalContactReadService::class)->contact($workspace, $company, $person->public_id)['version']];
        $this->artisan('svc:portal:project-access', $args() + ['--company-wide' => true])->assertSuccessful();
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'portal.scope_changed')->count());
        $this->artisan('svc:portal:project-access', $args() + ['--project' => [$first->public_id, $second->public_id]])->assertSuccessful();
        $this->artisan('svc:portal:project-access', $args() + ['--project' => [$second->public_id, $first->public_id]])->assertSuccessful();
        $this->assertSame(1, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'portal.scope_changed')->count());
    }
}
