<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrevoFoundationTest extends TestCase
{
    public function test_status_command_reports_missing_configuration_without_values(): void
    {
        config([
            'mail.default' => 'hybrid',
            'services.brevo.dsn' => null,
            'services.brevo.webhook_token' => null,
        ]);

        $this->artisan('svc:brevo:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"brevo","configured":false,"mailer_uses_brevo":true,"transport_configured":false,"webhook_token_present":false}')
            ->assertExitCode(1);
    }

    public function test_status_command_reports_complete_configuration_without_disclosing_secrets(): void
    {
        $dsn = 'brevo+api://'.implode('-', ['synthetic', 'api', 'key']).'@default';
        $token = implode('-', ['synthetic', 'webhook', 'token']);
        config([
            'mail.default' => 'hybrid',
            'services.brevo.dsn' => $dsn,
            'services.brevo.webhook_token' => $token,
        ]);

        $this->artisan('svc:brevo:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"brevo","configured":true,"mailer_uses_brevo":true,"transport_configured":true,"webhook_token_present":true}')
            ->doesntExpectOutputToContain($dsn)
            ->doesntExpectOutputToContain($token)
            ->assertSuccessful();
    }

    public function test_status_command_rejects_a_non_brevo_dsn(): void
    {
        config([
            'mail.default' => 'hybrid',
            'services.brevo.dsn' => 'smtp://synthetic.test',
            'services.brevo.webhook_token' => 'synthetic-webhook-token',
        ]);

        $this->artisan('svc:brevo:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"brevo","configured":false,"mailer_uses_brevo":true,"transport_configured":false,"webhook_token_present":true}')
            ->assertExitCode(1);
    }

    public function test_status_command_rejects_a_malformed_dsn(): void
    {
        config([
            'mail.default' => 'hybrid',
            'services.brevo.dsn' => 'not a dsn',
            'services.brevo.webhook_token' => 'synthetic-webhook-token',
        ]);

        $this->artisan('svc:brevo:status', ['--format' => 'json'])
            ->expectsOutput('{"provider":"brevo","configured":false,"mailer_uses_brevo":true,"transport_configured":false,"webhook_token_present":true}')
            ->assertExitCode(1);
    }

    public function test_status_command_refuses_an_unknown_format(): void
    {
        $this->artisan('svc:brevo:status', ['--format' => 'yaml'])
            ->expectsOutput('The --format option must be text or json.')
            ->assertExitCode(2);
    }
}
