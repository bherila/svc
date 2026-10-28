<?php

namespace Tests\Concerns;

use App\Services\Billing\Mail\InvoiceMailSender;
use App\Support\Billing\InvoiceMailSendResult;
use Throwable;

/** Explicit adapter phases for domain tests; real mailer boundaries have separate regressions. */
trait FakesInvoiceMailOutcomes
{
    protected function failInvoiceMail(Throwable $failure, bool $handoffStarted = false): void
    {
        $sender = \Mockery::mock(InvoiceMailSender::class);
        $sender->shouldReceive('send')->once()->andReturnUsing(function () use ($failure, $handoffStarted): InvoiceMailSendResult {
            $this->app->forgetInstance(InvoiceMailSender::class);

            return InvoiceMailSendResult::failed($failure, $handoffStarted);
        });
        $this->app->instance(InvoiceMailSender::class, $sender);
    }
}
