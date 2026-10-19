<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoicePayment;
use App\Services\Billing\PaymentRefundLedger;
use App\Support\Billing\ManualPaymentChange;
use DomainException;

/** The console twin of `payments.reverse_refund` (#472); see {@see ManualPaymentCommand}. */
class ReverseRefundCommand extends ManualPaymentCommand
{
    protected $signature = 'svc:billing:reverse-refund
        {payment : Payment public UUID}
        {--workspace= : Workspace public UUID (required)}
        {--refund= : The refund recorded in error, by its public UUID (required)}
        {--amount= : How much of that refund to reverse, in integer minor units (default: all of it)}
        {--reason= : Why the refund was recorded in error (required)}
        {--expected-version= : Refuse unless the payment is still at this version}
        {--dry-run : Report what would change and write nothing}
        {--format=text : Output text or json}';

    protected $description = 'Reverse a refund recorded in error against a hand-recorded payment, with a recorded reason; the refund itself is kept';

    protected function change(): ManualPaymentChange
    {
        $amount = $this->option('amount');
        if ($amount !== null && preg_match('/^[1-9]\d*$/D', $amount) !== 1) {
            throw new DomainException('The --amount option is a whole number of minor units, at least 1.');
        }

        return ManualPaymentChange::reversal($this->option('refund'), $amount === null ? null : (int) $amount, $this->option('reason'));
    }

    protected function refusalHint(ClientInvoicePayment $payment): ?string
    {
        $open = app(PaymentRefundLedger::class)->unreversed($payment);

        return $open === []
            ? 'This payment has no refund left to reverse.'
            : 'Refunds of this payment not yet reversed: '.implode(', ', $open).'.';
    }
}
