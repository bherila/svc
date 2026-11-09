<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\StripeAccess;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The Stripe adapter. Which key a call is made with comes from the workspace's
 * own connection ({@see PaymentConnectionResolver}); the deployment's
 * environment key serves only until the migration command has run.
 */
final class StripeGateway
{
    public function __construct(private readonly PaymentConnectionResolver $connections) {}

    public function isConfigured(): bool
    {
        return $this->publishableKey() !== null
            && $this->secretKey() !== null
            && $this->webhookSecret() !== null;
    }

    /**
     * `test`, `live`, `unknown`, or null when there are no credentials. With a
     * workspace, the mode of that workspace's own key; without, the
     * environment key's.
     */
    public function mode(?Workspace $workspace = null): ?string
    {
        if ($workspace !== null) {
            return $this->connections->stripe($workspace)?->mode;
        }
        $secret = $this->secretKey();

        return $secret === null ? null : StripeAccess::modeOf($secret);
    }

    /** The credentials this workspace's Stripe calls use, or null when it has none. */
    public function access(Workspace $workspace): ?StripeAccess
    {
        return $this->connections->stripe($workspace);
    }

    public function publishableKey(): ?string
    {
        return $this->configuredString('publishable_key');
    }

    /** A client with the workspace's own key, and no other. */
    public function client(Workspace $workspace): StripeClient
    {
        $access = $this->access($workspace);

        if ($access === null) {
            throw new RuntimeException('Stripe is not connected for this workspace.');
        }

        return $access->client();
    }

    /**
     * The currencies this workspace's Stripe account can charge, as upper-case
     * ISO 4217 codes, or null when it has no connection or Stripe could not be
     * asked (EDGE-5).
     *
     * Two reads: the account's country and default currency, then that
     * country's specification. A provider call, so callers make it outside any
     * database transaction. Nothing is cached: the answer is the account's
     * now, and a failure is logged with Stripe's code and nothing else.
     *
     * @return list<string>|null
     */
    public function chargeableCurrencies(Workspace $workspace): ?array
    {
        $access = $this->access($workspace);
        if ($access === null) {
            return null;
        }

        try {
            $client = $access->client();
            $account = $client->accounts->retrieve(null, [])->toArray();
            $country = is_string($account['country'] ?? null) ? $account['country'] : '';
            if ($country === '') {
                return null;
            }
            $spec = $client->countrySpecs->retrieve($country, [])->toArray();
        } catch (ApiErrorException $failure) {
            Log::warning('Stripe could not be asked which currencies the account can charge.', ['code' => $failure->getStripeCode()]);

            return null;
        } catch (RuntimeException) {
            Log::warning('Stripe could not be asked which currencies the account can charge.');

            return null;
        }

        $supported = is_array($spec['supported_payment_currencies'] ?? null) ? $spec['supported_payment_currencies'] : [];
        if (is_string($account['default_currency'] ?? null)) {
            $supported[] = $account['default_currency'];
        }
        $codes = array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper($code),
            array_filter($supported, static fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Za-z]{3}$/D', $code) === 1),
        )));
        sort($codes);

        return $codes === [] ? null : $codes;
    }

    /**
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public function constructWebhookEvent(string $payload, ?string $signature): Event
    {
        $secret = $this->webhookSecret();

        if ($secret === null) {
            throw new UnexpectedValueException('The Stripe webhook secret is not configured.');
        }

        return $this->constructWebhookEventWith($payload, $signature, $secret);
    }

    /**
     * Verify a delivery against the signing secret of the endpoint it arrived
     * at: a workspace's own secret, never one chosen from the payload.
     *
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public function constructWebhookEventWith(string $payload, ?string $signature, #[\SensitiveParameter] string $secret): Event
    {
        if ($signature === null || $signature === '') {
            throw SignatureVerificationException::factory('Missing Stripe signature.', $payload, null);
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    private function secretKey(): ?string
    {
        return $this->configuredString('secret_key');
    }

    private function webhookSecret(): ?string
    {
        return $this->configuredString('webhook_secret');
    }

    private function configuredString(string $key): ?string
    {
        $value = config("services.stripe.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
