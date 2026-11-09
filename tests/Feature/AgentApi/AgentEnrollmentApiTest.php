<?php

namespace Tests\Feature\AgentApi;

use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\CallsMcp;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Enrollments for owners and admins, and a client's own services for its
 * contacts, over REST and MCP (docs/service-packages.md, "MCP and REST
 * surface"). Accepting and adding are not here: they are the portal's.
 */
final class AgentEnrollmentApiTest extends TestCase
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
        Mail::fake();
    }

    #[Requirement('API-1', 'ACC-5', 'ACC-7')]
    public function test_an_owner_lists_reads_and_activates_an_enrollment_over_rest_and_mcp(): void
    {
        [$workspace, $owner, , $company, $contact, $enrollment] = $this->world(accept: true);
        $agreement = ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole();
        $this->actingAsMcp($owner, self::SCOPES);

        $list = $this->getJson($this->url($workspace))->assertOk();
        $this->schema('enrollments.list', $list->json());
        $list->assertJsonPath('data.0.id', $enrollment->public_id)
            ->assertJsonPath('data.0.status', 'awaiting_review')
            ->assertJsonPath('data.0.client', ['id' => $company->public_id, 'name' => $company->name])
            ->assertJsonPath('data.0.agreement_id', $agreement->public_id)
            ->assertJsonPath('data.0.agreement_url', url("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements/{$agreement->public_id}"))
            ->assertJsonPath('data.0.acceptance.signer_name', 'Morgan Synthetic')
            ->assertJsonPath('data.0.version_changed', false)
            ->assertJsonPath('data.0.request_id', null);
        $this->assertSame([], $this->getJson($this->url($workspace).'?status=active')->assertOk()->json('data'));
        $read = $this->getJson($this->url($workspace, $enrollment->public_id))->assertOk();
        $this->schema('enrollments.get', $read->json());
        $this->assertSame($list->json('data.0'), $read->json('data'));

        $session = $this->initialize();
        $got = $this->callTool($session, 'enrollments.get', ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id]);
        $this->assertSame($read->json(), $got['result']['structuredContent']);
        $listed = $this->callTool($session, 'enrollments.list', ['workspace_id' => $workspace->public_id, 'status' => 'awaiting_review']);
        $this->assertSame($list->json('data'), $listed['result']['structuredContent']['data']);

        $body = ['expected_version' => $read->json('data.version'), 'confirm' => true];
        $activated = $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', $body, ['Idempotency-Key' => 'synthetic-activate'])->assertOk();
        $this->schema('enrollments.activate', $activated->json());
        $activated->assertJsonPath('data.status', 'active');
        $this->assertIsString($activated->json('data.activated_at'));
        $this->assertSame('active', $agreement->refresh()->status);
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->hasTo($contact->email));

        // The same call over MCP is a replay: the same answer, one activation.
        $replay = $this->callTool($session, 'enrollments.activate', ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id, 'idempotency_key' => 'synthetic-activate', ...$body]);
        $this->assertFalse($replay['result']['isError'] ?? true, json_encode($replay));
        $this->assertSame($activated->json(), $replay['result']['structuredContent']);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'enrollments.activate', 'outcome' => 'replay']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unconfirmed(): iterable
    {
        yield 'without confirm' => [[]];
        yield 'with confirm false' => [['confirm' => false]];
        yield 'with a truthy string' => [['confirm' => 'true']];
    }

    /** @param array<string, mixed> $confirmation */
    #[DataProvider('unconfirmed')]
    #[Requirement('API-2')]
    public function test_activation_refuses_without_confirm_true_over_both_transports(array $confirmation): void
    {
        [$workspace, $owner, , , , $enrollment] = $this->world(accept: true);
        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['expected_version' => AgentApiVersion::for($enrollment), ...$confirmation];

        $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', $body, ['Idempotency-Key' => 'synthetic-unconfirmed'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $result = $this->callTool($this->initialize(), 'enrollments.activate', ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id, 'idempotency_key' => 'synthetic-unconfirmed-mcp', ...$body]);
        $this->assertTrue(($result['result']['isError'] ?? false) || isset($result['error']), json_encode($result));
        $this->assertSame('awaiting_review', $enrollment->refresh()->status);
    }

    #[Requirement('API-2')]
    public function test_the_server_instructions_ask_for_confirmation_before_activating(): void
    {
        [, $owner] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);

        $this->assertStringContainsString('Obtain explicit user confirmation before activating a package enrollment (enrollments.activate)',
            (string) $this->mcp($this->initializeMessage())->assertOk()->json('result.instructions'));
    }

    public function test_activating_an_offer_nobody_accepted_is_refused_with_its_code(): void
    {
        [$workspace, $owner, , , , $enrollment] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', ['expected_version' => AgentApiVersion::for($enrollment), 'confirm' => true], ['Idempotency-Key' => 'synthetic-early'])
            ->assertUnprocessable()->assertJsonPath('code', 'package_enrollment_not_awaiting_review');
        $this->assertSame('offered', $enrollment->refresh()->status);
    }

    #[Requirement('API-5')]
    public function test_a_company_contacts_token_reaches_only_their_own_services(): void
    {
        [$workspace, $owner, $package, $company, $contact, $enrollment] = $this->world(accept: true);
        $neighbour = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Hillside Synthetic Florist', 'slug' => 'hillside-'.Str::uuid()]);
        $theirs = app(PackageEnrollmentWorkflow::class)->addFromPortal($this->contact($neighbour), $neighbour, $package);
        $this->actingAsMcp($contact, self::SCOPES);

        // Owner/admin operations answer as if nothing existed.
        $this->getJson($this->url($workspace))->assertNotFound();
        $this->getJson($this->url($workspace, $enrollment->public_id))->assertNotFound();
        $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', ['expected_version' => AgentApiVersion::for($enrollment), 'confirm' => true], ['Idempotency-Key' => 'synthetic-client-activate'])->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/packages")->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/package-requests")->assertNotFound();

        $list = $this->getJson($this->services($workspace))->assertOk();
        $this->schema('client_services.list', $list->json());
        $list->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enrollment->public_id)
            ->assertJsonPath('data.0.status', 'awaiting_review')
            ->assertJsonPath('data.0.company', ['id' => $company->public_id, 'name' => $company->name])
            ->assertJsonPath('data.0.terms.agreement_text', 'Synthetic hosting agreement text.')
            ->assertJsonPath('data.0.acceptance.signer_name', 'Morgan Synthetic')
            ->assertJsonPath('data.0.url', url("/portal/{$company->public_id}/services/{$enrollment->public_id}"))
            ->assertJsonPath('addable_packages.0.id', $package->public_id)
            ->assertJsonPath('addable_packages.0.add_refusal', null);
        $this->assertArrayNotHasKey('invoice_issuing', $list->json('data.0.terms'), 'operator settings stay with the operator');
        $read = $this->getJson($this->services($workspace, $enrollment->public_id))->assertOk();
        $this->schema('client_services.get', $read->json());
        $this->assertSame($list->json('data.0'), $read->json('data'));
        $this->getJson($this->services($workspace, $theirs->public_id))->assertNotFound();
        $this->assertSame([], $this->getJson($this->services($workspace).'?company_id='.$neighbour->public_id)->assertOk()->json('data'));

        $session = $this->initialize();
        $names = $this->toolNames($session);
        $this->assertContains('client_services.list', $names);
        $this->assertContains('client_services.get', $names);
        $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'enrollments.') || str_starts_with($name, 'packages.') || str_starts_with($name, 'package_requests.'))));
        $got = $this->callTool($session, 'client_services.get', ['workspace_id' => $workspace->public_id, 'service_id' => $enrollment->public_id]);
        $this->assertSame($read->json(), $got['result']['structuredContent']);
        $listed = $this->callTool($session, 'client_services.list', ['workspace_id' => $workspace->public_id]);
        $this->assertSame($list->json('data'), $listed['result']['structuredContent']['data']);
    }

    #[Requirement('API-5')]
    public function test_a_project_limited_contact_sees_only_their_projects_services_and_nothing_to_add(): void
    {
        [$workspace, $owner, $package, $company, $contact, $enrollment] = $this->world();
        $other = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $this->publishedPackage($workspace, $owner, ['link_name' => 'second-hosting']));
        $limited = $this->contact($company, 'projects');
        DB::table('client_portal_project_access')->insert([
            'workspace_id' => $workspace->id,
            'client_company_membership_id' => ClientCompanyMembership::query()->where('user_id', $limited->id)->value('id'),
            'client_project_id' => $enrollment->client_project_id,
        ]);
        $this->actingAsMcp($limited, self::SCOPES);

        $list = $this->getJson($this->services($workspace))->assertOk();
        $this->assertSame([$enrollment->public_id], array_column($list->json('data'), 'id'));
        $this->assertSame([], $list->json('addable_packages'));
        $this->getJson($this->services($workspace, $other->public_id))->assertNotFound();
    }

    /** Staff who are not a client's contacts read enrollments, not client services. */
    public function test_an_owner_who_is_no_contact_has_no_client_services(): void
    {
        [$workspace, $owner, , , , $enrollment] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->services($workspace))->assertOk()->assertJsonPath('data', [])->assertJsonPath('addable_packages', []);
        $this->getJson($this->services($workspace, $enrollment->public_id))->assertNotFound();
    }

    public function test_another_workspaces_enrollment_is_not_found(): void
    {
        [$workspace, $owner] = $this->world();
        [, , , , , $foreign] = $this->world();
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->url($workspace, $foreign->public_id))->assertNotFound();
        $this->postJson($this->url($workspace, $foreign->public_id).'/activate', ['expected_version' => AgentApiVersion::for($foreign), 'confirm' => true], ['Idempotency-Key' => 'synthetic-foreign'])->assertNotFound();
        $this->assertCount(1, $this->getJson($this->url($workspace))->assertOk()->json('data'));
        $this->assertSame('offered', $foreign->refresh()->status);
    }

    public function test_the_package_write_cutover_withholds_activation_but_not_reads(): void
    {
        [$workspace, $owner, , , , $enrollment] = $this->world(accept: true);
        config(['agent_api.package_writes_enabled' => false]);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->url($workspace, $enrollment->public_id))->assertOk();
        $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', ['expected_version' => AgentApiVersion::for($enrollment), 'confirm' => true], ['Idempotency-Key' => 'synthetic-withheld'])->assertNotFound();
        $this->assertNotContains('enrollments.activate', $this->toolNames($this->initialize()));
        $this->assertSame('awaiting_review', $enrollment->refresh()->status);
    }

    #[Requirement('X-1')]
    public function test_switching_the_feature_off_withholds_every_enrollment_and_service_route(): void
    {
        [$workspace, $owner, , , , $enrollment] = $this->world(accept: true);
        config(['svc.service_packages.enabled' => false]);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->getJson($this->url($workspace))->assertNotFound();
        $this->getJson($this->services($workspace))->assertNotFound();
        $this->postJson($this->url($workspace, $enrollment->public_id).'/activate', ['expected_version' => AgentApiVersion::for($enrollment), 'confirm' => true], ['Idempotency-Key' => 'synthetic-off'])->assertNotFound();
        $this->assertSame([], array_values(array_filter($this->toolNames($this->initialize()), static fn (string $name): bool => str_starts_with($name, 'enrollments.') || str_starts_with($name, 'client_services.'))));
    }

    /**
     * API-4: a sign-up that saved a card, authorized autopay and owes an
     * invoice is read by its owner and its contact, over REST and MCP. The
     * payment state and the sign-up invoice's page come back; no Stripe key,
     * client secret, customer, card, card number or fingerprint, and no
     * invitation link, appear anywhere in any answer.
     */
    #[Requirement('API-4', 'ACC-3')]
    public function test_payment_state_is_read_without_any_secret_card_detail_or_invitation_link(): void
    {
        config(['services.stripe.publishable_key' => 'pk_test_synthetic_api4']);
        $this->connectStripe();
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, ['charge_first_period' => true, 'pay_before_activation' => true, 'require_autopay' => true, 'autopay_limit_amount' => 120000]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid(), 'billing_email' => 'billing@lakeside.example.test']);
        $contact = $this->contact($company);
        $customer = ClientStripeCustomer::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'stripe_customer_id' => 'cus_synthetic_api4']);
        ClientStripePaymentMethod::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_stripe_customer_id' => $customer->id,
            'stripe_payment_method_id' => 'pm_synthetic_api4', 'type' => 'card', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2038,
            'is_default' => true, 'fingerprint' => 'fp_synthetic_api4',
        ]);
        $consents = app(AutopayConsentService::class);
        $consents->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Client, $contact, 120000, wording: (string) $consents->authorizationWording($workspace, $company, 120000)));
        $workflow = app(PackageEnrollmentWorkflow::class);
        $offer = $workflow->addFromPortal($contact, $company, $package);
        $enrollment = $workflow->accept($contact, $company, $offer, $this->signatureFor($offer))->refresh();
        $invoice = ClientInvoice::query()->whereKey($enrollment->signup_invoice_id)->sole();
        $answers = [];

        $this->actingAsMcp($owner, self::SCOPES);
        $read = $this->getJson($this->url($workspace, $enrollment->public_id))->assertOk();
        $this->schema('enrollments.get', $read->json());
        $read->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payment.state', 'due')
            ->assertJsonPath('data.payment.waiting_for', ['payment'])
            ->assertJsonPath('data.payment.signup_invoice.id', $invoice->public_id)
            ->assertJsonPath('data.payment.signup_invoice.url', url("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$invoice->public_id}"))
            ->assertJsonPath('data.payment.requires_autopay', true)
            ->assertJsonPath('data.payment.autopay_limit_amount', 120000)
            ->assertJsonPath('data.payment.lapses_on', $enrollment->accepted_at?->addDays(14)->toDateString());
        $list = $this->getJson($this->url($workspace).'?status=awaiting_payment')->assertOk();
        $this->schema('enrollments.list', $list->json());
        $this->assertSame($read->json('data'), $list->json('data.0'));
        $session = $this->initialize();
        $answers[] = $read->getContent();
        $answers[] = $list->getContent();
        $answers[] = json_encode($this->callTool($session, 'enrollments.get', ['workspace_id' => $workspace->public_id, 'enrollment_id' => $enrollment->public_id]), JSON_THROW_ON_ERROR);
        $answers[] = json_encode($this->callTool($session, 'enrollments.list', ['workspace_id' => $workspace->public_id]), JSON_THROW_ON_ERROR);
        $answers[] = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/packages/{$package->public_id}")->assertOk()->getContent();

        $this->actingAsMcp($contact, self::SCOPES);
        $mine = $this->getJson($this->services($workspace, $enrollment->public_id))->assertOk();
        $this->schema('client_services.get', $mine->json());
        $mine->assertJsonPath('data.payment.signup_invoice.url', url("/portal/{$company->public_id}/invoices/{$invoice->public_id}"));
        $answers[] = $mine->getContent();
        $answers[] = $this->getJson($this->services($workspace))->assertOk()->getContent();
        $session = $this->initialize();
        $answers[] = json_encode($this->callTool($session, 'client_services.get', ['workspace_id' => $workspace->public_id, 'service_id' => $enrollment->public_id]), JSON_THROW_ON_ERROR);
        $answers[] = json_encode($this->callTool($session, 'client_services.list', ['workspace_id' => $workspace->public_id]), JSON_THROW_ON_ERROR);

        foreach ($answers as $answer) {
            foreach (['sk_test', 'pk_test', 'client_secret', 'whsec', 'cus_synthetic', 'pm_synthetic', 'pi_', '4242', 'fp_synthetic', 'fingerprint', 'last4', 'invitation'] as $secret) {
                $this->assertStringNotContainsString($secret, strtolower((string) $answer), $secret);
            }
        }
    }

    /** @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ClientCompany, 4: User, 5: ServicePackageEnrollment} */
    private function world(bool $accept = false): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->publishedPackage($workspace, $owner, ['activation_mode' => 'review']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Synthetic Bakery', 'slug' => 'lakeside-'.Str::uuid()]);
        $contact = $this->contact($company);
        $enrollment = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        if ($accept) {
            $enrollment = app(PackageEnrollmentWorkflow::class)->accept($contact, $company, $enrollment, $this->signatureFor($enrollment));
        }

        return [$workspace, $owner, $package, $company, $contact, $enrollment->refresh()];
    }

    private function contact(ClientCompany $company, string $scope = 'company'): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => $scope]);

        return $user;
    }

    private function url(Workspace $workspace, ?string $enrollment = null): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/enrollments'.($enrollment === null ? '' : '/'.$enrollment);
    }

    private function services(Workspace $workspace, ?string $service = null): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/client-services'.($service === null ? '' : '/'.$service);
    }

    /** @param array<string, mixed> $body */
    private function schema(string $operation, array $body): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation($operation)));
    }
}
