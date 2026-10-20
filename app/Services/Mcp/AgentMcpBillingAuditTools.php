<?php

namespace App\Services\Mcp;

use Mcp\Capability\Attribute\Schema;

/**
 * The aggregate billing audits over MCP (#459): the same REST operations,
 * scopes, budget and refusals.
 */
final class AgentMcpBillingAuditTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function unplaceableInvoices(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.audit_unplaceable_invoices', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function undatedCollectibleInvoices(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.audit_undated_collectible_invoices', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function missingBilledOverage(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.audit_missing_billed_overage', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function openingRollover(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.audit_opening_rollover', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function scheduleGenerationPreflight(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.preflight_schedule_generation', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function unlinkedProposalAgreements(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('billing.audit_unlinked_proposal_agreements', ['workspace' => $workspace_id]);
    }
}
