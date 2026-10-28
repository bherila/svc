<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** REST-routed review and confirmed recording share the operation's authorization and receipt. */
final class AgentMcpSquareCoveredPaymentTools
{
    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @return array<string,mixed> */
    public function review(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(maxLength: 191)] string $square_invoice_id): array
    {
        return $this->send('GET', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-coverage/{$square_invoice_id}/payments");
    }

    /** @return array<string,mixed> */
    public function record(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(maxLength: 191)] string $square_invoice_id, #[Schema(maxLength: 191)] string $payment_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 64, maxLength: 64)] string $reviewed_version,
        #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-coverage/{$square_invoice_id}/payments/{$payment_id}",
            compact('expected_version', 'reviewed_version', 'confirm'), $idempotency_key);
    }

    /** @param array<string,mixed>|null $body
     * @return array<string,mixed> */
    private function send(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $response = $this->api->send($method, $path, json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
