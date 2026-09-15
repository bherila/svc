<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\InvoiceAdministratorNotificationService;
use App\Services\Billing\InvoiceEmailService;
use Illuminate\Console\Command;

/** Recover committed issuance notifications and deliver due opt-in invoices. */
final class DispatchInvoiceEmailsCommand extends Command
{
    protected $signature = 'svc:billing:dispatch-invoice-emails {--limit=50 : Maximum records of each purpose to inspect}';

    protected $description = 'Send committed invoice review notifications and due automatic client deliveries';

    public function handle(
        InvoiceAdministratorNotificationService $administrators,
        InvoiceEmailService $clients,
    ): int {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $administratorCount = $administrators->dispatchDue($limit);
        $clientCount = $clients->dispatchAutomaticDue($limit);

        $this->components->info("Inspected {$administratorCount} administrator and {$clientCount} client deliveries.");

        return self::SUCCESS;
    }
}
