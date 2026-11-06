<?php

namespace App\Services\Mcp;

use App\Services\Revenue\RevenueSummaries;
use Mcp\Capability\Attribute\Schema;

/**
 * The revenue planning reads over MCP: each tool is its REST operation,
 * dispatched through {@see AgentMcpRestCall}, so a tool and its route return
 * the same figures, scopes and refusals.
 */
final class AgentMcpRevenueTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function summary(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['invoiced', 'collected'])] ?string $basis = null,
        ?bool $include_expenses = null,
        ?bool $include_projected_overage = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
    ): array {
        return $this->rest->call('revenue.summary', ['workspace' => $workspace_id], query: self::query(compact('basis', 'include_expenses', 'include_projected_overage', 'client_id')));
    }

    /** @return array<string, mixed> */
    public function monthly(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['invoiced', 'collected'])] ?string $basis = null,
        ?bool $include_expenses = null,
        ?bool $include_projected_overage = null,
        #[Schema(enum: RevenueSummaries::BANDS)] ?string $band = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
    ): array {
        return $this->rest->call('revenue.monthly', ['workspace' => $workspace_id], query: self::query(compact('basis', 'include_expenses', 'include_projected_overage', 'band', 'client_id')));
    }

    /** @return array<string, mixed> */
    public function projection(
        #[Schema(format: 'uuid')] string $workspace_id,
        ?bool $include_projected_overage = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
    ): array {
        return $this->rest->call('revenue.projection', ['workspace' => $workspace_id], query: self::query(compact('include_projected_overage', 'client_id')));
    }

    /** @return array<string, mixed> */
    public function clients(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['invoiced', 'collected'])] ?string $basis = null,
        ?bool $include_expenses = null,
        #[Schema(format: 'uuid')] ?string $client_id = null,
    ): array {
        return $this->rest->call('revenue.clients', ['workspace' => $workspace_id], query: self::query(compact('basis', 'include_expenses', 'client_id')));
    }

    /**
     * A query string carries text: booleans as the route reads them.
     *
     * @param  array<string, string|bool|null>  $values
     * @return array<string, string>
     */
    private static function query(array $values): array
    {
        $query = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $query[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            }
        }

        return $query;
    }
}
