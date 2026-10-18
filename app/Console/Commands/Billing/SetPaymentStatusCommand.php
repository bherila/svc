<?php

namespace App\Console\Commands\Billing;

use App\Support\Billing\ManualPaymentChange;

/** The console twin of `payments.set_status`; see {@see ManualPaymentCommand}. */
class SetPaymentStatusCommand extends ManualPaymentCommand
{
    protected $signature = 'svc:billing:payment-status
        {payment : Payment public UUID}
        {--workspace= : Workspace public UUID (required)}
        {--status= : succeeded (it cleared), failed (it bounced or was reversed) or canceled (recorded in error)}
        {--reason= : Why the status is changing (required)}
        {--expected-version= : Refuse unless the payment is still at this version}
        {--dry-run : Report what would change and write nothing}
        {--format=text : Output text or json}';

    protected $description = 'Mark a hand-recorded payment succeeded, failed or canceled, with a recorded reason';

    protected function change(): ManualPaymentChange
    {
        return ManualPaymentChange::status($this->option('status'), $this->option('reason'));
    }
}
