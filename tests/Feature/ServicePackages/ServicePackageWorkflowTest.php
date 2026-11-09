<?php

namespace Tests\Feature\ServicePackages;

use App\Exceptions\ServicePackageRefused;
use App\Models\ServicePackage;
use App\Models\ServicePackageComponent;
use App\Models\ServicePackageVersion;
use App\Models\Workspace;
use App\Services\Packages\ServicePackageReadService;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

final class ServicePackageWorkflowTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    private function workflow(): ServicePackageWorkflow
    {
        return app(ServicePackageWorkflow::class);
    }

    #[Requirement('PKG-2', 'PKG-3')]
    public function test_creating_writes_a_draft_first_version_with_every_term(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();

        $package = $this->createPackage($workspace, $owner, ['retainer_amount' => 60000, 'retainer_minutes' => 300, 'invoice_issuing' => 'draft']);

        $this->assertSame('draft', $package->status);
        $this->assertNull($package->published_version_id);
        $version = ServicePackageVersion::query()->sole();
        $this->assertSame(1, $version->number);
        $this->assertSame(1, $version->draft_marker);
        $this->assertNull($version->published_at);
        $this->assertSame(60000, $version->retainer_amount);
        $this->assertSame(300, $version->retainer_minutes);
        $this->assertSame('website_address', $version->intake_questions[0]['key']);
        $this->assertSame(['recurring', 'setup_fee'], ServicePackageComponent::query()->orderBy('sort_order')->pluck('kind')->all());
        $this->assertSame('one_time', ServicePackageComponent::query()->where('kind', 'setup_fee')->value('cadence'));
    }

    #[Requirement('PKG-2')]
    public function test_publishing_freezes_the_draft_and_later_edits_write_a_new_version(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner);
        $v1 = ServicePackageVersion::query()->sole();
        $frozen = $v1->only(['name', 'currency', 'billing_cadence', 'agreement_text']);

        $updated = $this->workflow()->update($owner, $workspace, $package, ['name' => 'Web hosting · Plus', 'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5500],
        ]]);

        $v1->refresh();
        $this->assertNotNull($v1->published_at);
        $this->assertNull($v1->draft_marker);
        $this->assertSame($frozen, $v1->only(['name', 'currency', 'billing_cadence', 'agreement_text']));
        $this->assertSame(2, ServicePackageComponent::query()->where('service_package_version_id', $v1->id)->count(), 'the published version keeps its components');
        $draft = ServicePackageVersion::query()->where('draft_marker', 1)->sole();
        $this->assertSame(2, $draft->number);
        $this->assertSame('Web hosting · Plus', $draft->name);
        $this->assertSame('Synthetic hosting agreement text.', $draft->agreement_text, 'unsent terms carry over from the published version');
        $this->assertSame([5500], ServicePackageComponent::query()->where('service_package_version_id', $draft->id)->pluck('amount')->all());
        $this->assertSame($v1->id, $updated->published_version_id, 'new sign-ups keep getting v1 until v2 is published');

        $republished = $this->workflow()->publish($owner, $workspace, $updated);
        $this->assertSame($draft->id, $republished->published_version_id);
        $this->assertSame(0, ServicePackageVersion::query()->where('draft_marker', 1)->count());
    }

    public function test_editing_an_existing_draft_updates_it_in_place(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);

        $this->workflow()->update($owner, $workspace, $package, ['summary' => 'Changed']);

        $this->assertSame(1, ServicePackageVersion::query()->count());
        $this->assertSame('Changed', ServicePackageVersion::query()->value('summary'));
        $this->assertSame(2, ServicePackageComponent::query()->count(), 'components are kept when the update does not send them');
    }

    public function test_a_partial_update_is_validated_against_the_merged_terms(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);

        $this->expectException(ValidationException::class);
        $this->workflow()->update($owner, $workspace, $package, ['currency' => 'dollars']);
    }

    public function test_package_fields_change_without_creating_a_draft(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner);
        $before = AgentApiVersion::for($package);

        $updated = $this->workflow()->update($owner, $workspace, $package, ['is_listed' => true, 'link_name' => 'hosting']);

        $this->assertTrue($updated->is_listed);
        $this->assertSame('hosting', $updated->link_name);
        $this->assertSame(0, ServicePackageVersion::query()->where('draft_marker', 1)->count());
        $this->assertNotSame($before, AgentApiVersion::for($updated));
    }

    public function test_a_draft_only_change_still_advances_the_package_version(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $before = AgentApiVersion::for($package);

        $updated = $this->workflow()->update($owner, $workspace, $package, ['summary' => 'New']);

        $this->assertNotSame($before, AgentApiVersion::for($updated));
    }

    public function test_unchanged_package_fields_sent_with_term_edits_still_advance_the_version(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $before = AgentApiVersion::for($package);

        // What the editor sends: every package field, unchanged, with the edited terms.
        $updated = $this->workflow()->update($owner, $workspace, $package, [
            'link_name' => $package->link_name, 'is_listed' => $package->is_listed, 'portal_addable' => $package->portal_addable,
            'summary' => 'Edited',
        ], $before);

        $this->assertNotSame($before, AgentApiVersion::for($updated));
        try {
            $this->workflow()->update($owner, $workspace, $package, ['summary' => 'Second editor'], $before);
            $this->fail('A stale version overwrote an edit.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Edited', ServicePackageVersion::query()->value('summary'));
    }

    public function test_a_stale_expected_version_is_refused_with_409(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $stale = AgentApiVersion::for($package);
        $this->workflow()->update($owner, $workspace, $package, ['summary' => 'First']);

        try {
            $this->workflow()->update($owner, $workspace, $package, ['summary' => 'Second'], $stale);
            $this->fail('A stale version was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('First', ServicePackageVersion::query()->value('summary'));
    }

    public function test_link_names_are_unique_within_a_workspace_but_not_across_them(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        [$other, $otherOwner] = $this->packageWorkspace('Other synthetic studio');
        $this->createPackage($workspace, $owner);
        $this->createPackage($other, $otherOwner);

        try {
            $this->createPackage($workspace, $owner);
            $this->fail('A duplicate link name was accepted.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::LinkNameTaken->value, $refused->refusalCode());
        }
    }

    #[Requirement('PKG-4')]
    public function test_publishing_a_paid_package_without_stripe_is_refused_and_writes_nothing(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->disconnectStripe();
        $package = $this->createPackage($workspace, $owner, ['charge_first_period' => true]);

        try {
            $this->workflow()->publish($owner, $workspace, $package);
            $this->fail('A paid package published without Stripe.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::PaymentNeedsStripe->value, $refused->refusalCode());
        }
        $this->assertSame('draft', $package->refresh()->status);
        $this->assertNull(ServicePackageVersion::query()->value('published_at'));
        $this->assertNull($workspace->refresh()->package_catalog_token);

        $this->connectStripe();
        $this->assertSame('published', $this->workflow()->publish($owner, $workspace, $package)->status);
    }

    #[Requirement('PKG-5')]
    public function test_publishing_automatic_issuing_with_an_hourly_rate_is_refused(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner, ['hourly_rate_amount' => 14000, 'invoice_issuing' => 'automatic']);

        $this->expectExceptionObject(ServicePackageRefused::because(PackageRefusal::AutoIssueNeedsFixedPrice));
        $this->workflow()->publish($owner, $workspace, $package);
    }

    #[Requirement('PUB-2')]
    public function test_the_first_publish_gives_the_workspace_one_unguessable_catalog_token(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->publishedPackage($workspace, $owner);
        $token = Workspace::query()->whereKey($workspace->id)->value('package_catalog_token');
        $this->publishedPackage($workspace, $owner, ['link_name' => 'second']);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', (string) $token);
        $this->assertSame($token, $workspace->package_catalog_token, 'the caller\'s workspace carries the token it just opened');
        $this->assertSame($token, $workspace->refresh()->package_catalog_token);
        $presented = app(ServicePackageReadService::class)->list($owner, $workspace)['data'][0];
        $this->assertSame(rtrim((string) config('app.url'), '/').'/s/'.$token.'/web-hosting', $presented['public_url']);
        $this->assertStringNotContainsString($workspace->public_id, (string) $presented['public_url']);
        $this->assertStringNotContainsString((string) $presented['id'], (string) $presented['public_url']);
    }

    public function test_publishing_with_no_draft_is_refused(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner);

        $this->expectExceptionObject(ServicePackageRefused::because(PackageRefusal::NothingToPublish));
        $this->workflow()->publish($owner, $workspace, $package);
    }

    #[Requirement('PKG-6')]
    public function test_only_a_published_package_can_be_retired_and_retiring_keeps_its_versions(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $draft = $this->createPackage($workspace, $owner);
        try {
            $this->workflow()->retire($owner, $workspace, $draft);
            $this->fail('A draft package was retired.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::NotPublished->value, $refused->refusalCode());
        }

        $published = $this->publishedPackage($workspace, $owner, ['link_name' => 'published-one']);
        $retired = $this->workflow()->retire($owner, $workspace, $published);

        $this->assertSame('retired', $retired->status);
        $this->assertSame($published->published_version_id, $retired->published_version_id);
        $this->assertNull(app(ServicePackageReadService::class)->get($owner, $workspace, $retired->public_id)['public_url']);
    }

    #[Requirement('PKG-1')]
    public function test_only_owners_and_admins_manage_packages(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $admin = $this->workspaceMember($workspace, 'admin');
        $this->assertSame('draft', $this->workflow()->update($admin, $workspace, $package, ['summary' => 'By admin'])->status);

        foreach (['member', 'contributor', 'viewer'] as $role) {
            $member = $this->workspaceMember($workspace, $role);
            foreach ([
                fn () => $this->workflow()->create($member, $workspace, $this->hostingPackageInput(['link_name' => 'by-'.$role])),
                fn () => $this->workflow()->update($member, $workspace, $package, ['summary' => 'x']),
                fn () => $this->workflow()->publish($member, $workspace, $package),
                fn () => $this->workflow()->retire($member, $workspace, $package),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("A {$role} changed a package.");
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
            try {
                app(ServicePackageReadService::class)->get($member, $workspace, $package->public_id);
                $this->fail("A {$role} read a package.");
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $this->assertSame(1, ServicePackage::query()->count());
    }

    #[Requirement('PKG-1')]
    public function test_a_package_is_reachable_only_through_its_own_workspace(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        [$other, $otherOwner] = $this->packageWorkspace('Other synthetic studio');
        $package = $this->createPackage($workspace, $owner);

        $this->assertSame([], app(ServicePackageReadService::class)->list($otherOwner, $other)['data']);
        foreach ([
            fn () => app(ServicePackageReadService::class)->get($otherOwner, $other, $package->public_id),
            fn () => $this->workflow()->update($otherOwner, $other, $package, ['summary' => 'stolen']),
            fn () => $this->workflow()->publish($otherOwner, $other, $package),
            fn () => $this->workflow()->retire($otherOwner, $other, $package),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another workspace reached the package.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('Managed hosting for small business sites.', ServicePackageVersion::query()->value('summary'));
    }

    public function test_the_list_pages_with_a_cursor_and_filters_by_status(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->publishedPackage($workspace, $owner, ['link_name' => 'pkg-a']);
        $this->createPackage($workspace, $owner, ['link_name' => 'pkg-b']);
        $this->createPackage($workspace, $owner, ['link_name' => 'pkg-c']);
        $reads = app(ServicePackageReadService::class);

        $first = $reads->list($owner, $workspace, limit: 2);
        $second = $reads->list($owner, $workspace, limit: 2, cursor: $first['meta']['next_cursor']);

        $this->assertSame(['pkg-a', 'pkg-b'], array_column($first['data'], 'link_name'));
        $this->assertSame(['pkg-c'], array_column($second['data'], 'link_name'));
        $this->assertNull($second['meta']['next_cursor']);
        $this->assertSame(['pkg-a'], array_column($reads->list($owner, $workspace, PackageStatus::Published)['data'], 'link_name'));
    }

    public function test_the_presented_draft_carries_its_publish_refusals(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->disconnectStripe();
        $package = $this->createPackage($workspace, $owner, ['require_autopay' => true]);

        $presented = app(ServicePackageReadService::class)->get($owner, $workspace, $package->public_id);

        $this->assertSame(['package_payment_needs_stripe', 'package_autopay_needs_limit'], array_column($presented['publish_refusals'], 'code'));
        $this->assertSame(15000, $presented['draft']['setup_fee_total']);
        $this->assertNull($presented['published']);
    }
}
