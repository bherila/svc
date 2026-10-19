<?php

namespace App\Console\Commands\Finance;

use App\Http\Resources\Api\V1\InvoicePaymentResource;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinancePaymentQuery;

/** The console twin of `finance.invoice_payments.list`; see {@see FinanceCommand}. */
class ListInvoicePaymentsCommand extends FinanceCommand
{
    protected $signature = 'svc:finance:payments
        {--workspace= : Workspace public UUID (required)}
        {--user= : Public UUID of the workspace owner or admin acting (required)}
        {--status= : Payment status (default succeeded)}
        {--invoice= : Only this invoice\'s payments (public UUID)}
        {--received-from= : Received on or after, YYYY-MM-DD}
        {--received-to= : Received on or before, YYYY-MM-DD}
        {--per-page= : Rows per page, at most 100 (default 50)}
        {--page=1 : Page number}
        {--format=text : Output text or json}';

    protected $description = 'List received payments with their finance reconciliation allocations';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $input = ['page' => $this->option('page')];
        foreach (['status' => 'status', 'invoice' => 'invoice', 'received-from' => 'received_from', 'received-to' => 'received_to', 'per-page' => 'per_page'] as $option => $field) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[$field] = (string) $value;
            }
        }
        $page = $operations->list($caller, $workspace, FinancePaymentQuery::fromInput($input));

        return [
            'data' => InvoicePaymentResource::collection($page->items())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    protected function summarize(array $result): void
    {
        foreach ($result['data'] as $payment) {
            $this->components->twoColumnDetail(
                $payment['id'].' '.$payment['received_on'].' '.$payment['status'],
                $payment['net_amount'].' '.$payment['currency'].', '.$payment['unreconciled_amount'].' unreconciled',
            );
        }
        $meta = $result['meta'];
        $this->components->info("Page {$meta['current_page']} of {$meta['last_page']}, {$meta['total']} payments.");
    }
}
