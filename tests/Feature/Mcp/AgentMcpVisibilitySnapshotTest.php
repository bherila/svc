<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * What each fixture principal sees over MCP, pinned as a snapshot.
 *
 * Recorded before the in-app capability registry was replaced by the shared
 * operation registry (#408) and asserted unchanged after: the same tool names,
 * schemas, annotations and security schemes, the same resources and prompts,
 * and the same withheld-tool reasons in context, for every principal, scope
 * set and deployment state below. A connector must not notice the switch.
 *
 * Regenerate with SVC_UPDATE_MCP_VISIBILITY_SNAPSHOT=1, only for an intended
 * change recorded in the PR that makes it.
 */
final class AgentMcpVisibilitySnapshotTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const string SNAPSHOT = 'tests/Fixtures/Mcp/visibility-snapshot.json';

    private const array WRITE_FLAGS = [
        'writes_enabled', 'time_entry_writes_enabled', 'invoice_writes_enabled', 'expense_writes_enabled',
        'payment_writes_enabled', 'client_writes_enabled', 'project_writes_enabled', 'proposal_writes_enabled',
        'workspace_writes_enabled', 'file_writes_enabled',
    ];

    /** @var array<string, User> */
    private array $principals = [];

    public function test_every_fixture_principal_sees_the_recorded_mcp_surface(): void
    {
        $this->principals = $this->fixturePrincipals();
        $all = array_keys(AgentApiScopes::descriptions());
        $read = [AgentApiScopes::MCP_USE, ...array_values(array_filter($all, static fn (string $scope): bool => str_ends_with($scope, ':read')))];
        $observed = [];

        // Every principal with every scope, under every deployment state.
        foreach ($this->states() as $state => $configuration) {
            foreach (array_keys($this->principals) as $principal) {
                $observed[$state][$principal.'/all_scopes'] = $this->observe($configuration, $principal, $all, full: $state === 'all_enabled' && $principal === 'owner');
            }
        }
        // Narrower grants for the principals whose roles differ most.
        foreach (['owner', 'member_viewer', 'portal'] as $principal) {
            foreach (['read_scopes' => $read, 'connection_only' => [AgentApiScopes::MCP_USE], 'billing_only' => [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ, AgentApiScopes::BILLING_WRITE]] as $grant => $scopes) {
                $observed['all_enabled'][$principal.'/'.$grant] = $this->observe($this->states()['all_enabled'], $principal, $scopes, full: false);
            }
        }

        $path = base_path(self::SNAPSHOT);
        $encoded = json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (getenv('SVC_UPDATE_MCP_VISIBILITY_SNAPSHOT') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $encoded);
        }

        $this->assertFileExists($path);
        $this->assertSame(
            json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            json_decode($encoded, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function states(): array
    {
        $on = ['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => []];
        foreach (self::WRITE_FLAGS as $flag) {
            $on['agent_api.'.$flag] = true;
        }
        $writesOff = $on;
        foreach (self::WRITE_FLAGS as $flag) {
            $writesOff['agent_api.'.$flag] = false;
        }
        $installDefaults = $on;
        foreach (self::WRITE_FLAGS as $flag) {
            $installDefaults['agent_api.'.$flag] = $flag === 'time_entry_writes_enabled';
        }

        return [
            'all_enabled' => $on,
            'install_defaults' => $installDefaults,
            'writes_off' => $writesOff,
            'outer_writes_off' => ['agent_api.writes_enabled' => false] + $on,
            'invoice_writes_only_off' => ['agent_api.invoice_writes_enabled' => false] + $on,
            'mcp_write_group_off' => ['agent_api.mcp_feature_flags' => ['mcp.write' => false]] + $on,
            'mcp_agreements_group_off' => ['agent_api.mcp_feature_flags' => ['mcp.read.agreements' => false]] + $on,
            'single_tools_off' => ['agent_api.mcp_feature_flags' => ['invoices.get' => false, 'time_entries.list' => false, 'context.get' => false]] + $on,
            'mcp_disabled' => ['agent_api.mcp_enabled' => false] + $on,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function observe(array $configuration, string $principal, array $scopes, bool $full): array
    {
        config($configuration);
        // Each observation is a fresh connection: no rate-limit budget carries over.
        $this->app['cache']->flush();
        $this->app['auth']->forgetGuards();
        $this->actingAsMcp($this->principals[$principal], $scopes);

        $context = $this->getJson('/api/v1/context');
        $observed = ['context' => $context->getStatusCode() === 200
            ? ['withheld_tools' => array_map(
                static fn (array $tool): string => implode(' ', array_map('strval', $tool)),
                (array) $context->json('data.withheld_tools'),
            )]
            : ['status' => $context->getStatusCode()]];

        $initialize = $this->mcp($this->initializeMessage());
        $observed['initialize_status'] = $initialize->getStatusCode();
        if ($initialize->getStatusCode() !== 200) {
            return $observed;
        }
        $session = (string) $initialize->headers->get('Mcp-Session-Id');
        $observed['capabilities'] = array_keys((array) $initialize->json('result.capabilities'));
        sort($observed['capabilities']);
        $observed['server_info'] = $initialize->json('result.serverInfo');
        $observed['instructions'] = $initialize->json('result.instructions');

        $tools = (array) $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->json('result.tools');
        usort($tools, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $observed['tools'] = $full ? $tools : $this->digests($tools, 'name');
        foreach (['resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates', 'prompts/list' => 'prompts'] as $method => $key) {
            $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => $method, 'params' => []], $session);
            $items = $response->json('result.'.$key);
            if (! is_array($items)) {
                $observed[$key] = ['status' => $response->getStatusCode(), 'error' => $response->json('error.code')];

                continue;
            }
            usort($items, static fn (array $a, array $b): int => ($a['name'] ?? $a['uriTemplate'] ?? '') <=> ($b['name'] ?? $b['uriTemplate'] ?? ''));
            $observed[$key] = $full ? $items : $this->digests($items, $key === 'resourceTemplates' ? 'uriTemplate' : 'name');
        }

        return $observed;
    }

    /**
     * Each item's name with a digest of its whole definition, so a changed
     * description, schema or annotation shows without storing every copy.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function digests(array $items, string $key): array
    {
        $digests = [];
        foreach ($items as $item) {
            $digests[(string) ($item[$key] ?? '')] = substr(hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 16);
        }

        return $digests;
    }

    /** @return array<string, User> */
    private function fixturePrincipals(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic visibility workspace', 'slug' => 'synthetic-visibility-'.Str::lower(Str::random(8))]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic visibility company', 'slug' => 'synthetic-'.Str::lower(Str::random(8))]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic visibility project']);
        $user = static fn (string $label): User => User::factory()->create(['email' => $label.'-'.Str::lower(Str::random(6)).'@synthetic.test']);

        $owner = $user('owner');
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $admin = $user('admin');
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $manager = $user('manager');
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $viewer = $user('viewer');
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $portal = $user('portal');
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client']);
        $outsider = $user('outsider');

        return [
            'owner' => $owner,
            'admin' => $admin,
            'member_project_manager' => $manager,
            'member_viewer' => $viewer,
            'portal' => $portal,
            'outsider' => $outsider,
        ];
    }
}
