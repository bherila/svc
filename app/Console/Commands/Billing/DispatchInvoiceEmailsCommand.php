<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Services\Billing\InvoiceAdministratorNotificationService;
use App\Services\Billing\InvoiceEmailService;

/** Recover committed issuance notifications and deliver due opt-in invoices. */
final class DispatchInvoiceEmailsCommand extends MutationCommand
{
    protected $signature = 'svc:billing:dispatch-invoice-emails {--limit=50 : Maximum records of each purpose to inspect}';

    protected $description = 'Send committed invoice review notifications and due automatic client deliveries';

    public function handle(
        InvoiceAdministratorNotificationService $administrators,
        InvoiceEmailService $clients,
    ): int {
        $limit = max(1, min(500, (int) $this->option('limit')));
        if (! $this->applying()) {
            return $this->planned();
        }

        $administratorCount = $administrators->dispatchDue($limit);
        $clientCount = $clients->dispatchAutomaticDue($limit);

        if ($this->option('format') === 'json') {
            $this->json(['administrator_deliveries_inspected' => $administratorCount, 'client_deliveries_inspected' => $clientCount]);

            return self::SUCCESS;
        }
        $this->components->info("Inspected {$administratorCount} administrator and {$clientCount} client deliveries.");

        return self::SUCCESS;
    }
}
