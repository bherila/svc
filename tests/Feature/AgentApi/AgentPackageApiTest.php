<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\CallsMcp;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The service package operations over REST and MCP (docs/service-packages.md,
 * "MCP and REST surface"). Every rule about a package is the workflow's; these
 * prove the agent surface reaches it the same way from both transports.
 */
final class AgentPackageApiTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'packages:read', 'packages:write'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.package_writes_enabled' => true]);
        $this->disconnectStripe();
    }

    #[Requirement('API-1')]
    public function test_create_update_publish_and_retire_over_rest_follow_the_workflow(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->actingAsMcp($owner, self::SCOPES);

        $created = $this->postJson($this->url($workspace), $this->hostingPackageInput(), ['Idempotency-Key' => 'synthetic-create'])->assertCreated();
        $this->schema('packages.create', $created->json());
        $created->assertJsonPath('data.status', 'draft')->assertJsonPath('data.public_url', null)->assertJsonPath('data.published', null)
            ->assertJsonPath('data.draft.number', 1)->assertJsonPath('data.draft.terms.intake_questions.0.key', 'website_address')
            ->assertJsonPath('data.draft.setup_fee_total', 15000)->assertJsonPath('data.publish_refusals', []);
        $id = (string) $created->json('data.id');

        $updated = $this->patchJson($this->url($workspace, $id), ['expected_version' => $created->json('data.version'), 'name' => 'Web hosting · Plus', 'is_listed' => true], ['Idempotency-Key' => 'synthetic-update'])->assertOk();
        $this->schema('packages.update', $updated->json());
        $updated->assertJsonPath('data.draft.terms.name', 'Web hosting · Plus')->assertJsonPath('data.is_listed', true)
            ->assertJsonPath('data.draft.terms.billing_cadence', 'monthly');
        $this->assertNotSame($created->json('data.version'), $updated->json('data.version'));

        $published = $this->postJson($this->url($workspace, $id).'/publish', ['expected_version' => $updated->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-publish'])->assertOk();
        $this->schema('packages.publish', $published->json());
        $published->assertJsonPath('data.status', 'published')->assertJsonPath('data.draft', null)->assertJsonPath('data.published.number', 1);
        $this->assertIsString($published->json('data.public_url'));
        $this->assertIsString($published->json('data.published.published_at'));

        $retired = $this->postJson($this->url($workspace, $id).'/retire', ['expected_version' => $published->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-retire'])->assertOk();
        $this->schema('packages.retire', $retired->json());
        $retired->assertJsonPath('data.status', 'retired')->assertJsonPath('data.published.terms.name', 'Web hosting · Plus');

        $list = $this->getJson($this->url($workspace).'?status=retired')->assertOk();
        $this->schema('packages.list', $list->json());
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.next_cursor', null);
        $this->getJson($this->url($workspace).'?status=draft')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($workspace, $id))->assertOk()->assertJsonPath('data', $retired->json('data'));
        foreach (['packages.create', 'packages.update', 'packages.publish', 'packages.retire'] as $operation) {
            $this->assertDatabaseHas('agent_mutation_audits', ['workspace_id' => $workspace->id, 'operation' => $operation, 'outcome' => 'success']);
        }
    }

    /**
     * One declaration, two transports (API-1): each tool's input is its REST
     * request body plus its path and idempotency key, its output is the REST
     * response, and the same call over either gives the same answer and the
     * same single receipt.
     */
    #[Requirement('API-1')]
    public function test_mcp_and_rest_take_the_same_requests_and_give_the_same_responses(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->actingAsMcp($owner, self::SCOPES);
        $session = $this->initialize();
        $tools = collect($this->listTools($session))->keyBy('name');
        $paths = ['packages.list' => ['workspace_id'], 'packages.get' => ['workspace_id', 'package_id'], 'packages.create' => ['workspace_id', 'idempotency_key'],
            'packages.update' => ['workspace_id', 'package_id', 'idempotency_key'], 'packages.publish' => ['workspace_id', 'package_id', 'idempotency_key'], 'packages.retire' => ['workspace_id', 'package_id', 'idempotency_key']];
        foreach ($paths as $name => $own) {
            $this->assertTrue($tools->has($name), $name);
            $input = $tools[$name]['inputSchema'];
            $body = in_array($name, ['packages.list', 'packages.get'], true) ? [] : AgentApiResponseSchemaCatalog::requestForOperation($name);
            $expected = [...$own, ...array_keys($body['properties'] ?? []), ...($name === 'packages.list' ? ['status', 'limit', 'cursor'] : [])];
            sort($expected);
            $actual = array_keys($input['properties']);
            sort($actual);
            $this->assertSame($expected, $actual, $name);
            foreach ($body['properties'] ?? [] as $field => $schema) {
                $this->assertSame($schema, $input['properties'][$field], "{$name}.{$field}");
            }
            $this->assertSame(AgentApiResponseSchemaCatalog::forOperation($name), $tools[$name]['outputSchema'], $name);
        }

        $body = $this->hostingPackageInput(['link_name' => 'parity-hosting']);
        $rest = $this->postJson($this->url($workspace), $body, ['Idempotency-Key' => 'synthetic-parity-create'])->assertCreated()->json();
        $replay = $this->callTool($session, 'packages.create', ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-parity-create', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($rest['data'], $replay['result']['structuredContent']['data']);
        $this->assertDatabaseCount('service_packages', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'packages.create', 'outcome' => 'replay']);

        $viaMcp = $this->callTool($session, 'packages.create', ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'synthetic-parity-mcp', ...$this->hostingPackageInput(['link_name' => 'mcp-hosting'])]);
        $this->assertFalse($viaMcp['result']['isError'] ?? true, json_encode($viaMcp));
        $id = $viaMcp['result']['structuredContent']['data']['id'];
        $read = $this->getJson($this->url($workspace, $id))->assertOk()->json();
        $this->assertSame($read['data'], $viaMcp['result']['structuredContent']['data']);
        $got = $this->callTool($session, 'packages.get', ['workspace_id' => $workspace->public_id, 'package_id' => $id]);
        $this->assertSame($read, $got['result']['structuredContent']);
        // Cursors are sealed afresh on every page, so only the rows compare equal.
        $listed = $this->callTool($session, 'packages.list', ['workspace_id' => $workspace->public_id, 'status' => 'draft', 'limit' => 1]);
        $page = $this->getJson($this->url($workspace).'?status=draft&limit=1')->assertOk()->json();
        $this->assertSame($page['data'], $listed['result']['structuredContent']['data']);
        $this->assertIsString($page['meta']['next_cursor']);
        $this->assertIsString($listed['result']['structuredContent']['meta']['next_cursor']);

        $updated = $this->callTool($session, 'packages.update', ['workspace_id' => $workspace->public_id, 'package_id' => $id, 'idempotency_key' => 'synthetic-parity-update', 'expected_version' => $read['data']['version'], 'summary' => 'Changed over MCP']);
        $this->assertSame('Changed over MCP', $updated['result']['structuredContent']['data']['draft']['terms']['summary']);
        $published = $this->callTool($session, 'packages.publish', ['workspace_id' => $workspace->public_id, 'package_id' => $id, 'idempotency_key' => 'synthetic-parity-publish', 'expected_version' => $updated['result']['structuredContent']['data']['version'], 'confirm' => true]);
        $this->assertSame('published', $published['result']['structuredContent']['data']['status']);
        $retired = $this->callTool($session, 'packages.retire', ['workspace_id' => $workspace->public_id, 'package_id' => $id, 'idempotency_key' => 'synthetic-parity-retire', 'expected_version' => $published['result']['structuredContent']['data']['version'], 'confirm' => true]);
        $this->assertSame($this->getJson($this->url($workspace, $id))->json('data'), $retired['result']['structuredContent']['data']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function unconfirmed(): iterable
    {
        foreach (['publish', 'retire'] as $action) {
            yield "{$action} without confirm" => [$action, []];
            yield "{$action} with confirm false" => [$action, ['confirm' => false]];
            yield "{$action} with a truthy string" => [$action, ['confirm' => 'true']];
            yield "{$action} with a truthy number" => [$action, ['confirm' => 1]];
        }
    }

    /** @param array<string, mixed> $confirmation */
    #[DataProvider('unconfirmed')]
    #[Requirement('API-2')]
    public function test_publish_and_retire_refuse_without_confirm_true_over_both_transports(string $action, array $confirmation): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $action === 'publish' ? $this->createPackage($workspace, $owner) : $this->publishedPackage($workspace, $owner);
        $status = $package->status;
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($package), ...$confirmation];

        $this->postJson($this->url($workspace, $package->public_id).'/'.$action, $body, ['Idempotency-Key' => 'synthetic-unconfirmed-rest'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $result = $this->callTool($this->initialize(), 'packages.'.$action, ['workspace_id' => $workspace->public_id, 'package_id' => $package->public_id, 'idempotency_key' => 'synthetic-unconfirmed-mcp', ...$body]);
        $this->assertTrue(($result['result']['isError'] ?? false) || isset($result['error']), json_encode($result));

        $this->assertSame($status, $package->refresh()->status);
        $this->assertSame(AgentApiVersion::for($package), $body['expected_version']);
    }

    public function test_a_stale_version_is_refused_with_409_and_changes_nothing(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $stale = AgentApiVersion::for($package);
        $this->actingAsMcp($owner, self::SCOPES);
        $fresh = $this->patchJson($this->url($workspace, $package->public_id), ['expected_version' => $stale, 'summary' => 'First change'], ['Idempotency-Key' => 'synthetic-first'])->assertOk()->json('data');

        $this->patchJson($this->url($workspace, $package->public_id), ['expected_version' => $stale, 'summary' => 'Lost change'], ['Idempotency-Key' => 'synthetic-stale-update'])->assertConflict();
        $this->postJson($this->url($workspace, $package->public_id).'/publish', ['expected_version' => $stale, 'confirm' => true], ['Idempotency-Key' => 'synthetic-stale-publish'])->assertConflict();
        $result = $this->callTool($this->initialize(), 'packages.publish', ['workspace_id' => $workspace->public_id, 'package_id' => $package->public_id, 'idempotency_key' => 'synthetic-stale-mcp', 'expected_version' => $stale, 'confirm' => true]);
        $this->assertTrue($result['result']['isError'] ?? false, json_encode($result));

        $this->getJson($this->url($workspace, $package->public_id))->assertOk()
            ->assertJsonPath('data.version', $fresh['version'])->assertJsonPath('data.status', 'draft')->assertJsonPath('data.draft.terms.summary', 'First change');
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'packages.update', 'outcome' => 'failed', 'error_category' => 'conflict']);
    }

    public function test_an_idempotency_key_replays_its_request_and_refuses_a_different_one(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->actingAsMcp($owner, self::SCOPES);
        $first = $this->postJson($this->url($workspace), $this->hostingPackageInput(), ['Idempotency-Key' => 'synthetic-once'])->assertCreated()->json('data');
        $this->postJson($this->url($workspace), $this->hostingPackageInput(), ['Idempotency-Key' => 'synthetic-once'])->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->postJson($this->url($workspace), $this->hostingPackageInput(['name' => 'Another synthetic package']), ['Idempotency-Key' => 'synthetic-once'])->assertConflict();
        $this->postJson($this->url($workspace), $this->hostingPackageInput())->assertUnprocessable();
        $this->assertDatabaseCount('service_packages', 1);
    }

    /** The publish rules refuse with their stable codes, on both transports. */
    public function test_a_publish_refusal_names_its_code(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner, ['require_autopay' => true, 'autopay_limit_amount' => 20000]);
        $this->actingAsMcp($owner, self::SCOPES);
        $read = $this->getJson($this->url($workspace, $package->public_id))->assertOk();
        $read->assertJsonPath('data.publish_refusals.0.code', 'package_payment_needs_stripe');

        $this->postJson($this->url($workspace, $package->public_id).'/publish', ['expected_version' => $read->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-refused'])
            ->assertUnprocessable()->assertJsonPath('code', 'package_payment_needs_stripe');
        $result = $this->callTool($this->initialize(), 'packages.publish', ['workspace_id' => $workspace->public_id, 'package_id' => $package->public_id, 'idempotency_key' => 'synthetic-refused-mcp', 'expected_version' => $read->json('data.version'), 'confirm' => true]);
        $this->assertTrue($result['result']['isError'] ?? false, json_encode($result));
        $this->assertStringContainsString('package_payment_needs_stripe', json_encode($result));
        $this->assertSame('draft', $package->refresh()->status);

        $this->connectStripe();
        $this->postJson($this->url($workspace, $package->public_id).'/publish', ['expected_version' => $read->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-connected'])
            ->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_an_unknown_field_or_an_invalid_term_is_refused_before_anything_is_written(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->actingAsMcp($owner, self::SCOPES);
        $this->postJson($this->url($workspace), [...$this->hostingPackageInput(), 'status' => 'published'], ['Idempotency-Key' => 'synthetic-unknown'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($this->url($workspace), $this->hostingPackageInput(['billing_cadence' => 'fortnightly']), ['Idempotency-Key' => 'synthetic-invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_cadence');
        $this->assertDatabaseCount('service_packages', 0);
    }

    /** @return iterable<string, array{string}> */
    public static function nonManagers(): iterable
    {
        yield 'workspace member' => ['member'];
        yield 'workspace viewer' => ['viewer'];
        yield 'portal contact' => ['portal'];
    }

    /** Anyone who may not manage the workspace is answered as if there were no packages. */
    #[DataProvider('nonManagers')]
    public function test_a_non_manager_finds_no_package_to_read_or_change(string $who): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $this->actingAsMcp($this->nonManager($workspace, $who), self::SCOPES);
        $version = AgentApiVersion::for($package);

        $this->getJson($this->url($workspace))->assertNotFound();
        $this->getJson($this->url($workspace, $package->public_id))->assertNotFound();
        $this->postJson($this->url($workspace), $this->hostingPackageInput(['link_name' => 'not-mine']), ['Idempotency-Key' => 'synthetic-nm-create'])->assertNotFound();
        $this->patchJson($this->url($workspace, $package->public_id), ['expected_version' => $version, 'summary' => 'Not mine'], ['Idempotency-Key' => 'synthetic-nm-update'])->assertNotFound();
        $this->postJson($this->url($workspace, $package->public_id).'/publish', ['expected_version' => $version, 'confirm' => true], ['Idempotency-Key' => 'synthetic-nm-publish'])->assertNotFound();
        $this->postJson($this->url($workspace, $package->public_id).'/retire', ['expected_version' => $version, 'confirm' => true], ['Idempotency-Key' => 'synthetic-nm-retire'])->assertNotFound();

        // Over MCP the manager-only tools are not offered at all.
        $this->assertSame([], array_values(array_filter($this->toolNames($this->initialize()), static fn (string $name): bool => str_starts_with($name, 'packages.'))));
        $this->assertDatabaseCount('service_packages', 1);
        $this->assertSame($version, AgentApiVersion::for($package->refresh()));
    }

    /** Another workspace's package is not found through this one, whatever the caller manages. */
    public function test_another_workspaces_package_is_not_found(): void
    {
        [$workspace, $owner] = $this->packageWorkspace('Synthetic Studio One');
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $other->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $foreign = $this->createPackage($other, $otherOwner);
        $this->createPackage($workspace, $owner, ['link_name' => 'own-hosting']);
        $this->actingAsMcp($owner, self::SCOPES);
        $version = AgentApiVersion::for($foreign);

        $this->getJson($this->url($workspace, $foreign->public_id))->assertNotFound();
        $this->patchJson($this->url($workspace, $foreign->public_id), ['expected_version' => $version, 'summary' => 'Crossed'], ['Idempotency-Key' => 'synthetic-cross-update'])->assertNotFound();
        $this->postJson($this->url($workspace, $foreign->public_id).'/publish', ['expected_version' => $version, 'confirm' => true], ['Idempotency-Key' => 'synthetic-cross-publish'])->assertNotFound();
        $listed = $this->getJson($this->url($workspace))->assertOk()->json('data');
        $this->assertSame(['own-hosting'], array_column($listed, 'link_name'));
        // A link name is the workspace's own: the other workspace's does not collide.
        $this->postJson($this->url($workspace), $this->hostingPackageInput(), ['Idempotency-Key' => 'synthetic-same-link'])->assertCreated();
        $this->assertSame($version, AgentApiVersion::for($foreign->refresh()));
        $this->assertSame('draft', $foreign->status);
    }

    public function test_the_package_write_cutover_withholds_agent_writes_but_not_reads(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        config(['agent_api.package_writes_enabled' => false]);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->url($workspace, $package->public_id))->assertOk();
        $this->postJson($this->url($workspace), $this->hostingPackageInput(['link_name' => 'withheld']), ['Idempotency-Key' => 'synthetic-withheld'])->assertNotFound();
        $names = $this->toolNames($this->initialize());
        $this->assertContains('packages.list', $names);
        $this->assertNotContains('packages.create', $names);
        $this->assertNotContains('packages.publish', $names);
    }

    /**
     * Switched off after the routes were built, the feature is still
     * unreachable: every route answers 404 and no tool is offered (X-1).
     */
    #[Requirement('X-1')]
    public function test_switching_the_feature_off_withholds_every_route_and_tool(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        config(['svc.service_packages.enabled' => false]);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->url($workspace))->assertNotFound();
        $this->getJson($this->url($workspace, $package->public_id))->assertNotFound();
        $this->postJson($this->url($workspace, $package->public_id).'/publish', ['expected_version' => AgentApiVersion::for($package), 'confirm' => true], ['Idempotency-Key' => 'synthetic-off'])->assertNotFound();
        $this->assertSame([], array_values(array_filter($this->toolNames($this->initialize()), static fn (string $name): bool => str_starts_with($name, 'packages.'))));
        $this->assertSame('draft', $package->refresh()->status);
    }

    private function nonManager(Workspace $workspace, string $who): User
    {
        if ($who !== 'portal') {
            return $this->workspaceMember($workspace, $who);
        }
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Package Client', 'slug' => 'synthetic-'.Str::uuid()]);
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => 'company']);

        return $user;
    }

    private function url(Workspace $workspace, ?string $package = null): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/packages'.($package === null ? '' : '/'.$package);
    }

    /** @param array<string, mixed> $body */
    private function schema(string $operation, array $body): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
