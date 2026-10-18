<?php

namespace App\Console\Commands\Billing;

use App\Support\Billing\ManualPaymentChange;
use DomainException;

/** The console twin of `payments.refund`; see {@see ManualPaymentCommand}. */
class RefundPaymentCommand extends ManualPaymentCommand
{
    protected $signature = 'svc:billing:refund-payment
        {payment : Payment public UUID}
        {--workspace= : Workspace public UUID (required)}
        {--amount= : What was returned to the client now, in integer minor units (required)}
        {--reason= : Why the money was returned (required)}
        {--expected-version= : Refuse unless the payment is still at this version}
        {--dry-run : Report what would change and write nothing}
        {--format=text : Output text or json}';

    protected $description = 'Record money returned to the client against a hand-recorded payment, with a recorded reason';

    protected function change(): ManualPaymentChange
    {
        $amount = $this->option('amount');
        if (! is_string($amount) || preg_match('/^[1-9]\d*$/D', $amount) !== 1) {
            throw new DomainException('The --amount option is a whole number of minor units, at least 1.');
        }

        return ManualPaymentChange::refund((int) $amount, $this->option('reason'));
    }
}
