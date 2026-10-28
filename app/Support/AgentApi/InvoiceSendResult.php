<?php

namespace App\Support\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceEmailDelivery;

/** The invoice and the exact delivery registered by this receipt, if known. */
final readonly class InvoiceSendResult
{
    public function __construct(
        public ClientInvoice $invoice,
        public ?ClientInvoiceEmailDelivery $delivery,
    ) {}
}
