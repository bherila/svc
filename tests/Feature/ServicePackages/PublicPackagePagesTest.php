<?php

namespace Tests\Feature\ServicePackages;

use App\Services\Packages\ServicePackageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

final class PublicPackagePagesTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
    }

    #[Requirement('X-1')]
    public function test_with_the_feature_off_the_public_pages_do_not_exist(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->publishedPackage($workspace, $owner);
        $token = $workspace->refresh()->package_catalog_token;
        config(['svc.service_packages.enabled' => false]);

        $this->get("/s/{$token}")->assertNotFound();
        $this->get("/s/{$token}/web-hosting")->assertNotFound();
    }

    #[Requirement('PUB-1')]
    public function test_a_published_package_is_readable_without_signing_in(): void
    {
        [$workspace, $owner] = $this->packageWorkspace('Synthetic Hosting Studio');
        $this->publishedPackage($workspace, $owner, ['hourly_rate_amount' => 14000, 'invoice_issuing' => 'draft']);
        $token = $workspace->refresh()->package_catalog_token;

        $this->get("/s/{$token}/web-hosting")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('packages/public/show')
                ->where('workspace.name', 'Synthetic Hosting Studio')
                ->where('package.name', 'Web hosting · Essentials')
                ->where('package.currency', 'USD')
                ->where('package.cycle_amount', 4500)
                ->where('package.setup_fee_amount', 15000)
                ->where('package.hourly_rate_amount', 14000)
                ->where('package.signup.setup_fee_amount', null)
                ->where('package.notice_days', 30)
                ->where('request', null));
    }

    #[Requirement('PUB-1')]
    public function test_every_miss_is_the_same_404(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->publishedPackage($workspace, $owner);
        $this->createPackage($workspace, $owner, ['link_name' => 'still-a-draft']);
        $retired = $this->publishedPackage($workspace, $owner, ['link_name' => 'retired-one']);
        app(ServicePackageWorkflow::class)->retire($owner, $workspace, $retired);
        $token = $workspace->refresh()->package_catalog_token;
        [$other, $otherOwner] = $this->packageWorkspace('Other synthetic studio');
        $this->publishedPackage($other, $otherOwner, ['link_name' => 'theirs']);

        $misses = [
            "/s/{$token}/still-a-draft",
            "/s/{$token}/retired-one",
            "/s/{$token}/no-such-package",
            "/s/{$token}/theirs",
            '/s/'.str_repeat('a', 24),
            '/s/'.str_repeat('a', 24).'/web-hosting',
            '/s/short',
        ];
        $bodies = [];
        foreach ($misses as $miss) {
            $response = $this->get($miss);
            $response->assertNotFound();
            $bodies[] = $response->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'A miss revealed which kind of miss it was.');
    }

    #[Requirement('PUB-2')]
    public function test_public_pages_carry_no_ids(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, ['is_listed' => true]);
        $token = $workspace->refresh()->package_catalog_token;

        foreach (["/s/{$token}", "/s/{$token}/web-hosting"] as $url) {
            $content = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($workspace->public_id, $content);
            $this->assertStringNotContainsString($package->public_id, $content);
            $this->assertStringNotContainsString((string) $package->publishedVersion?->public_id, $content);
        }
    }

    #[Requirement('PUB-3')]
    public function test_the_catalog_lists_only_published_listed_packages(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->publishedPackage($workspace, $owner, ['link_name' => 'listed', 'name' => 'Listed hosting', 'is_listed' => true]);
        $this->publishedPackage($workspace, $owner, ['link_name' => 'unlisted', 'name' => 'Unlisted audit', 'is_listed' => false]);
        $this->createPackage($workspace, $owner, ['link_name' => 'draft', 'name' => 'Draft hosting', 'is_listed' => true]);
        $token = $workspace->refresh()->package_catalog_token;

        $this->get("/s/{$token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('packages/public/catalog')
            ->has('packages', 1)
            ->where('packages.0.name', 'Listed hosting')
            ->where('packages.0.url', rtrim((string) config('app.url'), '/')."/s/{$token}/listed"));
        $this->get("/s/{$token}/unlisted")->assertOk();
    }
}
