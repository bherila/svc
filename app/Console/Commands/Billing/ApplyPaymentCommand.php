<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\ReceivedPaymentData;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The console twin of `payments.record` and the invoice screen's payment form.
 *
 * It parses through the same {@see ReceivedPaymentData} rules and records
 * through the same {@see RecordReceivedPayment} operation, so the console
 * accepts exactly what REST, MCP and the browser accept (#454): a payment is
 * recorded as `succeeded` or `pending`. `failed`, `canceled` and `refunded` are
 * where a recorded payment ends up, reached with `svc:billing:payment-status`
 * and `svc:billing:refund-payment`, never recorded as a new row.
 */
class ApplyPaymentCommand extends Command
{
    protected $signature = 'svc:billing:payment
        {invoice : Invoice public UUID}
        {amount : Integer minor-unit amount}
        {currency : Uppercase ISO currency}
        {method : Payment method}
        {--workspace= : Workspace public UUID}
        {--received-on= : The day the money arrived, YYYY-MM-DD (default: today on the workspace calendar)}
        {--reference= : External payment reference}
        {--notes= : Internal note}
        {--status=succeeded : succeeded, or pending for money not yet cleared}
        {--external-finance-transaction= : The finance system transaction UUID}
        {--idempotency-key= : Stable retry key}
        {--format=text : Output text or json}';

    protected $description = 'Record a tenant-scoped manual payment against an invoice';

    protected $aliases = ['billing:apply-payment'];

    public function handle(RecordReceivedPayment $payments): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }
        $workspaceId = (string) $this->option('workspace');
        if ($workspaceId === '') {
            $this->error('The --workspace option is required.');

            return self::INVALID;
        }

        try {
            $facts = ReceivedPaymentData::from([
                'amount' => $this->argument('amount'),
                'currency' => $this->argument('currency'),
                'received_on' => $this->text('received-on'),
                'method' => $this->argument('method'),
                'reference' => $this->text('reference'),
                'notes' => $this->text('notes'),
                'status' => $this->text('status'),
                'external_finance_transaction_uuid' => $this->text('external-finance-transaction'),
                'idempotency_key' => $this->text('idempotency-key'),
            ], requireDate: false, includeBookkeeping: true, acceptsBodyKey: true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
                if ($field === 'status') {
                    $this->line('A payment is recorded as '.implode(' or ', ReceivedPaymentData::recordableStatusValues()).'. To mark a recorded payment failed or canceled use svc:billing:payment-status; to record a refund use svc:billing:refund-payment.');
                }
            }

            return self::INVALID;
        }

        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        $invoice = $workspace === null ? null : ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', (string) $this->argument('invoice'))
            ->first();
        if ($workspace === null || $invoice === null) {
            $this->error('Invoice not found in the requested workspace.');

            return self::FAILURE;
        }

        try {
            $payment = $payments->record($workspace, $invoice, $facts);
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $recorded = $payment->getRelation('invoice');

        $result = [
            'payment_public_id' => $payment->public_id,
            'invoice_public_id' => $invoice->public_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'invoice_status' => $recorded->status,
            'remaining_balance' => $recorded->balance_amount,
        ];
        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Payment', $payment->public_id);
            $this->components->twoColumnDetail('Invoice status', (string) $result['invoice_status']);
            $this->components->twoColumnDetail('Remaining balance', (string) $result['remaining_balance']);
        }

        return self::SUCCESS;
    }

    /** An option given empty means not given, as the browser form's blank field does. */
    private function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
