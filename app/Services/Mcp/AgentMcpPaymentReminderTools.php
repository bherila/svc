<?php

namespace App\Services\Mcp;

use Mcp\Capability\Attribute\Schema;

/**
 * Payment reminders over MCP (#438): the same REST operations, receipts and
 * refusals.
 */
final class AgentMcpPaymentReminderTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        return $this->rest->call('invoices.reminders.list', ['workspace' => $workspace_id, 'invoice' => $invoice_id]);
    }

    /** @return array<string, mixed> */
    public function send(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->rest->call('invoices.send_reminder', ['workspace' => $workspace_id, 'invoice' => $invoice_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function company(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id): array
    {
        return $this->rest->call('companies.payment_reminders.get', ['workspace' => $workspace_id, 'client' => $client_id]);
    }

    /** @return array<string, mixed> */
    public function setCompany(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $enabled,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->rest->call('companies.payment_reminders.set', ['workspace' => $workspace_id, 'client' => $client_id], compact('expected_version', 'enabled'), $idempotency_key);
    }
}
