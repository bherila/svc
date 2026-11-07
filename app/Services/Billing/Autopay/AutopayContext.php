<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripePaymentMethod;
use App\Models\InvoiceAutopayAttempt;
use App\Support\Billing\Autopay\AutopayFacts;

/** The rows behind one {@see AutopayFacts}, for the caller that goes on to charge or to present. */
final readonly class AutopayContext
{
    public function __construct(
        public ClientInvoice $invoice,
        public ClientCompany $company,
        public AutopayFacts $facts,
        public ?ClientStripePaymentMethod $method,
        public ?InvoiceAutopayAttempt $attempt,
        public ?ClientInvoicePayment $attemptPayment,
    ) {}
}
