<?php

namespace App\Console\Commands\Finance;

use App\Http\Resources\Api\V1\FinancePayoutResource;
use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinancePayoutQuery;

/** The console twin of `finance.payouts.list`; see {@see FinanceCommand}. */
class ListPayoutsCommand extends FinanceCommand
{
    protected $signature = 'svc:finance:payouts
        {--workspace= : Workspace public UUID (required)}
        {--user= : Public UUID of the workspace owner or admin acting (required)}
        {--provider= : stripe or square}
        {--status= : pending, in_transit, paid, failed or canceled}
        {--arrival-from= : Arriving on or after, YYYY-MM-DD}
        {--arrival-to= : Arriving on or before, YYYY-MM-DD}
        {--per-page= : Rows per page, at most 100 (default 50)}
        {--page=1 : Page number}
        {--format=text : Output text or json}';

    protected $description = 'List the payment-processor payouts that settled a workspace\'s payments';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $input = ['page' => $this->option('page')];
        foreach (['provider' => 'provider', 'status' => 'status', 'arrival-from' => 'arrival_from', 'arrival-to' => 'arrival_to', 'per-page' => 'per_page'] as $option => $field) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[$field] = (string) $value;
            }
        }
        $page = $operations->payouts($caller, $workspace, FinancePayoutQuery::fromInput($input));

        return [
            'data' => FinancePayoutResource::collection($page->items())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    protected function summarize(array $result): void
    {
        foreach ($result['data'] as $payout) {
            $this->components->twoColumnDetail(
                $payout['id'].' '.($payout['arrival_date'] ?? 'no arrival date').' '.$payout['provider'].' '.$payout['status'],
                $payout['amount'].' '.$payout['currency'].' ('.$payout['type'].')',
            );
        }
        $meta = $result['meta'];
        $this->components->info("Page {$meta['current_page']} of {$meta['last_page']}, {$meta['total']} payouts.");
    }
}
