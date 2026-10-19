<?php

namespace App\Services\Mcp;

use App\Services\Finance\FinanceReconciliationOperations;
use App\Support\Finance\FinancePaymentQuery;
use App\Support\Finance\ReconciliationTarget;
use Mcp\Capability\Attribute\Schema;

/**
 * Finance reconciliation over MCP (#457): each tool is its REST operation,
 * dispatched through {@see AgentMcpRestCall}, so the tool and the route run
 * one {@see FinanceReconciliationOperations} with the
 * same refusals.
 */
final class AgentMcpFinanceTools
{
    private const string SYSTEM = '^'.ReconciliationTarget::SYSTEM_PATTERN.'$';

    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function list(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['pending', 'succeeded', 'failed', 'refunded', 'disputed', 'canceled'])] ?string $status = null,
        #[Schema(format: 'uuid')] ?string $invoice_id = null,
        #[Schema(format: 'date')] ?string $received_from = null,
        #[Schema(format: 'date')] ?string $received_to = null,
        #[Schema(minimum: 1, maximum: FinancePaymentQuery::MAX_PER_PAGE)] int $per_page = FinancePaymentQuery::DEFAULT_PER_PAGE,
        #[Schema(minimum: 1)] int $page = 1,
    ): array {
        // A query string carries text; the REST route reads it as given.
        $query = array_map(strval(...), array_filter(
            ['invoice' => $invoice_id, ...compact('status', 'received_from', 'received_to', 'per_page', 'page')],
            static fn (mixed $value): bool => $value !== null,
        ));

        return $this->rest->call('finance.invoice_payments.list', ['workspace' => $workspace_id], query: $query);
    }

    /** @return array<string, mixed> */
    public function upsert(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(maxLength: ReconciliationTarget::SYSTEM_MAX_LENGTH, pattern: self::SYSTEM)] string $external_system,
        #[Schema(format: 'uuid')] string $transaction_id,
        #[Schema(minimum: 1)] int $allocated_amount,
        #[Schema(pattern: '^[A-Z]{3}$')] string $currency,
        #[Schema(format: 'date')] ?string $reconciled_on = null,
        bool $is_active = true,
    ): array {
        return $this->rest->call(
            'finance.reconciliations.upsert',
            $this->allocation($workspace_id, $payment_id, $external_system, $transaction_id),
            compact('allocated_amount', 'currency', 'reconciled_on', 'is_active'),
        );
    }

    /** @return array<string, mixed> */
    public function deactivate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $payment_id,
        #[Schema(maxLength: ReconciliationTarget::SYSTEM_MAX_LENGTH, pattern: self::SYSTEM)] string $external_system,
        #[Schema(format: 'uuid')] string $transaction_id,
    ): array {
        return $this->rest->call('finance.reconciliations.deactivate', $this->allocation($workspace_id, $payment_id, $external_system, $transaction_id));
    }

    /** @return array<string, string> the REST binding's path parameters */
    private function allocation(string $workspace, string $payment, string $system, string $transaction): array
    {
        return ['workspace' => $workspace, 'payment' => $payment, 'external_system' => $system, 'transaction' => $transaction];
    }
}
