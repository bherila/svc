<?php

namespace App\Services\Billing;

use RuntimeException;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

final class StripeGateway
{
    public function isConfigured(): bool
    {
        return $this->publishableKey() !== null
            && $this->secretKey() !== null
            && $this->webhookSecret() !== null;
    }

    public function mode(): ?string
    {
        $secret = $this->secretKey();

        if ($secret === null) {
            return null;
        }

        return match (true) {
            str_starts_with($secret, 'sk_test_') => 'test',
            str_starts_with($secret, 'sk_live_') => 'live',
            default => 'unknown',
        };
    }

    public function publishableKey(): ?string
    {
        return $this->configuredString('publishable_key');
    }

    public function client(): StripeClient
    {
        $secret = $this->secretKey();

        if ($secret === null) {
            throw new RuntimeException('Stripe is not configured.');
        }

        return new StripeClient($secret);
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
