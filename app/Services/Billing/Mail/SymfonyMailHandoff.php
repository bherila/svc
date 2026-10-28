<?php

namespace App\Services\Billing\Mail;

use Symfony\Component\Mime\RawMessage;

/** Attempt-local observation; unrelated nested messages cannot advance it. */
final class SymfonyMailHandoff
{
    private ?RawMessage $message = null;

    private bool $started = false;

    public function bind(RawMessage $message): void
    {
        $this->message = $message;
    }

    public function enter(RawMessage $message): void
    {
        if ($message === $this->message) {
            $this->started = true;
        }
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }
}
