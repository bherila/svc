<?php

namespace App\Services\Mcp;

use Mcp\Capability\Attribute\Schema;

/**
 * Autopay over MCP (#636): the same REST operations, receipts and refusals.
 */
final class AgentMcpAutopayTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function company(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id): array
    {
        return $this->rest->call('companies.autopay.get', ['workspace' => $workspace_id, 'client' => $client_id]);
    }

    /** @return array<string, mixed> */
    public function setCompany(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $enabled,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(minimum: 1)] ?int $limit_amount = null,
        #[Schema(minLength: 10, maxLength: 1000)] ?string $attestation = null,
        #[Schema] ?bool $confirm = null,
    ): array {
        $body = ['expected_version' => $expected_version, 'enabled' => $enabled];
        foreach (['limit_amount' => $limit_amount, 'attestation' => $attestation, 'confirm' => $confirm] as $field => $value) {
            if ($value !== null) {
                $body[$field] = $value;
            }
        }

        return $this->rest->call('companies.autopay.set', ['workspace' => $workspace_id, 'client' => $client_id], $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoice(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        return $this->rest->call('invoices.autopay.get', ['workspace' => $workspace_id, 'invoice' => $invoice_id]);
    }

    /** @return array<string, mixed> */
    public function skip(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->rest->call('invoices.autopay.skip', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'confirm'), $idempotency_key);
    }
}
