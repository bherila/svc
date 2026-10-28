<?php

namespace App\Services\Billing\Mail;

use App\Support\Billing\InvoiceEmailDraft;
use App\Support\Billing\InvoiceMailSendResult;
use Illuminate\Mail\Mailable;

interface InvoiceMailSender
{
    public function send(Mailable $message, InvoiceEmailDraft $draft): InvoiceMailSendResult;
}
