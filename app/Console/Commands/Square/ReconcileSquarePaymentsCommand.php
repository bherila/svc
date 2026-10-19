<?php

namespace App\Console\Commands\Square;

use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareInvoiceReconciliation;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Cron's backstop also revisits paid invoices so missed refunds are recovered.
 * `--invoice=` reconciles one linked invoice now, through the same
 * {@see SquareInvoiceReconciliation} as `invoices.square_reconcile`.
 */
final class ReconcileSquarePaymentsCommand extends Command
{
    protected $signature = 'svc:square:reconcile-payments
        {--invoice= : Reconcile only this linked svc invoice (its public id), now}';

    protected $description = 'Reconcile linked invoices and refunds through read-only Square calls';

    public function handle(SquarePaymentReconciler $reconciler, SquareInvoiceReconciliation $single): int
    {
        $invoice = $this->option('invoice');
        // A bare --invoice is a typo for one invoice, never a request for the sweep.
        if (($invoice === null && $this->input->hasParameterOption('--invoice')) || ($invoice !== null && ! Str::isUuid($invoice))) {
            $this->error('The --invoice option must be an invoice public id.');

            return self::INVALID;
        }
        $configuration = SquareConfiguration::fromConfig();
        if (! $configuration->isConfigured()) {
            if ($invoice !== null) {
                $this->components->warn('Square is not configured, so the invoice was not reconciled.');

                return self::FAILURE;
            }
            $this->components->info('Square is not configured; nothing to reconcile.');

            return self::SUCCESS;
        }
        $workspace = Workspace::query()->where('public_id', $configuration->workspacePublicId)->first();
        if ($workspace === null) {
            $this->components->warn('The configured workspace does not exist.');

            return self::FAILURE;
        }
        if ($invoice !== null) {
            return $this->reconcileOne($single, $workspace, $invoice);
        }
        $failed = 0;
        foreach ($reconciler->linkedInvoices($workspace) as $link) {
            try {
                $reconciler->invoice($workspace, $link->square_invoice_id);
            } catch (SquareRequestFailed $failure) {
                if (! $failure->isRecordSpecific()) {
                    $this->components->warn('The Square connection is unavailable; stopping the sweep until the next scheduled attempt.');

                    return self::FAILURE;
                }
                $failed++;
            } catch (DomainException) {
                $failed++;
            }
        }
        foreach ($reconciler->recordedPayments($workspace) as $payment) {
            try {
                $reconciler->payment($workspace, (string) $payment->provider_payment_identifier);
            } catch (SquareRequestFailed $failure) {
                if (! $failure->isRecordSpecific()) {
                    $this->components->warn('The Square connection is unavailable; stopping the sweep until the next scheduled attempt.');

                    return self::FAILURE;
                }
                $failed++;
            } catch (DomainException) {
                $failed++;
            }
        }
        if ($failed > 0) {
            $this->components->warn('Some linked invoices could not be reconciled; the next sweep will retry.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reconcileOne(SquareInvoiceReconciliation $single, Workspace $workspace, string $invoice): int
    {
        try {
            $reconciled = $single->reconcile($workspace, $invoice);
        } catch (ModelNotFoundException) {
            $this->components->warn('No invoice with that id exists in the workspace Square serves.');

            return self::FAILURE;
        } catch (SquareRequestFailed $failure) {
            $this->components->warn('Square could not be read for this invoice: '.$failure->getMessage());

            return self::FAILURE;
        } catch (DomainException $refused) {
            $this->components->warn($refused->getMessage());

            return self::FAILURE;
        }
        $this->components->info(sprintf('Invoice %s reconciled with Square: %s, balance %d %s.', $reconciled->invoice_number ?? $reconciled->public_id, $reconciled->status, (int) $reconciled->balance_amount, $reconciled->currency));

        return self::SUCCESS;
    }
}
