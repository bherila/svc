<?php

namespace Tests\Concerns;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\Autopay\AutopayWording;
use Closure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Synthetic autopay fixtures (#636): reserved domains, obvious test
 * identifiers, a workspace on New York time so calendar days and UTC instants
 * disagree for part of every day, and a Stripe that answers in process and
 * records every request - its idempotency key included.
 */
trait BuildsAutopay
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, idempotency: string|null}> */
    protected array $autopayStripeCalls = [];

    /**
     * @param  Closure(string, string, array<string, mixed>): array{0: array<string, mixed>, 1?: int}|null  $answer
     */
    protected function fakeAutopayStripe(?Closure $answer = null): void
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic_autopay']);
        $answer ??= static fn (string $method, string $path, array $params): array => [[
            'id' => 'pi_synthetic_'.substr(hash('sha256', json_encode($params['metadata'] ?? [], JSON_THROW_ON_ERROR)), 0, 10),
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $params['amount'] ?? 0,
        ]];
        $record = function (array $call): void {
            $this->autopayStripeCalls[] = $call;
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
                $key = null;
                foreach ($headers as $header) {
                    if (is_string($header) && str_starts_with($header, 'Idempotency-Key: ')) {
                        $key = substr($header, strlen('Idempotency-Key: '));
                    }
                }
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                ($this->record)(['method' => (string) $method, 'path' => $path, 'params' => $params, 'idempotency' => $key]);
                $answer = ($this->answer)((string) $method, $path, $params);

                return [json_encode($answer[0], JSON_THROW_ON_ERROR), $answer[1] ?? 200, []];
            }
        });
    }

    protected function restoreAutopayStripe(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>, idempotency: string|null}> */
    protected function autopayCharges(): array
    {
        return array_values(array_filter($this->autopayStripeCalls, static fn (array $call): bool => $call['path'] === '/v1/payment_intents' && strtolower($call['method']) === 'post'));
    }

    /**
     * A workspace with a Stripe account, a client with a billing address, a
     * portal contact and a default Visa ending 4242.
     *
     * @return array{0: Workspace, 1: User, 2: ClientCompany, 3: User, 4: ClientStripePaymentMethod}
     */
    protected function autopayFixture(string $slug = 'autopay'): array
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic_autopay']);
        $workspace = Workspace::query()->create(['name' => 'Example Studio '.$slug, 'slug' => $slug.'-'.uniqid(), 'timezone' => 'America/New_York']);
        $owner = User::factory()->create(['name' => 'Sam Example']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Northwind Example Co. '.$slug,
            'slug' => 'northwind-'.$slug.'-'.uniqid(),
            'billing_email' => 'billing@northwind.example.test',
        ]);
        $contact = User::factory()->create(['name' => 'Dana Example']);
        $company->portalUsers()->attach($contact->id, ['workspace_id' => $workspace->id]);
        $customer = ClientStripeCustomer::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'stripe_customer_id' => 'cus_synthetic_'.$slug,
        ]);
        $method = $this->autopayMethod($workspace, $company, $customer, 'pm_synthetic_'.$slug, true);

        return [$workspace->refresh(), $owner, $company->refresh(), $contact, $method];
    }

    protected function autopayMethod(Workspace $workspace, ClientCompany $company, ClientStripeCustomer $customer, string $providerId, bool $default, string $last4 = '4242'): ClientStripePaymentMethod
    {
        return ClientStripePaymentMethod::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_stripe_customer_id' => $customer->id,
            'stripe_payment_method_id' => $providerId,
            'type' => 'card',
            'brand' => 'visa',
            'last4' => $last4,
            'exp_month' => 8,
            'exp_year' => 2038,
            'is_default' => $default,
        ]);
    }

    protected function autopayInvoice(ClientCompany $company, string $number = 'INV-0142', int $balance = 375_000, string $due = '2030-12-01', string $status = 'issued'): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $company->workspace_id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'invoice_kind' => 'ad_hoc',
            'status' => $status,
            'currency' => 'USD',
            'subtotal_amount' => $balance,
            'tax_amount' => 0,
            'total_amount' => $balance,
            'paid_amount' => 0,
            'balance_amount' => $balance,
            'issue_date' => '2030-11-01',
            'due_date' => $due,
            'issued_at' => '2030-11-01 15:00:00',
            'is_visible_to_client' => true,
        ]);
    }

    /** Turn autopay on as the client would, with the wording the portal shows. */
    protected function turnAutopayOn(Workspace $workspace, ClientCompany $company, User $contact, int $limit = 500_000): void
    {
        $service = app(AutopayConsentService::class);
        $service->turnOn($workspace, $company, new AutopayConsentRequest(
            AutopayActorRole::Client,
            $contact,
            $limit,
            wording: AutopayWording::authorization($workspace->name, $company->name, 'Visa ending 4242', $limit, 'USD'),
        ));
    }
}
