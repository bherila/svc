<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The immutable issued revision sent to the responsible administrator. */
final class AdministratorInvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $clientName,
        public readonly string $invoiceNumber,
        public readonly string $currency,
        public readonly int $totalAmount,
        public readonly string $openUrl,
        private readonly string $invoicePdf,
        private readonly string $invoicePdfName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'invoices.administrator-issued-email');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->invoicePdf, $this->invoicePdfName)
                ->withMime('application/pdf'),
        ];
    }
}
