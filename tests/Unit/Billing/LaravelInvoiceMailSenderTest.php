<?php

namespace Tests\Unit\Billing;

use App\Providers\AppServiceProvider;
use App\Services\Billing\Mail\InvoiceMailSender;
use App\Services\Billing\Mail\LaravelInvoiceMailSender;
use App\Services\Billing\Mail\SymfonyMailHandoff;
use App\Services\Billing\Mail\TrackedMailTransport;
use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceMailSendResult;
use Illuminate\Container\Container;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Support\RecordingMailTransport;
use Tests\TestCase;

#[CoversClass(LaravelInvoiceMailSender::class)]
#[CoversClass(SymfonyMailHandoff::class)]
#[CoversClass(TrackedMailTransport::class)]
#[CoversClass(InvoiceMailSendResult::class)]
#[CoversClass(AppServiceProvider::class)]
final class LaravelInvoiceMailSenderTest extends TestCase
{
    public function test_application_registration_supplies_the_replaceable_mail_boundary_in_a_fresh_container(): void
    {
        // The booted test application has already registered its services before
        // test coverage begins. Check composition without inherited bindings.
        $previous = Container::getInstance();
        try {
            $application = new Application($this->app->basePath());
            (new AppServiceProvider($application))->register();

            $this->assertInstanceOf(LaravelInvoiceMailSender::class, $application->make(InvoiceMailSender::class));
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_real_mailer_acceptance_preserves_addresses_and_the_shared_default_transport(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $sender = $this->app->make(InvoiceMailSender::class);
        $this->assertInstanceOf(LaravelInvoiceMailSender::class, $sender);
        $result = $sender->send((new Mailable)->html('<p>Synthetic body</p>'), $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertNull($result->failure);
        $this->assertNotEmpty($result->providerMessageId);
        $this->assertCount(1, $transport->messages);
        $email = $transport->messages[0];
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('billing@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('operator@example.test', $email->getBcc()[0]->getAddress());
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_view_resolution_failure_is_before_transport_entry(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->view('synthetic-missing-mail-view'), $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertNotNull($result->failure);
        $this->assertNull($result->providerMessageId);
        $this->assertSame([], $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_missing_sender_envelope_is_preparation_even_after_laravel_builds_the_message(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $mailer->alwaysFrom(null);
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertNotNull($result->failure);
        $this->assertSame([], $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_transport_throwable_is_unknown_and_does_not_replace_the_default_transport(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $transport->fail = true;
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertInstanceOf(TransportException::class, $result->failure);
        $this->assertNull($result->providerMessageId);
        $this->assertCount(1, $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_invalid_draft_message_is_rejected_before_transport_entry(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $message = (new Mailable)->html('Synthetic body')->withSymfonyMessage(
            static fn (Email $email) => $email->getHeaders()->addTextHeader('X-Unsent', '1'),
        );
        $result = (new LaravelInvoiceMailSender)->send($message, $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertNotNull($result->failure);
        $this->assertSame([], $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_invalid_lazy_envelope_sender_is_preparation_failure(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $mailer->alwaysFrom('séndér@example.test');
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertNotNull($result->failure);
        $this->assertSame([], $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_nested_message_during_preparation_cannot_mark_the_invoice_as_handed_off(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $failure = new RuntimeException('Synthetic outer preparation failed');
        Event::listen(MessageSending::class, static function (MessageSending $event) use ($failure): void {
            if ($event->message->getSubject() !== 'Outer invoice') {
                return;
            }
            Mail::to('operator@example.test')->send((new Mailable)->subject('Nested notice')->html('Synthetic notice'));

            throw $failure;
        });
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->subject('Outer invoice')->html('Synthetic body'), $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertSame($failure, $result->failure);
        $this->assertCount(1, $transport->messages);
        $this->assertInstanceOf(Email::class, $transport->messages[0]);
        $this->assertSame('Nested notice', $transport->messages[0]->getSubject());
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_exception_after_acceptance_keeps_the_observed_handoff(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $failure = new RuntimeException('Synthetic private post-acceptance listener detail');
        Event::listen(MessageSent::class, static fn () => throw $failure);
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertSame($failure, $result->failure);
        $this->assertNull($result->providerMessageId);
        $this->assertCount(1, $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_laravel_veto_without_transport_entry_is_known_unsent(): void
    {
        [$mailer, $transport] = $this->realMailer();
        Event::listen(MessageSending::class, static fn (): bool => false);
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertInstanceOf(RuntimeException::class, $result->failure);
        $this->assertSame([], $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_transport_without_acceptance_is_unresolved(): void
    {
        [$mailer, $transport] = $this->realMailer();
        $transport->returnNull = true;
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertInstanceOf(RuntimeException::class, $result->failure);
        $this->assertCount(1, $transport->messages);
        $this->assertSame($transport, $mailer->getSymfonyTransport());
    }

    public function test_only_the_exact_bound_email_can_mark_handoff(): void
    {
        $handoff = new SymfonyMailHandoff;
        $transport = new RecordingMailTransport;
        $decorator = new TrackedMailTransport($transport, $handoff);
        $email = (new Email)->from('sender@example.test')->to('billing@example.test')->text('Synthetic body');
        $this->assertFalse($handoff->hasStarted());
        $decorator->send(clone $email);
        $this->assertFalse($handoff->hasStarted());
        $handoff->bind($email);
        $decorator->send(clone $email);
        $this->assertFalse($handoff->hasStarted());
        $decorator->send($email);
        $this->assertTrue($handoff->hasStarted());
        $this->assertCount(3, $transport->messages);
        $this->assertSame($email, $transport->messages[2]);
        $this->assertSame('synthetic://mail', (string) $decorator);
    }

    public function test_explicit_transport_envelope_keeps_bounce_sender_and_routing_recipients(): void
    {
        $handoff = new SymfonyMailHandoff;
        $transport = new RecordingMailTransport;
        $decorator = new TrackedMailTransport($transport, $handoff);
        $email = (new Email)->from('sender@example.test')->to('billing@example.test')->text('Synthetic body');
        $handoff->bind($email);
        $sent = $decorator->send($email, new Envelope(new Address('bounce@example.test'), [new Address('routed@example.test')]));

        $this->assertNotNull($sent);
        $this->assertTrue($handoff->hasStarted());
        $this->assertSame('bounce@example.test', $sent->getEnvelope()->getSender()->getAddress());
        $this->assertSame(['routed@example.test'], array_map(static fn (Address $address): string => $address->getAddress(), $sent->getEnvelope()->getRecipients()));
        $this->assertSame('billing@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame([$email], $transport->messages);
    }

    public function test_recording_replacement_can_accept_without_a_provider_id(): void
    {
        Mail::fake();
        $result = (new LaravelInvoiceMailSender)->send((new Mailable)->html('Synthetic body'), $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertNull($result->providerMessageId);
        $this->assertNull($result->failure);
        Mail::assertSent(Mailable::class, 1);
    }

    public function test_opaque_replacement_exception_cannot_claim_known_unsent(): void
    {
        $failure = new RuntimeException('Synthetic opaque sender detail');
        $mailer = \Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->once()->andThrow($failure);
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('mailer')->once()->with(null)->andReturn($mailer);
        Mail::swap($factory);
        $result = (new LaravelInvoiceMailSender)->send(new Mailable, $this->draft());
        $this->assertTrue($result->handoffStarted);
        $this->assertSame($failure, $result->failure);
        $this->assertNull($result->providerMessageId);
    }

    public function test_absent_mail_factory_is_preparation_failure(): void
    {
        Mail::swap(new \stdClass);
        $result = (new LaravelInvoiceMailSender)->send(new Mailable, $this->draft());
        $this->assertFalse($result->handoffStarted);
        $this->assertInstanceOf(RuntimeException::class, $result->failure);
        $this->assertNull($result->providerMessageId);
    }

    /** @return array{Mailer, RecordingMailTransport} */
    private function realMailer(): array
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test']);
        $manager = new MailManager($this->app);
        Mail::swap($manager);
        $mailer = $manager->mailer();
        $this->assertInstanceOf(Mailer::class, $mailer);
        $transport = new RecordingMailTransport;
        $mailer->setSymfonyTransport($transport);

        return [$mailer, $transport];
    }

    private function draft(): InvoiceEmailDraft
    {
        return InvoiceEmailDraft::of(['billing@example.test'], ['operator@example.test'], 'Synthetic invoice', null);
    }
}
