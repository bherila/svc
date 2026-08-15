<?php

namespace App\Console\Commands\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Console\Command;

class ApplyPaymentCommand extends Command
{
    protected $signature = 'svc:billing:payment
        {invoice : Invoice public UUID}
        {amount : Integer minor-unit amount}
        {currency : Uppercase ISO currency}
        {method : Payment method}
        {--workspace= : Workspace public UUID}
        {--received-on= : Received date}
        {--reference= : External payment reference}
        {--notes= : Internal note}
        {--status=succeeded : Payment lifecycle status}
        {--idempotency-key= : Stable retry key}
        {--format=text : Output text or json}';

    protected $description = 'Apply a tenant-scoped manual payment to an invoice';

    protected $aliases = ['billing:apply-payment'];

    public function handle(InvoiceLifecycleService $service): int
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
        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();
        $invoice = ClientInvoice::query()->where('public_id', (string) $this->argument('invoice'))->first();
        if ($workspace === null || $invoice === null || $invoice->workspace_id !== $workspace->id) {
            $this->error('Invoice not found in the requested workspace.');

            return self::FAILURE;
        }

        $payment = $service->applyPayment($invoice, [
            'amount' => $this->argument('amount'),
            'currency' => $this->argument('currency'),
            'received_on' => $this->option('received-on') ?: null,
            'method' => $this->argument('method'),
            'reference' => $this->option('reference'),
            'notes' => $this->option('notes'),
            'status' => $this->option('status'),
            'idempotency_key' => $this->option('idempotency-key'),
        ], $workspace);

        $result = [
            'payment_public_id' => $payment->public_id,
            'invoice_public_id' => $invoice->public_id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'invoice_status' => $payment->invoice->status,
            'remaining_balance' => $payment->invoice->balance_amount,
        ];
        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Payment', $payment->public_id);
            $this->components->twoColumnDetail('Invoice status', $result['invoice_status']);
            $this->components->twoColumnDetail('Remaining balance', (string) $result['remaining_balance']);
        }

        return self::SUCCESS;
    }
}
