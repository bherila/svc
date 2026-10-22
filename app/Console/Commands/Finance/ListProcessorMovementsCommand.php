<?php

namespace App\Console\Commands\Finance;

use App\Models\Workspace;
use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinanceCaller;
use App\Support\Finance\FinanceProcessorMovementQuery;

/** The console twin of the read-only processor movement change feed. */
class ListProcessorMovementsCommand extends FinanceCommand
{
    protected $signature = 'svc:finance:processor-movements
        {--workspace= : Workspace public UUID (required)}
        {--user= : Public UUID of the workspace owner or admin acting (required)}
        {--provider= : stripe or square}
        {--kind= : refund, refund_reversal, dispute_debit, dispute_credit, fee or fee_reversal}
        {--payment-id= : Original payment public UUID}
        {--occurred-from= : Local occurrence date on or after, YYYY-MM-DD}
        {--occurred-to= : Local occurrence date on or before, YYYY-MM-DD}
        {--per-page= : Rows per page, at most 100 (default 50)}
        {--updated-since= : Inclusive UTC change-feed lower bound}
        {--watermark= : UTC snapshot watermark}
        {--cursor= : Opaque cursor from the preceding page}
        {--format=text : Output text or json}';

    protected $description = 'Read the signed processor movement change feed for a workspace';

    protected function perform(FinanceReconciliationOperations $operations, FinanceCaller $caller, Workspace $workspace): array
    {
        $input = [];
        foreach (['provider', 'kind', 'payment-id', 'occurred-from', 'occurred-to', 'per-page', 'updated-since', 'watermark', 'cursor'] as $option) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[str_replace('-', '_', $option)] = (string) $value;
            }
        }

        return $operations->movementChanges($caller, $workspace, FinanceProcessorMovementQuery::fromInput($input));
    }

    protected function summarize(array $result): void
    {
        foreach ($result['data'] as $movement) {
            $this->components->twoColumnDetail($movement['id'].' '.$movement['occurred_on'].' '.$movement['kind'], $movement['net_minor'].' '.$movement['currency']);
        }
        $this->components->info(count($result['data']).' movements; watermark '.$result['meta']['watermark'].($result['meta']['next_cursor'] === null ? '; complete.' : '; continue with next_cursor.'));
    }
}
