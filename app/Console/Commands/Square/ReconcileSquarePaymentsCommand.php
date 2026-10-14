<?php

namespace App\Console\Commands\Square;

use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use DomainException;
use Illuminate\Console\Command;

/** Cron's backstop also revisits paid invoices so missed refunds are recovered. */
final class ReconcileSquarePaymentsCommand extends Command
{
    protected $signature = 'svc:square:reconcile-payments';

    protected $description = 'Reconcile linked invoices and refunds through read-only Square calls';

    public function handle(SquarePaymentReconciler $reconciler): int
    {
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->isConfigured()) {
            $this->components->info('Square is not configured; nothing to reconcile.');

            return self::SUCCESS;
        }
        $workspace = Workspace::query()->where('public_id', $configuration->workspacePublicId)->first();
        if ($workspace === null) {
            $this->components->warn('The configured workspace does not exist.');

            return self::FAILURE;
        }
        $failed = 0;
        foreach ($reconciler->linkedInvoices($workspace) as $link) {
            try {
                $reconciler->invoice($workspace, $link->square_invoice_id);
            } catch (SquareRequestFailed|DomainException) {
                $failed++;
            }
        }
        if ($failed > 0) {
            $this->components->warn('Some linked invoices could not be reconciled; the next sweep will retry.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
