<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every service-package email: a heading, a few short paragraphs and at most
 * one link. One message shape for requests, decisions, activations and
 * cancellations, so their wording is chosen by PackageNotifier and their look
 * by one template. Sent in the request, after its commit; nothing is queued.
 */
final class ServicePackageNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<string> $paragraphs plain text; escaped by the template */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly array $paragraphs,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'packages.notice-email', with: [
            'heading' => $this->heading,
            'paragraphs' => $this->paragraphs,
            'actionUrl' => $this->actionUrl,
            'actionLabel' => $this->actionLabel,
        ]);
    }
}
