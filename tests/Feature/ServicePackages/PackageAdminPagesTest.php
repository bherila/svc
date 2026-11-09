<?php

namespace Tests\Feature\ServicePackages;

use App\Services\Packages\ServicePackageReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

final class PackageAdminPagesTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use RefreshDatabase;

    #[Requirement('PKG-1')]
    public function test_owners_get_the_list_and_editor_with_finished_urls(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner);
        $this->actingAs($owner);

        $this->get("/workspaces/{$workspace->public_id}/packages")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('packages/index')
            ->has('packages', 1)
            ->where('packages.0.edit_url', "/workspaces/{$workspace->public_id}/packages/{$package->public_id}")
            ->where('new_package_url', "/workspaces/{$workspace->public_id}/packages/new")
            ->where('workspaceNavigation.packages_href', "/workspaces/{$workspace->public_id}/packages"));

        $this->get("/workspaces/{$workspace->public_id}/packages/new")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('packages/edit')
            ->where('package', null)
            ->where('urls.store', "/api/v1/workspaces/{$workspace->public_id}/packages")
            ->where('urls.update', null)
            ->where('defaults.currency', 'USD'));

        $this->get("/workspaces/{$workspace->public_id}/packages/{$package->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('packages/edit')
            ->where('package.id', $package->public_id)
            ->where('urls.update', "/api/v1/workspaces/{$workspace->public_id}/packages/{$package->public_id}")
            ->where('urls.publish', null)
            ->where('urls.retire', "/api/v1/workspaces/{$workspace->public_id}/packages/{$package->public_id}/retire"));
    }

    public function test_a_long_list_pages_with_finished_urls(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $first = $this->createPackage($workspace, $owner, ['link_name' => 'first-package']);
        $this->createPackage($workspace, $owner, ['link_name' => 'second-package']);
        $this->actingAs($owner);
        $next = null;

        $this->get("/workspaces/{$workspace->public_id}/packages")->assertOk()->assertInertia(function (AssertableInertia $page) use (&$next) {
            $page->where('first_page_url', null);
            $next = $page->toArray()['props']['next_page_url'];
        });
        $this->assertNull($next, 'two packages fit on one page');

        $cursor = app(ServicePackageReadService::class)->list($owner, $workspace, limit: 1)['meta']['next_cursor'];
        $this->get("/workspaces/{$workspace->public_id}/packages?cursor=".urlencode((string) $cursor))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('packages', 1)
            ->where('packages.0.link_name', 'second-package')
            ->where('first_page_url', "/workspaces/{$workspace->public_id}/packages"));
        $this->assertNotNull($first);
    }

    #[Requirement('PKG-1')]
    public function test_members_are_refused_and_strangers_see_nothing(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        [, $stranger] = $this->packageWorkspace('Other synthetic studio');
        $member = $this->workspaceMember($workspace, 'member');

        $this->actingAs($member)->get("/workspaces/{$workspace->public_id}/packages")->assertForbidden();
        $this->actingAs($member)->get("/workspaces/{$workspace->public_id}/packages/{$package->public_id}")->assertForbidden();
        $this->actingAs($stranger)->get("/workspaces/{$workspace->public_id}/packages")->assertNotFound();
        // The shared tenant binding refuses a non-member before the package is read (403), as for every workspace child.
        $this->actingAs($stranger)->get("/workspaces/{$workspace->public_id}/packages/{$package->public_id}")->assertForbidden();
    }

    #[Requirement('X-1')]
    public function test_with_the_feature_off_the_pages_and_navigation_entry_are_gone(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        config(['svc.service_packages.enabled' => false]);
        $this->actingAs($owner);

        $this->get("/workspaces/{$workspace->public_id}/packages")->assertNotFound();
        $this->get("/workspaces/{$workspace->public_id}/dashboard")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaceNavigation.packages_href', null));
    }
}
