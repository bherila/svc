<?php

namespace Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Synthetic in-process transport: never opens a network connection. */
final class RecordingMailTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public bool $fail = false;

    public bool $returnNull = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->messages[] = $message;
        if ($this->fail) {
            throw new TransportException('Synthetic accepted then response lost; private detail');
        }

        return $this->returnNull ? null : new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'synthetic://mail';
    }
}
