<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackage;
use App\Models\ServicePackageRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\PackageRequestWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use Illuminate\Support\Str;

/**
 * Synthetic service-package worlds: a workspace, its people, and packages.
 *
 * Every name and address is made up; emails use reserved example domains.
 */
trait BuildsServicePackages
{
    /** @return array{0: Workspace, 1: User} a workspace and its owner */
    protected function packageWorkspace(string $name = 'Synthetic Package Studio'): array
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => 'synthetic-packages-'.uniqid(), 'default_currency' => 'USD']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $owner];
    }

    protected function workspaceMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** Someone who may not manage the workspace: a `member`, a `viewer`, or a `portal` contact of one of its clients. */
    protected function packageNonManager(Workspace $workspace, string $who): User
    {
        if ($who !== 'portal') {
            return $this->workspaceMember($workspace, $who);
        }
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Package Client', 'slug' => 'synthetic-'.Str::uuid()]);
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => 'company']);

        return $user;
    }

    /**
     * A workspace and its owner, who signs in through the identity provider and so may invite.
     *
     * @return array{0: Workspace, 1: User}
     */
    protected function boundPackageWorkspace(string $subject = '4100'): array
    {
        config(['bherila-auth.oauth_client.provider' => 'test-provider']);
        [$workspace, $owner] = $this->packageWorkspace();
        $owner->forceFill(['oauth_provider' => 'test-provider', 'oauth_subject' => $subject])->save();

        return [$workspace, $owner];
    }

    /**
     * A visitor's request, recorded through the workflow.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function packageRequest(Workspace $workspace, ServicePackage $package, array $overrides = []): ServicePackageRequest
    {
        return app(PackageRequestWorkflow::class)->submit($workspace, $package, [
            'company_name' => 'Lakeside Synthetic Bakery',
            'contact_name' => 'Morgan Synthetic',
            'email' => 'morgan.lakeside@example.com',
            'answers' => ['website_address' => 'lakeside.example.com'],
            ...$overrides,
        ], null, null);
    }

    /** Makes the deployment's Stripe key serve workspaces that never connected their own. */
    protected function connectStripe(): void
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic_packages']);
    }

    protected function disconnectStripe(): void
    {
        config(['services.stripe.secret_key' => null]);
    }

    /**
     * A complete, publishable hosting package with no payment at sign-up.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function hostingPackageInput(array $overrides = []): array
    {
        return [
            'link_name' => 'web-hosting',
            'name' => 'Web hosting · Essentials',
            'summary' => 'Managed hosting for small business sites.',
            'body_markdown' => "- SSL\n- Daily backups",
            'agreement_text' => 'Synthetic hosting agreement text.',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'activation_mode' => 'automatic',
            'invoice_issuing' => 'automatic',
            'notice_days' => 30,
            'cancellation_mode' => 'self_serve',
            'components' => [
                ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500],
                ['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 15000],
            ],
            'intake_questions' => [
                ['label' => 'Website address', 'kind' => 'short_text', 'required' => true],
            ],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function createPackage(Workspace $workspace, User $owner, array $overrides = []): ServicePackage
    {
        return app(ServicePackageWorkflow::class)->create($owner, $workspace, $this->hostingPackageInput($overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function publishedPackage(Workspace $workspace, User $owner, array $overrides = []): ServicePackage
    {
        return app(ServicePackageWorkflow::class)->publish($owner, $workspace, $this->createPackage($workspace, $owner, $overrides));
    }
}
