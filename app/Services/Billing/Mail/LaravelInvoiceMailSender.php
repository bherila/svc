<?php

namespace App\Services\Billing\Mail;

use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceMailSendResult;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class LaravelInvoiceMailSender implements InvoiceMailSender
{
    public function send(Mailable $message, InvoiceEmailDraft $draft): InvoiceMailSendResult
    {
        $handoff = new SymfonyMailHandoff;
        $opaqueHandoff = false;

        try {
            $factory = Mail::getFacadeRoot();
            if (! $factory instanceof Factory) {
                throw new RuntimeException('The mail factory is unavailable.');
            }
            $configured = $factory->mailer($message->mailer);
            if ($configured instanceof Mailer) {
                // Isolate this attempt: never replace the shared default mailer's
                // transport, including during nested sends or after exceptions.
                $mailer = clone $configured;
                $mailer->setSymfonyTransport(new TrackedMailTransport($mailer->getSymfonyTransport(), $handoff));
                $message->withSymfonyMessage($handoff->bind(...));
            } else {
                // An opaque replacement (for example a recording mailer) owns
                // its own boundary. An exception once invoked is conservative
                // unknown; a custom adapter can report a more precise phase.
                $mailer = $configured;
            }
            $pending = (new PendingMail($mailer))->to($draft->recipients)->bcc($draft->bcc);
            $opaqueHandoff = ! $configured instanceof Mailer;
            $sent = $pending->send($message);

            if ($sent === null && ! $opaqueHandoff) {
                throw new RuntimeException('The mailer returned no transport acceptance.');
            }

            return InvoiceMailSendResult::accepted($sent?->getMessageId());
        } catch (Throwable $failure) {
            return InvoiceMailSendResult::failed($failure, $handoff->hasStarted() || $opaqueHandoff);
        }
    }
}
