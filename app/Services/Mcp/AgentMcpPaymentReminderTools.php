<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Payment reminders over MCP (#438): the same REST operations, receipts and
 * refusals.
 */
final class AgentMcpPaymentReminderTools
{
    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        return $this->request('GET', "workspaces/{$workspace_id}/invoices/{$invoice_id}/reminders", null);
    }

    /** @return array<string, mixed> */
    public function send(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->request('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/reminders", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function company(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id): array
    {
        return $this->request('GET', "workspaces/{$workspace_id}/clients/{$client_id}/payment-reminders", null);
    }

    /** @return array<string, mixed> */
    public function setCompany(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $enabled,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->request('PUT', "workspaces/{$workspace_id}/clients/{$client_id}/payment-reminders", compact('expected_version', 'enabled'), $idempotency_key);
    }

    /** @param array<string, mixed>|null $body
     * @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $response = $this->api->send($method, $path, json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
