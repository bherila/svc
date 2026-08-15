<?php

namespace App\Services\Billing;

use App\Mail\InvoiceMail;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $invoiceId, private readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = ClientInvoiceEmailDelivery::query()->findOrFail($this->deliveryId);
        $invoice = ClientInvoice::query()->findOrFail($this->invoiceId);
        if ($delivery->status === 'sent') {
            return;
        }

        Mail::to($delivery->recipients)->send(new InvoiceMail($invoice));
        $delivery->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_reference' => null,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        ClientInvoiceEmailDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_summary' => 'Email delivery failed ('.class_basename($exception).').',
        ]);
    }
}
