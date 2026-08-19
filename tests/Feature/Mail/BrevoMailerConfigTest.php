<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Tests\TestCase;

class BrevoMailerConfigTest extends TestCase
{
    public function test_brevo_transport_resolves(): void
    {
        config(['services.brevo.dsn' => 'brevo+api://xkeysib-test-key@default']);

        $transport = Mail::mailer('brevo')->getSymfonyTransport();

        $this->assertInstanceOf(BrevoApiTransport::class, $transport);
    }

    public function test_hybrid_mailer_is_a_failover_of_brevo_then_smtp(): void
    {
        config(['services.brevo.dsn' => 'brevo+api://xkeysib-test-key@default']);

        $this->assertInstanceOf(
            FailoverTransport::class,
            Mail::mailer('hybrid')->getSymfonyTransport(),
        );

        $this->assertSame(['brevo', 'smtp'], config('mail.mailers.hybrid.mailers'));
    }
}
