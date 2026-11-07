<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\Workspace;
use DomainException;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * The Stripe adapter for a client's saved payment methods (#642): the calls the
 * portal makes on the client's behalf, always with the workspace's own key.
 *
 * Every call here is irrevocable on Stripe's side, so none of them is made
 * inside a database transaction; the callers record the result afterwards. A
 * Stripe failure is logged with its code and reaches the client as a refusal
 * that names no provider detail.
 */
final class StripePaymentMethodGateway
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * Create the Stripe customer for a client company. The idempotency key is
     * derived from the pair, so two concurrent or repeated first uses are
     * handed the same customer rather than minting two.
     */
    public function createCustomer(Workspace $workspace, ClientCompany $company): string
    {
        $customer = $this->call($workspace, 'customer', fn (StripeClient $client): array => $client->customers->create([
            'name' => $company->name,
            'metadata' => [
                'workspace_public_id' => $workspace->public_id,
                'client_company_public_id' => $company->public_id,
            ],
        ], ['idempotency_key' => 'svc-customer-'.$workspace->public_id.'-'.$company->public_id])->toArray());

        return $this->requiredString($customer['id'] ?? null, 'customer');
    }

    /**
     * A SetupIntent that saves a card or US bank account for off-session use,
     * and its client secret - the one value the browser receives.
     */
    public function createSetupIntent(Workspace $workspace, ClientCompany $company, string $customerId, ?string $attempt): string
    {
        $options = $attempt === null ? [] : [
            'idempotency_key' => 'svc-setup-'.hash('sha256', $workspace->public_id.'|'.$company->public_id.'|'.$attempt),
        ];
        $intent = $this->call($workspace, 'setup intent', fn (StripeClient $client): array => $client->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types' => ['card', 'us_bank_account'],
            'payment_method_options' => ['us_bank_account' => ['verification_method' => 'automatic']],
            'metadata' => [
                'workspace_public_id' => $workspace->public_id,
                'client_company_public_id' => $company->public_id,
            ],
        ], $options)->toArray());

        return $this->requiredString($intent['client_secret'] ?? null, 'setup intent');
    }

    /**
     * @return array{status: string, customer: string|null, payment_method: string|null}
     */
    public function setupIntent(Workspace $workspace, string $setupIntentId): array
    {
        $intent = $this->call($workspace, 'setup intent', fn (StripeClient $client): array => $client->setupIntents->retrieve($setupIntentId, [])->toArray());
        $customer = $intent['customer'] ?? null;
        $method = $intent['payment_method'] ?? null;

        return [
            'status' => is_string($intent['status'] ?? null) ? $intent['status'] : '',
            'customer' => is_string($customer) && $customer !== '' ? $customer : null,
            'payment_method' => is_string($method) && $method !== '' ? $method : null,
        ];
    }

    /** @return array<string, mixed> */
    public function paymentMethod(Workspace $workspace, string $paymentMethodId): array
    {
        return $this->call($workspace, 'payment method', fn (StripeClient $client): array => $client->paymentMethods->retrieve($paymentMethodId, [])->toArray());
    }

    public function makeDefault(Workspace $workspace, string $customerId, string $paymentMethodId): void
    {
        $this->call($workspace, 'default payment method', fn (StripeClient $client): array => $client->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ])->toArray());
    }

    /** Detach a method. One Stripe already reports as not attached is detached. */
    public function detach(Workspace $workspace, string $paymentMethodId): void
    {
        try {
            $this->call($workspace, 'payment method', fn (StripeClient $client): array => $client->paymentMethods->detach($paymentMethodId, [])->toArray());
        } catch (StripeRefusal $refusal) {
            if (! $refusal->alreadyGone) {
                throw $refusal;
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(StripeClient): T  $call
     * @return T
     */
    private function call(Workspace $workspace, string $what, callable $call): mixed
    {
        try {
            return $call($this->stripe->client($workspace));
        } catch (ApiErrorException $failure) {
            Log::warning('Stripe refused a saved payment method call.', [
                'what' => $what,
                'workspace' => $workspace->public_id,
                'code' => $failure->getStripeCode(),
                'status' => $failure->getHttpStatus(),
            ]);
            $gone = $failure->getHttpStatus() === 404
                || $failure->getStripeCode() === 'resource_missing'
                || str_contains(strtolower($failure->getMessage()), 'not attached');

            throw new StripeRefusal('Stripe could not complete that. Try again in a moment.', $gone, $failure);
        }
    }

    private function requiredString(mixed $value, string $what): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException('Stripe returned an invalid '.$what.'.');
        }

        return $value;
    }
}
