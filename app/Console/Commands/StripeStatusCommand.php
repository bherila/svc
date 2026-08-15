<?php

namespace App\Console\Commands;

use App\Services\Billing\StripeGateway;
use Illuminate\Console\Command;

class StripeStatusCommand extends Command
{
    protected $signature = 'svc:stripe:status {--format=text : Output text or json}';

    protected $description = 'Report whether the Stripe adapter is configured without exposing credentials';

    public function handle(StripeGateway $gateway): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

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
