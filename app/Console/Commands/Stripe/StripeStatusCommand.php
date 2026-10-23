<?php

namespace App\Console\Commands\Stripe;

use App\Console\Commands\SvcCommand;
use App\Services\Billing\StripeGateway;

class StripeStatusCommand extends SvcCommand
{
    protected $signature = 'svc:stripe:status {--format=text : Output text or json}';

    protected $description = 'Report whether the Stripe adapter is configured without exposing credentials';

    public function handle(StripeGateway $gateway): int
    {
        $format = (string) $this->option('format');

        $status = [
            'provider' => 'stripe',
            'configured' => $gateway->isConfigured(),
            'mode' => $gateway->mode(),
            'publishable_key_present' => $gateway->publishableKey() !== null,
            'secret_key_present' => $this->configuredValueIsPresent('secret_key'),
            'webhook_secret_present' => $this->configuredValueIsPresent('webhook_secret'),
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Provider', 'Stripe');
            $this->components->twoColumnDetail('Configured', $status['configured'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Mode', $status['mode'] ?? 'not configured');
        }

        return $status['configured'] ? self::SUCCESS : self::FAILURE;
    }

    private function configuredValueIsPresent(string $key): bool
    {
        $value = config("services.stripe.{$key}");

        return is_string($value) && $value !== '';
    }
}
