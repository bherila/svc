<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Services\Billing\Reminders\PaymentReminderService;

/**
 * The automatic payment reminder cadence (#438), for clients that have it
 * switched on. Runs in process from the scheduler: nothing is queued.
 */
final class SendPaymentRemindersCommand extends MutationCommand
{
    protected $signature = 'svc:billing:send-payment-reminders {--limit=50 : Maximum reminders to send in this run}';

    protected $description = 'Send the payment reminders whose cadence step has fallen due';

    public function handle(PaymentReminderService $reminders): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        if (! $this->applying()) {
            return $this->planned();
        }

        $sent = $reminders->dispatchAutomaticDue($limit);

        if ($this->option('format') === 'json') {
            $this->json(['reminders_attempted' => $sent]);

            return self::SUCCESS;
        }
        $this->components->info("Sent or attempted {$sent} payment reminders.");

        return self::SUCCESS;
    }
}
