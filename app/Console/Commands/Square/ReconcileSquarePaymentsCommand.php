<?php

namespace App\Console\Commands\Square;

use App\Console\Commands\MutationCommand;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Square\SquareConfiguration;
use App\Services\Square\SquareInvoiceReconciliation;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Cron's backstop also revisits paid invoices so missed refunds are recovered.
 * `--invoice=` reconciles one linked invoice now, through the same
 * {@see SquareInvoiceReconciliation} as `invoices.square_reconcile`.
 */
final class ReconcileSquarePaymentsCommand extends MutationCommand
{
    protected $signature = 'svc:square:reconcile-payments
        {--invoice= : Reconcile only this linked svc invoice (its public id), now}';

    protected $description = 'Reconcile linked invoices, refunds and disputes through read-only Square calls';

    public function handle(SquarePaymentReconciler $reconciler, SquareInvoiceReconciliation $single): int
    {
        $invoice = $this->option('invoice');
        // A bare --invoice is a typo for one invoice, never a request for the sweep.
        if (($invoice === null && $this->input->hasParameterOption('--invoice')) || ($invoice !== null && ! Str::isUuid($invoice))) {
            $this->error('The --invoice option must be an invoice public id.');

            return self::INVALID;
        }
        $workspaces = SquareConfiguration::servedWorkspaces();
        if ($workspaces === []) {
            if ($invoice !== null) {
                $this->components->warn('Square is not configured, so the invoice was not reconciled.');

                return self::FAILURE;
            }
            if ($this->option('format') === 'json') {
                $this->json(['configured' => false, 'reconciled' => false]);
            } else {
                $this->components->info('Square is not configured; nothing to reconcile.');
            }

            return self::SUCCESS;
        }
        if (! $this->applying()) {
            return $this->planned();
        }

        if ($invoice !== null) {
            // The invoice picks the workspace; only a workspace Square serves is eligible.
            $owner = ClientInvoice::query()->where('public_id', $invoice)->value('workspace_id');
            foreach ($workspaces as $workspace) {
                if ($workspace->id === $owner) {
                    return $this->reconcileOne($single, $workspace, $invoice);
                }
            }
            $this->components->warn('No invoice with that id exists in the workspace Square serves.');

            return self::FAILURE;
        }
        $exit = self::SUCCESS;
        foreach ($workspaces as $workspace) {
            if ($this->sweep($reconciler, $workspace) !== self::SUCCESS) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function sweep(SquarePaymentReconciler $reconciler, Workspace $workspace): int
    {
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
        // New payments must exist before recovering disputes whose notification
        // arrived first. A failed listing is not a successful, complete sweep.
        try {
            $reconciler->disputes($workspace);
        } catch (SquareRequestFailed|DomainException) {
            $failed++;
        }
        if ($this->option('format') === 'json') {
            $this->json(['failed' => $failed]);

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }
        if ($failed > 0) {
            $this->components->warn('Some invoices, payments or disputes could not be reconciled; the next sweep will retry.');
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
        if ($this->option('format') === 'json') {
            $this->json(['invoice_id' => $reconciled->public_id, 'status' => $reconciled->status, 'balance_amount' => (int) $reconciled->balance_amount, 'currency' => $reconciled->currency]);

            return self::SUCCESS;
        }
        $this->components->info(sprintf('Invoice %s reconciled with Square: %s, balance %d %s.', $reconciled->invoice_number ?? $reconciled->public_id, $reconciled->status, (int) $reconciled->balance_amount, $reconciled->currency));

        return self::SUCCESS;
    }
}
