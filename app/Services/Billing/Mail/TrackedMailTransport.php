<?php

namespace App\Services\Billing\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Marks delegated transport entry after local message and envelope validation. */
final readonly class TrackedMailTransport implements TransportInterface
{
    public function __construct(private TransportInterface $transport, private SymfonyMailHandoff $handoff) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $message->ensureValidity();
        $envelope ??= Envelope::create($message);
        // Symfony's Envelope::create returns a lazy DelayedEnvelope. Force
        // sender/recipient resolution before claiming a transport handoff.
        $envelope = new Envelope($envelope->getSender(), $envelope->getRecipients());
        $this->handoff->enter($message);

        return $this->transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }
}
