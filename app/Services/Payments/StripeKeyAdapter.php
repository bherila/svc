<?php

namespace App\Services\Payments;

use App\Support\Payments\PaymentProcessor;
use Stripe\Exception\ExceptionInterface;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe through a restricted secret key the workspace's owner pasted in. The
 * key is checked with a read of the account, and svc creates the workspace's
 * own webhook endpoint through the API so the owner never copies a signing
 * secret.
 */
final class StripeKeyAdapter implements PaymentConnectionAdapter
{
    /** The events the webhook service handles. */
    public const array EVENTS = [
        'payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed', 'payment_intent.canceled',
        'charge.refunded', 'charge.refund.updated', 'refund.updated', 'refund.failed',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
        'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated',
        'payout.paid', 'payout.failed', 'payout.canceled',
        'payment_method.attached', 'payment_method.detached', 'setup_intent.succeeded', 'customer.updated',
    ];

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
        try {
            $account = (new StripeClient($secret))->accounts->retrieve()->toArray();
        } catch (ExceptionInterface) {
            throw new PaymentCredentialsRejected('Stripe did not accept this key. Check that it is correct and can read the account.');
        }
        $id = $account['id'] ?? null;
        if (! is_string($id) || preg_match('/^acct_[A-Za-z0-9_]{1,200}$/D', $id) !== 1) {
            throw new PaymentCredentialsRejected('Stripe did not identify the account for this key.');
        }
        $name = $account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? null;

        return new VerifiedAccount($id, is_string($name) && $name !== '' ? $name : null, $environment);
    }

    public function registerWebhook(PaymentCredentials $credentials, string $url): RegisteredWebhook
    {
        try {
            $endpoint = (new StripeClient($credentials->secret()))->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => self::EVENTS,
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
