<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageRequest;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Packages\PackageRequestWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Packages\EnrollmentVersions;
use Closure;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

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

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    protected array $packageStripeCalls = [];

    /**
     * Makes the deployment's Stripe key serve workspaces that never connected
     * their own, with a Stripe that answers in process: a US account that can
     * charge USD, EUR and GBP (EDGE-5), and whatever else `$answer` answers.
     * Nothing reaches Stripe; every request is recorded.
     *
     * @param  (Closure(string, string, array<string, mixed>): array{0: array<string, mixed>, 1?: int})|null  $answer
     * @param  list<string>  $currencies  lower-case, as Stripe spells them
     */
    protected function connectStripe(?Closure $answer = null, array $currencies = ['usd', 'eur', 'gbp']): void
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic_packages']);
        $record = function (string $method, string $path, array $params): void {
            $this->packageStripeCalls[] = ['method' => $method, 'path' => $path, 'params' => $params];
        };
        $answer = static function (string $method, string $path, array $params) use ($answer, $currencies): array {
            return match (true) {
                $path === '/v1/account' => [['id' => 'acct_synthetic_packages', 'object' => 'account', 'country' => 'US', 'default_currency' => 'usd']],
                $path === '/v1/country_specs/US' => [['id' => 'US', 'object' => 'country_spec', 'default_currency' => 'usd', 'supported_payment_currencies' => $currencies]],
                $answer !== null => $answer($method, $path, $params),
                default => [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe has no answer for '.$path]], 500],
            };
        };
        ApiRequestor::setHttpClient(new class($answer, $record) implements ClientInterface
        {
            public function __construct(private readonly Closure $answer, private readonly Closure $record) {}

            /**
             * @param  array<int|string, mixed>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                ($this->record)((string) $method, $path, $params);
                $answer = ($this->answer)((string) $method, $path, $params);

                return [json_encode($answer[0], JSON_THROW_ON_ERROR), $answer[1] ?? 200, []];
            }
        });
        $this->beforeApplicationDestroyed(static fn () => ApiRequestor::setHttpClient(CurlClient::instance()));
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

    /**
     * What the review page sends with a signature: the version it shows now
     * (ACC-2, EDGE-7). Read as the page reads it, so a test that publishes
     * before signing signs what a reloaded page would show.
     */
    protected function reviewedVersion(ServicePackageEnrollment $enrollment): string
    {
        $package = ServicePackage::query()->whereKey($enrollment->service_package_id)->sole();

        return ServicePackageVersion::query()->whereKey(EnrollmentVersions::shown($package, $enrollment->refresh()))->sole()->public_id;
    }

    /**
     * A signature on what the review page shows now.
     *
     * @return array<string, mixed>
     */
    protected function signatureFor(ServicePackageEnrollment $enrollment, string $name = 'Morgan Synthetic', ?string $title = null): array
    {
        return ['signer_name' => $name, 'signer_title' => $title, 'accepted_terms' => true, 'reviewed_version' => $this->reviewedVersion($enrollment)];
    }

    /** @param array<string, mixed> $overrides */
    protected function publishedPackage(Workspace $workspace, User $owner, array $overrides = []): ServicePackage
    {
        return app(ServicePackageWorkflow::class)->publish($owner, $workspace, $this->createPackage($workspace, $owner, $overrides));
    }
}
