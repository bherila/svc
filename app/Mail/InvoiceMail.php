<?php

namespace App\Mail;

use App\Models\ClientInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An invoice, on its way to the client.
 *
 * The subject and the covering note are passed in rather than composed here.
 * The operator writes them on the compose screen and they are stored on the
 * delivery row, so what the client read and what the record says are the same
 * text - which is the point of storing it at all.
 *
 * Both fall back to the template's own words. A caller that supplies neither
 * sends exactly what this used to send.
 */
final class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ClientInvoice $invoice,
        public readonly ?string $subjectLine,
        public readonly ?string $note,
        private readonly string $invoicePdf,
        private readonly string $invoicePdfName,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->subjectLine === null ? '' : trim($this->subjectLine);

        return new Envelope(
            subject: $subject === '' ? 'Invoice '.$this->invoice->invoice_number : $subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'invoices.email', with: [
            'invoice' => $this->invoice->load('clientCompany'),
            'note' => $this->note,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => $this->invoicePdf,
                $this->invoicePdfName,
            )->withMime('application/pdf'),
        ];
    }
}
