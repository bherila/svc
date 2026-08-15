<?php

namespace Tests\Feature;

use App\Services\Billing\StripeGateway;
use Tests\TestCase;

class StripeFoundationTest extends TestCase
{
    public function test_status_command_reports_missing_configuration_without_values(): void
    {
        config(['services.stripe' => [
            'publishable_key' => null,
            'secret_key' => null,
            'webhook_secret' => null,
        ]]);

        $this->artisan('svc:stripe:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"stripe","configured":false,"mode":null,"publishable_key_present":false,"secret_key_present":false,"webhook_secret_present":false}')
            ->assertExitCode(1);
    }

    public function test_status_command_reports_test_configuration_without_disclosing_keys(): void
    {
        $secretKey = implode('_', ['sk', 'test', 'synthetic-secret-value']);
        $webhookSecret = implode('_', ['whsec', 'synthetic-webhook-value']);

        config(['services.stripe' => [
            'publishable_key' => implode('_', ['pk', 'test', 'synthetic-publishable-value']),
            'secret_key' => $secretKey,
            'webhook_secret' => $webhookSecret,
        ]]);

        $this->artisan('svc:stripe:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"stripe","configured":true,"mode":"test","publishable_key_present":true,"secret_key_present":true,"webhook_secret_present":true}')
            ->doesntExpectOutputToContain($secretKey)
            ->doesntExpectOutputToContain($webhookSecret)
            ->assertSuccessful();
    }

    public function test_gateway_verifies_a_signed_webhook_payload(): void
    {
        $webhookSecret = implode('_', ['whsec', 'synthetic-webhook-value']);
        config(['services.stripe.webhook_secret' => $webhookSecret]);

        $payload = json_encode([
            'id' => 'evt_synthetic',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'synthetic-payment']],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', $timestamp.'.'.$payload, $webhookSecret),
        );

        $event = app(StripeGateway::class)->constructWebhookEvent($payload, $signature);

        $this->assertSame('evt_synthetic', $event->id);
        $this->assertSame('payment_intent.succeeded', $event->type);
    }
}
