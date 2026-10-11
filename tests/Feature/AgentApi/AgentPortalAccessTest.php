<?php

namespace Tests\Feature\AgentApi;

use App\Models\AccessAuditEvent;
use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use App\Support\Access\PortalAccessVersion;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentPortalAccessTest extends TestCase
{
    use BuildsAccessFixtures;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'clients:read', 'clients:write'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true,
            'bherila-auth.oauth_client.provider' => 'example-provider']);
    }

    public static function transports(): array
    {
        return ['REST' => ['rest'], 'MCP' => ['mcp']];
    }

    public static function writes(): array
    {
        return ['grant' => ['grant'], 'update' => ['update'], 'revoke' => ['revoke']];
    }

    public static function writeCases(): array
    {
        $cases = [];
        foreach (['rest', 'mcp'] as $transport) {
            foreach (['grant', 'update', 'revoke'] as $kind) {
                $cases[$transport.' '.$kind] = [$transport, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('transports')]
    public function test_managers_can_grant_list_change_and_revoke_a_portal_contact(string $transport): void
    {
        [$workspace, $owner, $company, $person, $project] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $grant = $this->success($this->portalOperation($transport, 'grant', $workspace, $company, ['email' => strtoupper($person->email), 'scope' => 'projects', 'projects' => [$project->public_id], 'confirm' => true], key: 'synthetic-grant'), 'grant');
        $this->assertSame($person->public_id, $grant['data']['id']);
        $this->assertSame('projects', $grant['data']['scope']);
        $this->assertSame([$project->public_id], array_column($grant['data']['projects'], 'id'));
        $this->assertSame(route('agent-api.v1.clients.portal-access.update', ['workspace' => $workspace, 'client' => $company->public_id, 'user' => $person->public_id]), $grant['data']['update_href']);
        $listing = $this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list');
        $this->assertSame([$grant['data']], $listing['data']);
        $changed = $this->success($this->portalOperation($transport, 'update', $workspace, $company, ['scope' => 'company', 'confirm' => true, 'expected_version' => $grant['data']['version']], $person, 'synthetic-scope'), 'update');
        $this->assertSame('company', $changed['data']['scope']);
        $this->assertSame([], $changed['data']['projects']);
        $this->assertSame(0, DB::table('client_portal_project_access')->where('workspace_id', $workspace->id)->count());
        $revoked = $this->success($this->portalOperation($transport, 'revoke', $workspace, $company, ['confirm' => true, 'expected_version' => $changed['data']['version']], $person, 'synthetic-revoke'), 'revoke');
        $this->assertSame(['client_id' => $company->public_id, 'user_id' => $person->public_id, 'revoked' => true], $revoked['data']);
        $this->assertSame([], $this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data']);
        $this->assertSame(3, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
    }

    #[DataProvider('writes')]
    public function test_rest_and_mcp_share_receipts_and_replays_do_not_repeat_access_writes(string $kind): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        if ($kind !== 'grant') {
            $this->seedPortal($owner, $company, $person);
        }
        $this->actingAsMcp($owner, self::SCOPES);
        $body = $this->body($kind, $person, $company);
        $first = $this->success($this->portalOperation('rest', $kind, $workspace, $company, $body, $person), $kind);
        $again = $this->success($this->portalOperation('mcp', $kind, $workspace, $company, $body, $person), $kind);
        $this->assertSame($first, $again);
        $this->assertSame(1, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
        $this->assertSame(1, AgentMutationReceipt::query()->where('workspace_id', $workspace->id)->where('operation', 'clients.portal_access.'.$kind)->count());
        $this->refusal($this->portalOperation('rest', $kind, $workspace, $company, [...$body, 'confirm' => false], $person), 409);
    }

    #[DataProvider('transports')]
    public function test_clients_contacts_projects_and_cursors_are_isolated_between_workspaces_and_clients(string $transport): void
    {
        [$workspace, $owner, $company, $person, $project] = $this->fixture();
        [$otherWorkspace, $otherOwner, $otherCompany, $otherPerson, $otherProject] = $this->fixture();
        $this->seedPortal($owner, $company, $person);
        $this->seedPortal($otherOwner, $otherCompany, $otherPerson);
        $this->actingAsMcp($owner, self::SCOPES);
        $this->assertSame([$person->public_id], array_column($this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data'], 'id'));
        $this->refusal($this->portalOperation($transport, 'list', $workspace, $otherCompany), 404);
        $this->refusal($this->portalOperation($transport, 'list', $otherWorkspace, $otherCompany), 404);
        foreach (['grant', 'update', 'revoke'] as $kind) {
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $otherCompany, $this->body($kind, $otherPerson, $otherCompany), $otherPerson, 'foreign-'.$kind), 404);
        }
        foreach (['update', 'revoke'] as $kind) {
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $this->body($kind, $otherPerson, $otherCompany), $otherPerson, 'foreign-contact-'.$kind), 404);
        }
        $sameWorkspaceCompany = $this->company($workspace, 'second-synthetic-client');
        $sameWorkspaceProject = $this->project($sameWorkspaceCompany, 'Other client project');
        foreach ([$otherProject, $sameWorkspaceProject] as $foreignProject) {
            $this->refusal($this->portalOperation($transport, 'update', $workspace, $company, [...$this->body('update', $person, $company), 'scope' => 'projects', 'projects' => [$foreignProject->public_id]], $person, 'foreign-project-'.$foreignProject->public_id), 422);
        }
        $second = User::factory()->create();
        $this->seedPortal($owner, $company, $second);
        $firstPage = $this->success($this->portalOperation($transport, 'list', $workspace, $company, ['limit' => 1]), 'list');
        $this->assertNotNull($firstPage['meta']['next_cursor']);
        $nextPage = $this->success($this->portalOperation($transport, 'list', $workspace, $company, ['limit' => 1, 'cursor' => $firstPage['meta']['next_cursor']]), 'list');
        $this->assertSame([$second->public_id], array_column($nextPage['data'], 'id'));
        $this->refusal($this->portalOperation($transport, 'list', $workspace, $sameWorkspaceCompany, ['cursor' => $firstPage['meta']['next_cursor']]), 422);
        $this->assertSame('company', ClientCompanyMembership::query()->where('workspace_id', $otherWorkspace->id)->where('client_company_id', $otherCompany->id)->sole()->access_scope);
        $this->assertSame('company', ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $person->id)->sole()->access_scope);
    }

    #[DataProvider('writeCases')]
    public function test_writes_require_scope_key_confirmation_and_cutovers_and_refuse_members_and_strangers(string $transport, string $kind): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        if ($kind !== 'grant') {
            $this->seedPortal($owner, $company, $person);
        }
        $body = $this->body($kind, $person, $company);
        $this->actingAsMcp($owner, ['mcp:use', 'clients:read']);
        $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), 403);
        foreach ([$this->staff($workspace, 'member'), User::factory()->create()] as $index => $unauthorized) {
            $this->actingAsMcp($unauthorized, self::SCOPES);
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), $index === 0 ? 403 : 404);
        }
        $this->actingAsMcp($owner, self::SCOPES);
        $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person, key: null), 422);
        $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, [...$body, 'confirm' => false], $person), 422);
        foreach (['agent_api.client_writes_enabled', 'agent_api.writes_enabled'] as $flag) {
            config([$flag => false]);
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), 404);
            config([$flag => true]);
        }
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
    }

    #[DataProvider('transports')]
    public function test_only_managers_with_read_scope_can_list_contacts(string $transport): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        $this->seedPortal($owner, $company, $person);
        $this->actingAsMcp($owner, ['mcp:use', 'clients:write']);
        $this->refusal($this->portalOperation($transport, 'list', $workspace, $company), 403);
        $this->actingAsMcp($this->staff($workspace, 'member'), self::SCOPES);
        $this->refusal($this->portalOperation($transport, 'list', $workspace, $company), 403);
        $this->actingAsMcp(User::factory()->create(), self::SCOPES);
        $this->refusal($this->portalOperation($transport, 'list', $workspace, $company), 404);
    }

    #[DataProvider('transports')]
    public function test_grants_require_an_exact_address_and_a_subject_bound_to_the_configured_provider(string $transport): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = $this->body('grant', $person, $company);
        $this->refusal($this->portalOperation($transport, 'grant', $workspace, $company, [...$body, 'email' => 'missing@example.test']), 422);
        foreach ([['oauth_provider' => 'different-provider'], ['oauth_subject' => null], ['oauth_subject' => '']] as $unbound) {
            $person->forceFill(['oauth_provider' => 'example-provider', 'oauth_subject' => 'contact-subject', ...$unbound])->save();
            $this->refusal($this->portalOperation($transport, 'grant', $workspace, $company, $body), 422);
        }
        $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->count());
    }

    #[DataProvider('writeCases')]
    public function test_both_new_writes_and_receipt_replays_recheck_authority_under_the_access_lock(string $transport, string $kind): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        $admin = $this->staff($workspace, 'admin');
        if ($kind !== 'grant') {
            $this->seedPortal($owner, $company, $person);
        }
        $this->actingAsMcp($admin, self::SCOPES);
        $body = $this->body($kind, $person, $company);
        $this->onceTheWorkspaceIsLocked(fn () => WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $admin->id)->update(['role' => 'member']));
        $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), 403);
        $this->assertSame('forbidden', AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'clients.portal_access.'.$kind)->sole()->error_category);
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
        $this->success($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), $kind);
        $this->onceTheWorkspaceIsLocked(fn () => WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $admin->id)->update(['role' => 'member']));
        $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person), 403);
        $this->assertSame(1, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
    }

    public function test_mcp_client_write_switches_withhold_tools_without_affecting_rest(): void
    {
        [$workspace, $owner, $company, $person] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        config(['agent_api.mcp_feature_flags' => ['mcp.write.clients' => false]]);
        $names = $this->toolNames($this->initialize());
        $this->assertContains('clients.portal_access.list', $names);
        foreach (['grant', 'update', 'revoke'] as $kind) {
            $this->assertNotContains('clients.portal_access.'.$kind, $names);
        }
        $this->success($this->portalOperation('rest', 'grant', $workspace, $company, $this->body('grant', $person, $company)), 'grant');
    }

    #[DataProvider('transports')]
    public function test_scope_changes_and_revocations_require_a_fresh_version_and_its_read_scope(string $transport): void
    {
        [$workspace, $owner, $company, $person, $project] = $this->fixture();
        $this->seedPortal($owner, $company, $person);
        $old = $this->body('update', $person, $company)['expected_version'];
        $this->actingAsMcp($owner, ['mcp:use', 'clients:write']);
        foreach (['update', 'revoke'] as $kind) {
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, ['confirm' => true, 'expected_version' => $old, ...($kind === 'update' ? ['scope' => 'company'] : [])], $person), 403);
        }
        $this->actingAsMcp($owner, self::SCOPES);
        app(WorkspaceAccess::class)->changePortalScope(AccessMutationContext::user($owner), $company, $person, PortalAccessScope::Projects, [$project->public_id]);
        $current = $this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data'][0];
        $this->assertNotSame($old, $current['version']);
        foreach (['update', 'revoke'] as $kind) {
            $body = ['confirm' => true, ...($kind === 'update' ? ['scope' => 'company'] : [])];
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, $body, $person, 'missing-'.$kind), 422);
            $this->refusal($this->portalOperation($transport, $kind, $workspace, $company, [...$body, 'expected_version' => $old], $person, 'stale-'.$kind), 409);
        }
        $this->assertSame([$project->public_id], array_column($this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data'][0]['projects'], 'id'));
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
    }

    #[DataProvider('transports')]
    public function test_versions_are_checked_after_the_access_lock_and_cover_project_selection(string $transport): void
    {
        [$workspace, $owner, $company, $person, $project] = $this->fixture();
        $this->seedPortal($owner, $company, $person);
        $this->actingAsMcp($owner, self::SCOPES);
        $body = $this->body('update', $person, $company);
        $this->onceTheWorkspaceIsLocked(fn () => ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $person->id)->update(['role' => 'changed-role']));
        $this->refusal($this->portalOperation($transport, 'update', $workspace, $company, $body, $person), 409);
        $this->assertSame('conflict', AgentMutationAudit::query()->where('workspace_id', $workspace->id)->where('operation', 'clients.portal_access.update')->sole()->error_category);
        app(WorkspaceAccess::class)->changePortalScope(AccessMutationContext::user($owner), $company, $person, PortalAccessScope::Projects, [$project->public_id]);
        $old = $this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data'][0]['version'];
        $other = $this->project($company, 'Another synthetic project');
        app(WorkspaceAccess::class)->changePortalScope(AccessMutationContext::user($owner), $company, $person, PortalAccessScope::Projects, [$other->public_id]);
        $this->refusal($this->portalOperation($transport, 'revoke', $workspace, $company, ['confirm' => true, 'expected_version' => $old], $person, 'project-selection'), 409);
        $this->assertSame([$other->public_id], array_column($this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data'][0]['projects'], 'id'));
    }

    #[DataProvider('transports')]
    public function test_replays_keep_the_original_contact_response_after_scope_changes_and_revocation(string $transport): void
    {
        [$workspace, $owner, $company, $person, $project] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $grantBody = $this->body('grant', $person, $company);
        $granted = $this->success($this->portalOperation($transport, 'grant', $workspace, $company, $grantBody, key: 'grant-snapshot'), 'grant');
        $updateBody = ['confirm' => true, 'scope' => 'projects', 'projects' => [$project->public_id], 'expected_version' => $granted['data']['version']];
        $updated = $this->success($this->portalOperation($transport, 'update', $workspace, $company, $updateBody, $person, 'update-snapshot'), 'update');
        $this->assertSame($granted, $this->success($this->portalOperation($transport, 'grant', $workspace, $company, $grantBody, key: 'grant-snapshot'), 'grant'));
        app(WorkspaceAccess::class)->revokePortal(AccessMutationContext::user($owner), $company, $person);
        $this->assertSame($granted, $this->success($this->portalOperation($transport, 'grant', $workspace, $company, $grantBody, key: 'grant-snapshot'), 'grant'));
        $this->assertSame($updated, $this->success($this->portalOperation($transport, 'update', $workspace, $company, $updateBody, $person, 'update-snapshot'), 'update'));
        $this->assertSame([], $this->success($this->portalOperation($transport, 'list', $workspace, $company), 'list')['data']);
        $this->assertSame(2, AccessAuditEvent::query()->where('workspace_id', $workspace->id)->whereNotNull('operation_id')->count());
    }

    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic portal workspace', 'slug' => 'synthetic-portal-'.uniqid()]);
        $owner = $this->staff($workspace, 'owner');
        $company = $this->company($workspace, 'synthetic-client');
        $person = User::factory()->create(['email' => 'contact-'.uniqid().'@example.test', 'oauth_provider' => 'example-provider', 'oauth_subject' => 'contact-'.uniqid()]);
        $project = $this->project($company, 'Synthetic portal project');

        return [$workspace, $owner, $company, $person, $project];
    }

    private function seedPortal(User $owner, ClientCompany $company, User $person): void
    {
        app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::user($owner), $company, $person, PortalAccessScope::Company);
    }

    private function body(string $kind, User $person, ClientCompany $company): array
    {
        $version = null;
        if ($kind !== 'grant') {
            $member = ClientCompanyMembership::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)->where('user_id', $person->id)->sole();
            $version = PortalAccessVersion::for($member->public_id, $member->role, new PortalAccessState(PortalAccessScope::Company, []), (string) config('app.key'));
        }

        return match ($kind) {
            'grant' => ['email' => $person->email, 'scope' => 'company', 'confirm' => true],
            'update' => ['scope' => 'company', 'confirm' => true, 'expected_version' => $version],
            'revoke' => ['confirm' => true, 'expected_version' => $version],
        };
    }

    private function portalOperation(string $transport, string $kind, Workspace $workspace, ClientCompany $company, array $body = [], ?User $person = null, ?string $key = 'synthetic-key'): TestResponse|array
    {
        if ($transport === 'mcp') {
            $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, ...$body];
            if (in_array($kind, ['update', 'revoke'], true)) {
                $arguments['user_id'] = $person->public_id;
            }
            if ($kind !== 'list' && $key !== null) {
                $arguments['idempotency_key'] = $key;
            }

            return $this->callTool($this->initialize(), 'clients.portal_access.'.$kind, $arguments);
        }
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/portal-access';
        if (in_array($kind, ['update', 'revoke'], true)) {
            $path .= '/'.$person->public_id;
        }
        if ($kind === 'list') {
            return $this->getJson($path.'?'.http_build_query($body));
        }

        return $this->json(match ($kind) {
            'grant' => 'POST', 'update' => 'PATCH', 'revoke' => 'DELETE'
        }, $path, $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function success(TestResponse|array $response, string $kind): array
    {
        if ($response instanceof TestResponse) {
            $body = $response->assertStatus($kind === 'grant' ? 201 : 200)->json();
        } else {
            $this->assertFalse($response['result']['isError'] ?? true, json_encode($response));
            $body = $response['result']['structuredContent'];
        }
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation('clients.portal_access.'.$kind)));

        return $body;
    }

    private function refusal(TestResponse|array $response, int $status): void
    {
        if ($response instanceof TestResponse) {
            $response->assertStatus($status);
        } else {
            $this->assertTrue(isset($response['error']) || ($response['result']['isError'] ?? false), json_encode($response));
        }
    }
}
