<?php

namespace App\Mail;

use App\Models\ClientInvoice;
use App\Support\Billing\Reminders\PaymentReminderPayLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A payment reminder for an open invoice (#438), from SVC's usual invoice
 * sender address.
 *
 * It carries one way to pay or read the invoice, decided by the reminder
 * policy: Square's own payment page for an invoice Square collects, the client
 * portal where the client can sign in to read it, or neither - and then the
 * invoice PDF, so the reminder is never a demand with nothing to pay against.
 * Built only by `PaymentReminderService::deliver()`.
 */
final class InvoicePaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array{0: string, 1: string}|null  $pdf  bytes and file name, for a reminder with no link */
    public function __construct(
        public readonly ClientInvoice $invoice,
        public readonly string $subjectLine,
        public readonly string $companyName,
        public readonly PaymentReminderPayLink $payLink,
        public readonly ?string $payUrl,
        public readonly ?string $squareInvoiceNumber,
        public readonly string $today,
        private readonly ?array $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $due = $this->invoice->due_date?->toDateString();

        return new Content(view: 'invoices.reminder-email', with: [
            'invoice' => $this->invoice,
            'companyName' => $this->companyName,
            'linkKind' => $this->payLink->value,
            'payUrl' => $this->payUrl,
            'squareInvoiceNumber' => $this->squareInvoiceNumber,
            'dueDate' => $due,
            'overdue' => $due !== null && $due < $this->today,
            'balance' => $this->invoice->currency.' '.number_format($this->invoice->balance_amount / 100, 2),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }
        [$bytes, $name] = $this->pdf;

        return [Attachment::fromData(fn (): string => $bytes, $name)->withMime('application/pdf')];
    }
}
