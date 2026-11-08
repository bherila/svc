<?php

namespace App\Services\Payments;

use App\Services\Billing\StripeWebhookService;
use App\Support\Payments\PaymentProcessor;
use App\Support\Payments\StripeAccountCheck;
use App\Support\Payments\StripePublishableKey;
use App\Support\Payments\StripeWebhookHealthDecision;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe through a restricted secret key the workspace's owner pasted in. The
 * key is checked with a read of the account; a publishable key entered beside
 * it is checked for mode and then against the account itself
 * ({@see StripeAccountCheck}); and svc creates the workspace's
 * own webhook endpoint through the API so the owner never copies a signing
 * secret.
 */
final class StripeKeyAdapter implements PaymentConnectionAdapter
{
    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Stripe;
    }

    public function verify(PaymentCredentials $credentials): VerifiedAccount
    {
        $secret = $credentials->secret();
        $environment = match (true) {
            str_starts_with($secret, 'rk_live_'), str_starts_with($secret, 'sk_live_') => 'live',
            str_starts_with($secret, 'rk_test_'), str_starts_with($secret, 'sk_test_') => 'test',
            default => throw new PaymentCredentialsRejected('Enter a Stripe secret or restricted key (it starts with rk_ or sk_).'),
        };
        // The mode first, before Stripe is called: it needs no network.
        if ($credentials->publishableKey !== null) {
            $refusal = StripePublishableKey::rejection($credentials->publishableKey, $environment);
            if ($refusal !== null) {
                throw new PaymentCredentialsRejected($refusal, 'publishable_key');
            }
        }
        try {
            $account = (new StripeClient($secret))->accounts->retrieve()->toArray();
        } catch (ExceptionInterface) {
            throw new PaymentCredentialsRejected('Stripe did not accept this key. Check that it is correct and can read the account.');
        }
        $id = $account['id'] ?? null;
        if (! is_string($id) || preg_match('/^acct_[A-Za-z0-9_]{1,200}$/D', $id) !== 1) {
            throw new PaymentCredentialsRejected('Stripe did not identify the account for this key.');
        }
        // The account behind a publishable key cannot be read from the key, so
        // Stripe is asked whether it can see something only this account holds.
        if ($credentials->publishableKey !== null) {
            $this->assertPublishableKeyBelongs($secret, $credentials->publishableKey);
        }
        $name = $account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? null;

        return new VerifiedAccount($id, is_string($name) && $name !== '' ? $name : null, $environment);
    }

    /**
     * Prove the publishable key belongs to the restricted key's account: the
     * restricted key creates a SetupIntent (no customer, no payment method),
     * the publishable key reads it back with its client secret - which works
     * only inside one account - and the restricted key cancels it again,
     * whatever happened, so a failed check leaves nothing open.
     *
     * @throws PaymentCredentialsRejected
     */
    private function assertPublishableKeyBelongs(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $publishableKey): void
    {
        $restricted = new StripeClient($secret);
        try {
            $intent = $restricted->setupIntents->create(['usage' => 'off_session']);
        } catch (ExceptionInterface $failure) {
            throw $this->checkRefused(StripeAccountCheck::createRefusal(...$this->failureOf($failure)));
        }
        $id = is_string($intent->id ?? null) ? $intent->id : '';
        $clientSecret = $intent->client_secret ?? null;

        try {
            if (! is_string($clientSecret) || $clientSecret === '') {
                throw $this->checkRefused(['field' => 'publishable_key', 'message' => StripeAccountCheck::UNVERIFIABLE]);
            }
            try {
                $found = (new StripeClient($publishableKey))->setupIntents->retrieve($id, ['client_secret' => $clientSecret]);
                $outcome = [200, null, is_string($found->id ?? null) ? $found->id : null];
            } catch (ExceptionInterface $failure) {
                $outcome = [...$this->failureOf($failure, code: true), null];
            }
            $refusal = StripeAccountCheck::retrieveRefusal($id, $outcome[0], $outcome[1], $outcome[2]);
            if ($refusal !== null) {
                throw $this->checkRefused($refusal);
            }
        } finally {
            $this->cancelCheck($restricted, $id);
        }
    }

    /**
     * What an SDK failure tells the decision: Stripe's status and error type
     * (or, with `$code`, its error code). Null status when it never answered.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function failureOf(ExceptionInterface $failure, bool $code = false): array
    {
        if (! $failure instanceof ApiErrorException) {
            return [null, null];
        }

        return [$failure->getHttpStatus(), $code ? $failure->getStripeCode() : $failure->getError()?->type];
    }

    /** @param  array{field: string, message: string}  $refusal */
    private function checkRefused(array $refusal): PaymentCredentialsRejected
    {
        return new PaymentCredentialsRejected($refusal['message'], $refusal['field']);
    }

    private function cancelCheck(StripeClient $restricted, string $id): void
    {
        if ($id === '') {
            return;
        }
        try {
            $restricted->setupIntents->cancel($id);
        } catch (Throwable) {
            // Unreachable here means it stays open with no customer and no
            // payment method, which charges and collects nothing.
            Log::warning('A Stripe SetupIntent made to check a publishable key could not be cancelled.', ['setup_intent' => $id]);
        }
    }

    public function registerWebhook(PaymentCredentials $credentials, string $url): RegisteredWebhook
    {
        try {
            $endpoint = (new StripeClient($credentials->secret()))->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => StripeWebhookService::EVENTS,
                'description' => 'SVC workspace payments',
            ]);
        } catch (ExceptionInterface) {
            throw new PaymentCredentialsRejected('Stripe would not create the webhook endpoint. The key needs permission to write webhook endpoints.');
        }
        $secret = $endpoint->secret ?? null;
        if (! is_string($endpoint->id ?? null) || ! is_string($secret) || $secret === '') {
            throw new PaymentCredentialsRejected('Stripe did not return a webhook signing secret.');
        }

        return new RegisteredWebhook($endpoint->id, $secret);
    }

    /**
     * Every webhook endpoint of the key's account, as the health check reads
     * them. One page of 100: Stripe allows an account far fewer endpoints.
     *
     * @return list<array{id: string, url: string, status: string, enabled_events: list<string>, livemode: bool}>
     *
     * @throws WebhookCallFailed
     */
    public function webhookEndpoints(#[SensitiveParameter] string $secret): array
    {
        try {
            $list = (new StripeClient($secret))->webhookEndpoints->all(['limit' => 100]);
        } catch (ExceptionInterface $failure) {
            throw $this->webhookFailure($failure);
        }

        $endpoints = [];
        foreach ($list->data as $endpoint) {
            $events = $endpoint->enabled_events ?? null;
            if (! is_string($endpoint->id ?? null) || ! is_string($endpoint->url ?? null) || ! is_iterable($events)) {
                continue;
            }
            $endpoints[] = [
                'id' => $endpoint->id,
                'url' => $endpoint->url,
                'status' => is_string($endpoint->status ?? null) ? $endpoint->status : 'disabled',
                'enabled_events' => array_values(array_filter([...$events], is_string(...))),
                'livemode' => (bool) ($endpoint->livemode ?? false),
            ];
        }

        return $endpoints;
    }

    /**
     * Set one endpoint's subscriptions. The caller passes the union of what it
     * has and what it needs; this never removes an event by itself.
     *
     * @param  list<string>  $events
     *
     * @throws WebhookCallFailed
     */
    public function setWebhookEvents(#[SensitiveParameter] string $secret, string $endpointId, array $events): void
    {
        try {
            (new StripeClient($secret))->webhookEndpoints->update($endpointId, ['enabled_events' => $events]);
        } catch (ExceptionInterface $failure) {
            throw $this->webhookFailure($failure);
        }
    }

    private function webhookFailure(ExceptionInterface $failure): WebhookCallFailed
    {
        [$status, $type] = $this->failureOf($failure);

        return new WebhookCallFailed(StripeWebhookHealthDecision::failure($status, $type));
    }

    public function removeWebhook(PaymentCredentials $credentials, string $endpointId): void
    {
        try {
            (new StripeClient($credentials->secret()))->webhookEndpoints->delete($endpointId);
        } catch (Throwable) {
            // The local change already stands; a stale endpoint only receives
            // deliveries that the router refuses.
        }
    }
}
