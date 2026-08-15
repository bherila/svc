<?php

namespace App\Mail;

use App\Models\ClientInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ClientInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invoice '.$this->invoice->invoice_number);
    }

    public function content(): Content
    {
        return new Content(view: 'invoices.email', with: ['invoice' => $this->invoice->load(['lines', 'clientCompany'])]);
    }
}
