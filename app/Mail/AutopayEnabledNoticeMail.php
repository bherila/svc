<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a client's billing contacts that the provider turned autopay on for
 * them on the strength of an agreement made outside SVC (#636), and where in
 * the portal to turn it off. Sent in the request that made the change, after
 * it committed: nothing here is queued.
 */
final class AutopayEnabledNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $providerName,
        public readonly string $companyName,
        public readonly string $methodLabel,
        public readonly string $limit,
        public readonly string $attestation,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->providerName.' turned on autopay for '.$this->companyName);
    }

    public function content(): Content
    {
        return new Content(view: 'invoices.autopay-enabled-email', with: [
            'providerName' => $this->providerName,
            'companyName' => $this->companyName,
            'methodLabel' => $this->methodLabel,
            'limit' => $this->limit,
            'attestation' => $this->attestation,
            'portalUrl' => $this->portalUrl,
        ]);
    }
}
