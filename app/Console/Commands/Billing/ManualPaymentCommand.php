<?php

namespace App\Console\Commands\Billing;

use App\Console\Commands\MutationCommand;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\ManualPaymentChange;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The operator's doors to {@see InvoiceLifecycleService::changeManualPayment()}
 * (#455), the console twins of `payments.set_status`, `payments.refund` and
 * `payments.reverse_refund` (#472).
 *
 * Each subclass only turns its options into a {@see ManualPaymentChange}; the
 * rules are that value's and the write is the service's, exactly as the API
 * reaches them. `--dry-run` runs the whole change - every rule, the version
 * check, the no-op rule, the invoice recomputation - inside a transaction that
 * is always rolled back, so what it reports is what a real run would do. The
 * report is taken from the row the service locked, never from a read before it.
 */
abstract class ManualPaymentCommand extends MutationCommand
{
    /** The change the options describe; a DomainException is reported as a refusal. */
    abstract protected function change(): ManualPaymentChange;

    /** What to tell the operator after a refusal, beside the refusal itself. */
    protected function refusalHint(ClientInvoicePayment $payment): ?string
    {
        return null;
    }

    public function handle(InvoiceLifecycleService $service): int
    {
        $format = (string) $this->option('format');
        $workspaceId = (string) $this->option('workspace');
        if ($workspaceId === '') {
            $this->error('The --workspace option is required.');

            return self::INVALID;
        }
        try {
            $change = $this->change();
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $workspace = $this->workspaceByPublicId($workspaceId);
        $payment = $workspace === null ? null : ClientInvoicePayment::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', (string) $this->argument('payment'))
            ->first();
        if ($workspace === null || $payment === null) {
            $this->error('Payment not found in the requested workspace.');

            return self::FAILURE;
        }

        $expected = $this->option('expected-version');
        $dryRun = ! $this->applying();

        DB::beginTransaction();
        try {
            $outcome = $service->changeManualPayment($payment, $change, is_string($expected) && $expected !== '' ? $expected : null, $workspace);
            $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($outcome->payment->client_invoice_id)
                ->firstOrFail(['public_id', 'status', 'paid_amount', 'balance_amount']);
            $result = [
                'payment_public_id' => (string) $outcome->payment->public_id,
                'invoice_public_id' => (string) $invoice->public_id,
                'dry_run' => $dryRun,
                'changed' => $outcome->changed,
                'status' => ['old' => $outcome->previousStatus, 'new' => (string) $outcome->payment->status],
                'refunded_amount' => ['old' => $outcome->previousRefundedAmount, 'new' => (int) $outcome->payment->refunded_amount],
                'invoice_status' => (string) $invoice->status,
                'invoice_balance' => (int) $invoice->balance_amount,
                // After a dry run the row is back to how it was locked.
                'version' => $dryRun ? $outcome->lockedVersion : AgentApiVersion::for($outcome->payment),
                // The refund or reversal entry written (#472); after a dry
                // run, the one that would have been.
                'entry' => $outcome->entry === null ? null : [
                    'id' => (string) $outcome->entry->public_id,
                    'kind' => $outcome->entry->kind->value,
                    'amount' => (int) $outcome->entry->amount,
                ],
            ];
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (DomainException $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());
            $hint = $this->refusalHint($payment);
            if ($hint !== null) {
                $this->line($hint);
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Payment', $result['payment_public_id']);
        if (! $result['changed']) {
            $this->components->info('Nothing to change: the payment already reads that way.');
        }
        $this->components->twoColumnDetail('Status', $result['status']['old'].' → '.$result['status']['new']);
        $this->components->twoColumnDetail('Refunded', $result['refunded_amount']['old'].' → '.$result['refunded_amount']['new']);
        $this->components->twoColumnDetail('Invoice', $result['invoice_status'].', balance '.$result['invoice_balance']);
        if ($result['entry'] !== null) {
            $this->components->twoColumnDetail(ucfirst($result['entry']['kind']), $result['entry']['id'].', '.$result['entry']['amount']);
        }
        if ($dryRun && $result['changed']) {
            $this->components->warn('Dry run: nothing was written.');
        }
        $this->components->twoColumnDetail('Version', $result['version']);

        return self::SUCCESS;
    }
}
