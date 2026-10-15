<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\Reminders\PaymentReminderService;
use Illuminate\Console\Command;

/**
 * The automatic payment reminder cadence (#438), for clients that have it
 * switched on. Runs in process from the scheduler: nothing is queued.
 */
final class SendPaymentRemindersCommand extends Command
{
    protected $signature = 'svc:billing:send-payment-reminders {--limit=50 : Maximum reminders to send in this run}';

    protected $description = 'Send the payment reminders whose cadence step has fallen due';

    public function handle(PaymentReminderService $reminders): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $sent = $reminders->dispatchAutomaticDue($limit);

        $this->components->info("Sent or attempted {$sent} payment reminders.");

        return self::SUCCESS;
    }
}
