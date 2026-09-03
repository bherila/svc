<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BrevoStatusCommand extends Command
{
    protected $signature = 'svc:brevo:status {--format=text : Output text or json}';

    protected $description = 'Report whether Brevo mail and webhook adapters are configured without exposing credentials';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $mailer = config('mail.default');
        $mailerUsesBrevo = is_string($mailer) && in_array($mailer, ['brevo', 'hybrid'], true);
        $transportConfigured = $this->configuredValueIsPresent('dsn');
        $webhookTokenPresent = $this->configuredValueIsPresent('webhook_token');
        $configured = $mailerUsesBrevo && $transportConfigured && $webhookTokenPresent;

        $status = [
            'provider' => 'brevo',
            'configured' => $configured,
            'mailer_uses_brevo' => $mailerUsesBrevo,
            'transport_configured' => $transportConfigured,
            'webhook_token_present' => $webhookTokenPresent,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Provider', 'Brevo');
            $this->components->twoColumnDetail('Configured', $configured ? 'yes' : 'no');
            $this->components->twoColumnDetail('Mailer uses Brevo', $mailerUsesBrevo ? 'yes' : 'no');
        }

        return $configured ? self::SUCCESS : self::FAILURE;
    }

    private function configuredValueIsPresent(string $key): bool
    {
        $value = config("services.brevo.{$key}");

        return is_string($value) && trim($value) !== '';
    }
}
