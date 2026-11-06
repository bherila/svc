<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Operations\AccountingOperations;
use App\Support\Accounting\AccountingFeedQuery;
use Mcp\Capability\Attribute\Schema;

/**
 * The accounting reads over MCP: each tool is its REST operation, dispatched
 * through {@see AgentMcpRestCall}, so a tool and its route return the same
 * entries, scopes and refusals. The yearly export is returned as JSON; the CSV
 * download is REST only.
 */
final class AgentMcpAccountingTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function invoiceLines(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['invoice_line', 'correction', 'void'])] ?string $entry_type = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
        #[Schema(format: 'uuid')] ?string $invoice_id = null,
        #[Schema(format: 'date')] ?string $from = null,
        #[Schema(format: 'date')] ?string $to = null,
        #[Schema(minimum: 1, maximum: AccountingFeedQuery::MAX_PER_PAGE)] int $per_page = AccountingFeedQuery::DEFAULT_PER_PAGE,
        #[Schema(format: 'date-time')] ?string $updated_since = null,
        #[Schema(format: 'date-time')] ?string $watermark = null,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->rest->call('finance.invoice_lines.list', ['workspace' => $workspace_id], query: self::query(
            compact('entry_type', 'client_id', 'invoice_id', 'from', 'to', 'per_page', 'updated_since', 'watermark', 'cursor'),
        ));
    }

    /** @return array<string, mixed> */
    public function cashIncome(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['payment', 'refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee', 'fee_reversal'])] ?string $entry_type = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
        #[Schema(format: 'uuid')] ?string $invoice_id = null,
        #[Schema(format: 'date')] ?string $from = null,
        #[Schema(format: 'date')] ?string $to = null,
        #[Schema(minimum: 1, maximum: AccountingFeedQuery::MAX_PER_PAGE)] int $per_page = AccountingFeedQuery::DEFAULT_PER_PAGE,
        #[Schema(format: 'date-time')] ?string $updated_since = null,
        #[Schema(format: 'date-time')] ?string $watermark = null,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->rest->call('finance.cash_income.list', ['workspace' => $workspace_id], query: self::query(
            compact('entry_type', 'client_id', 'invoice_id', 'from', 'to', 'per_page', 'updated_since', 'watermark', 'cursor'),
        ));
    }

    /** @return array<string, mixed> */
    public function incomeExport(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minimum: AccountingOperations::YEAR_MIN, maximum: AccountingOperations::YEAR_MAX)] int $year,
    ): array {
        return $this->rest->call('finance.income_export.get', ['workspace' => $workspace_id], query: ['year' => (string) $year]);
    }

    /**
     * A query string carries text.
     *
     * @param  array<string, string|int|null>  $values
     * @return array<string, string>
     */
    private static function query(array $values): array
    {
        return array_map(strval(...), array_filter($values, static fn (string|int|null $value): bool => $value !== null));
    }
}
