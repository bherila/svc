<?php

namespace Tests\Feature\AgentApi;

use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\CallsMcp;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Moving package subscribers to a new version over the agent API
 * (docs/service-packages.md, "MCP and REST surface"): owners and admins
 * preview, schedule, list, read and cancel moves with `confirm` and the
 * current version; enrollments say which move they are part of; a client's
 * contacts read the notice through `client_services.*` and nothing else.
 *
 * The clock starts at 9 November 2026 in a UTC workspace.
 */
final class AgentPackageMigrationApiTest extends TestCase
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
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    #[Requirement('API-1', 'MIG-1', 'MIG-2', 'MIG-4')]
    public function test_an_owner_previews_schedules_and_reads_a_move_over_rest_and_mcp(): void
    {
        [$workspace, $owner, $package, $from, $enrollment, $contact] = $this->world();
        ClientCompany::query()->whereKey($enrollment->client_company_id)->update(['autopay_enabled_at' => now(), 'autopay_limit_amount' => 4800]);
        $this->actingAsMcp($owner, self::SCOPES);

        $preview = $this->getJson($this->packageUrl($workspace, $package).'/migration-preview?from_version_id='.$from->public_id)->assertOk();
        $this->schema('package_migrations.preview', $preview->json());
        $preview->assertJsonPath('data.effective_on', '2027-01-01')
            ->assertJsonPath('data.to_version.number', 2)
            ->assertJsonPath('data.new_price.amount', 5000)
            ->assertJsonPath('data.subscribers.0.enrollment_id', $enrollment->public_id)
            ->assertJsonPath('data.subscribers.0.autopay_below_price', true)
            ->assertJsonPath('data.subscribers.0.refusal', null);
        $session = $this->initialize();
        $viaMcp = $this->callTool($session, 'package_migrations.preview', ['workspace_id' => $workspace->public_id, 'package_id' => $package->public_id, 'from_version_id' => $from->public_id]);
        $this->assertSame($preview->json(), $viaMcp['result']['structuredContent']);

        $body = ['expected_version' => AgentApiVersion::for($package->refresh()), 'confirm' => true, 'from_version_id' => $from->public_id,
            'effective_on' => '2027-01-01', 'enrollment_ids' => [$enrollment->public_id], 'message' => 'Our hosting costs went up.'];
        $scheduled = $this->postJson($this->packageUrl($workspace, $package).'/migrations', $body, ['Idempotency-Key' => 'synthetic-move'])->assertCreated();
        $this->schema('package_migrations.schedule', $scheduled->json());
        $scheduled->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.effective_on', '2027-01-01')
            ->assertJsonPath('data.message', 'Our hosting costs went up.')
            ->assertJsonPath('data.counts.scheduled', 1)
            ->assertJsonPath('data.subscribers.0.autopay_below_price', true);
        Mail::assertSent(ServicePackageNoticeMail::class, 1);

        // The same call over MCP is a replay: one move, one email.
        $replay = $this->callTool($session, 'package_migrations.schedule', ['workspace_id' => $workspace->public_id, 'package_id' => $package->public_id, 'idempotency_key' => 'synthetic-move', ...$body]);
        $this->assertSame($scheduled->json(), $replay['result']['structuredContent']);
        $this->assertSame(1, ServicePackageMigration::query()->count());
        Mail::assertSent(ServicePackageNoticeMail::class, 1);

        $id = $scheduled->json('data.id');
        $list = $this->getJson($this->migrationsUrl($workspace))->assertOk();
        $this->schema('package_migrations.list', $list->json());
        $this->assertSame($scheduled->json('data'), $list->json('data.0'));
        $this->assertSame([], $this->getJson($this->migrationsUrl($workspace).'?status=cancelled')->assertOk()->json('data'));
        $read = $this->getJson($this->migrationsUrl($workspace, $id))->assertOk();
        $this->schema('package_migrations.get', $read->json());

        // enrollments.get says which move it is part of.
        $enrollmentRead = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/enrollments/{$enrollment->public_id}")->assertOk();
        $this->schema('enrollments.get', $enrollmentRead->json());
        $enrollmentRead->assertJsonPath('data.move.migration_id', $id)->assertJsonPath('data.move.effective_on', '2027-01-01')->assertJsonPath('data.current_version', null);

        // The client reads the notice, read-only.
        $this->actingAsMcp($contact, ['mcp:use', 'packages:read']);
        $service = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/client-services/{$enrollment->public_id}")->assertOk();
        $this->schema('client_services.get', $service->json());
        $service->assertJsonPath('data.move.effective_on', '2027-01-01')
            ->assertJsonPath('data.move.version_number', 2)
            ->assertJsonPath('data.move.autopay_below_price', true)
            ->assertJsonPath('data.move.terms.cycle_amount', 5000);
    }

    #[Requirement('API-1', 'API-2', 'MIG-5')]
    public function test_an_owner_cancels_a_move_with_confirm_and_its_current_version(): void
    {
        [$workspace, $owner, $package, $from, $enrollment] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);
        $id = $this->postJson($this->packageUrl($workspace, $package).'/migrations', [
            'expected_version' => AgentApiVersion::for($package->refresh()), 'confirm' => true, 'from_version_id' => $from->public_id,
            'effective_on' => '2027-01-01', 'enrollment_ids' => [$enrollment->public_id],
        ], ['Idempotency-Key' => 'synthetic-move'])->assertCreated()->json('data.id');
        $version = $this->getJson($this->migrationsUrl($workspace, $id))->json('data.version');

        $this->postJson($this->migrationsUrl($workspace, $id).'/cancel', ['expected_version' => $version], ['Idempotency-Key' => 'synthetic-unconfirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $result = $this->callTool($this->initialize(), 'package_migrations.cancel', ['workspace_id' => $workspace->public_id, 'migration_id' => $id, 'idempotency_key' => 'synthetic-unconfirmed-mcp', 'expected_version' => $version, 'confirm' => false]);
        $this->assertTrue(($result['result']['isError'] ?? false) || isset($result['error']), json_encode($result));
        $this->postJson($this->migrationsUrl($workspace, $id).'/cancel', ['expected_version' => str_repeat('0', 64), 'confirm' => true], ['Idempotency-Key' => 'synthetic-stale'])->assertConflict();
        $this->assertSame('scheduled', ServicePackageMigration::query()->sole()->status);

        $cancelled = $this->postJson($this->migrationsUrl($workspace, $id).'/cancel', ['expected_version' => $version, 'confirm' => true], ['Idempotency-Key' => 'synthetic-cancel'])->assertOk();
        $this->schema('package_migrations.cancel', $cancelled->json());
        $cancelled->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.counts.cancelled', 1);
        $this->postJson($this->migrationsUrl($workspace, $id).'/cancel', ['expected_version' => $cancelled->json('data.version'), 'confirm' => true], ['Idempotency-Key' => 'synthetic-cancel-again'])
            ->assertUnprocessable()->assertJsonPath('code', 'package_migration_not_scheduled');
    }

    #[Requirement('API-2', 'MIG-1')]
    public function test_scheduling_refuses_without_confirm_or_on_a_date_not_offered_and_changes_nothing(): void
    {
        [$workspace, $owner, $package, $from, $enrollment] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($package->refresh()), 'from_version_id' => $from->public_id, 'effective_on' => '2027-01-01', 'enrollment_ids' => [$enrollment->public_id]];

        $this->postJson($this->packageUrl($workspace, $package).'/migrations', $body, ['Idempotency-Key' => 'synthetic-unconfirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->postJson($this->packageUrl($workspace, $package).'/migrations', [...$body, 'confirm' => true, 'effective_on' => '2026-12-01'], ['Idempotency-Key' => 'synthetic-early'])
            ->assertUnprocessable()->assertJsonPath('code', 'package_migration_date_not_offered');
        $this->postJson($this->packageUrl($workspace, $package).'/migrations', [...$body, 'confirm' => true, 'enrollment_ids' => []], ['Idempotency-Key' => 'synthetic-none'])
            ->assertUnprocessable()->assertJsonValidationErrors('enrollment_ids');
        $this->postJson($this->packageUrl($workspace, $package).'/migrations', [...$body, 'confirm' => true, 'enrollment_ids' => ['not-a-uuid']], ['Idempotency-Key' => 'synthetic-bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('enrollment_ids.0');
        $this->postJson($this->packageUrl($workspace, $package).'/migrations', [...$body, 'confirm' => true, 'surprise' => 1], ['Idempotency-Key' => 'synthetic-extra'])
            ->assertUnprocessable();

        $this->assertSame(0, ServicePackageMigration::query()->count());
        Mail::assertNothingSent();
    }

    /** API-5: a client's own contact, a member and another workspace's owner find nothing here. */
    #[Requirement('API-5', 'MIG-1')]
    public function test_only_this_workspaces_owners_and_admins_reach_moves(): void
    {
        [$workspace, $owner, $package, $from, $enrollment, $contact] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);
        $id = $this->postJson($this->packageUrl($workspace, $package).'/migrations', [
            'expected_version' => AgentApiVersion::for($package->refresh()), 'confirm' => true, 'from_version_id' => $from->public_id,
            'effective_on' => '2027-01-01', 'enrollment_ids' => [$enrollment->public_id],
        ], ['Idempotency-Key' => 'synthetic-move'])->assertCreated()->json('data.id');
        [, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $member = $this->workspaceMember($workspace, 'member');

        foreach ([$contact, $member, $otherOwner] as $outsider) {
            $this->actingAsMcp($outsider, self::SCOPES);
            $this->getJson($this->migrationsUrl($workspace))->assertNotFound();
            $this->getJson($this->migrationsUrl($workspace, $id))->assertNotFound();
            $this->getJson($this->packageUrl($workspace, $package).'/migration-preview?from_version_id='.$from->public_id)->assertNotFound();
            $this->postJson($this->migrationsUrl($workspace, $id).'/cancel', ['expected_version' => str_repeat('0', 64), 'confirm' => true], ['Idempotency-Key' => 'synthetic-outsider-'.Str::random(6)])->assertNotFound();
        }
        $this->assertSame('scheduled', ServicePackageMigration::query()->sole()->status);
    }

    /**
     * A package published as version 1, a client accepted on it, then version 2.
     *
     * @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ServicePackageVersion, 4: ServicePackageEnrollment, 5: User}
     */
    private function world(): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, ['invoice_issuing' => 'automatic', 'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500]]]);
        $from = ServicePackageVersion::query()->findOrFail($package->published_version_id);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid(), 'billing_email' => 'billing-lakeside@example.com']);
        $contact = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
        $workflow = app(PackageEnrollmentWorkflow::class);
        $enrollment = $workflow->accept($contact, $company, $workflow->addFromPortal($contact, $company, $package), ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true]);
        $packages = app(ServicePackageWorkflow::class);
        $packages->update($owner, $workspace, $package->refresh(), ['components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5000]]]);
        $packages->publish($owner, $workspace, $package->refresh());
        Mail::fake();

        return [$workspace->refresh(), $owner, $package->refresh(), $from, $enrollment->refresh(), $contact];
    }

    private function packageUrl(Workspace $workspace, ServicePackage $package): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/packages/{$package->public_id}";
    }

    private function migrationsUrl(Workspace $workspace, ?string $id = null): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/package-migrations".($id === null ? '' : '/'.$id);
    }

    /** @param array<string, mixed> $body */
    private function schema(string $operation, array $body): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
