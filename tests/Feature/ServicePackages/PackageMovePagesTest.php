<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageMigrationWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * The move screens (docs/service-packages.md, slice 6): the editor's
 * versions and moves, the page that previews and schedules a move, and the
 * portal notice. Every date, flag and URL is the server's; a control is
 * offered exactly when it may be used.
 *
 * The clock starts at 9 November 2026 in a UTC workspace.
 */
final class PackageMovePagesTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    #[Requirement('MIG-1', 'MIG-5')]
    public function test_the_editor_lists_versions_with_their_subscribers_and_offers_moving_the_older_ones(): void
    {
        [$workspace, $owner, $package, $from, $enrollment] = $this->world();
        $this->actingAs($owner);
        $editor = "/workspaces/{$workspace->public_id}/packages/{$package->public_id}";

        $this->get($editor)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('packages/edit')
            ->where('moves.versions.0.number', 1)
            ->where('moves.versions.0.subscribers', 1)
            ->where('moves.versions.0.move_url', "{$editor}/move?from={$from->public_id}")
            ->where('moves.versions.1.number', 2)
            ->where('moves.versions.1.subscribers', 0)
            ->where('moves.versions.1.move_url', null)
            ->where('moves.moves', []));

        $migration = app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', [$enrollment->public_id], null);
        $this->get($editor)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('moves.moves.0.id', $migration->public_id)
            ->where('moves.moves.0.status', 'scheduled')
            ->where('moves.moves.0.cancel_url', "/api/v1/workspaces/{$workspace->public_id}/package-migrations/{$migration->public_id}/cancel"));

        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration);
        $this->get($editor)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('moves.moves.0.status', 'cancelled')
            ->where('moves.moves.0.cancel_url', null));
    }

    #[Requirement('MIG-1', 'MIG-4')]
    public function test_the_move_page_previews_the_server_dates_and_schedules_through_the_agent_endpoint(): void
    {
        [$workspace, $owner, $package, $from, $enrollment] = $this->world();
        ClientCompany::query()->whereKey($enrollment->client_company_id)->update(['autopay_enabled_at' => now(), 'autopay_limit_amount' => 4800]);
        $this->actingAs($owner);
        $page = "/workspaces/{$workspace->public_id}/packages/{$package->public_id}/move?from={$from->public_id}";

        $this->get($page)->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('packages/move')
            ->where('preview.effective_on', '2027-01-01')
            ->where('preview.effective_dates.0', ['date' => '2027-01-01', 'subscribers' => 1])
            ->where('preview.subscribers.0.enrollment_id', $enrollment->public_id)
            ->where('preview.subscribers.0.autopay_below_price', true)
            ->where('preview.subscribers.0.refusal', null)
            ->where('package_version', AgentApiVersion::for($package->refresh()))
            ->where('urls.schedule', "/api/v1/workspaces/{$workspace->public_id}/packages/{$package->public_id}/migrations")
            ->where('urls.editor', "/workspaces/{$workspace->public_id}/packages/{$package->public_id}")
            ->where('urls.preview', $page));
        $this->get($page.'&effective_on=2026-12-01')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('preview.effective_on', '2026-12-01')
            ->where('preview.subscribers.0.refusal.code', 'package_migration_date_not_offered'));

    }

    #[Requirement('MIG-1', 'X-1')]
    public function test_only_this_workspaces_owners_and_admins_open_the_move_page(): void
    {
        [$workspace, , $package, $from] = $this->world();
        $page = "/workspaces/{$workspace->public_id}/packages/{$package->public_id}/move?from={$from->public_id}";

        $this->actingAs($this->workspaceMember($workspace, 'member'))->get($page)->assertForbidden();
        // The shared tenant binding refuses a non-member before the package is read (403), as for every workspace child.
        [, $stranger] = $this->packageWorkspace('Synthetic Studio Two');
        $this->actingAs($stranger)->get($page)->assertForbidden();
        // X-1: with the feature off the page does not exist.
        config(['svc.service_packages.enabled' => false]);
        $this->actingAs($this->workspaceMember($workspace, 'owner'))->get($page)->assertNotFound();
    }

    #[Requirement('MIG-2')]
    public function test_the_portal_service_page_carries_the_notice(): void
    {
        [$workspace, $owner, $package, $from, $enrollment, $contact] = $this->world();
        app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', [$enrollment->public_id], 'Our hosting costs went up.');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $this->actingAs($contact);

        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/service')
            ->where('service.move.effective_on', '2027-01-01')
            ->where('service.move.version_number', 2)
            ->where('service.move.message', 'Our hosting costs went up.')
            ->where('service.move.changes.0.term', 'cycle_amount'));
        $this->get("/portal/{$company->public_id}/services")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('services.0.move.effective_on', '2027-01-01'));
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
        $enrollment = $workflow->accept($contact, $company, $offer = $workflow->addFromPortal($contact, $company, $package), $this->signatureFor($offer));
        $packages = app(ServicePackageWorkflow::class);
        $packages->update($owner, $workspace, $package->refresh(), ['components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5000]]]);
        $packages->publish($owner, $workspace, $package->refresh());

        return [$workspace->refresh(), $owner, $package->refresh(), $from, $enrollment->refresh(), $contact];
    }
}
