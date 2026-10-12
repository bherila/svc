<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * SVC's Square links over MCP (#438): the same REST operations, receipts and
 * refusals. Nothing here reaches Square.
 */
final class AgentMcpSquareLinkTools
{
    public function __construct(private readonly InternalAgentApiTransport $api, private readonly RequestArguments $arguments) {}

    /** @param list<string>|null $reasons
     * @return array<string, mixed> */
    public function link(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(maxLength: 191)] string $square_invoice_id,
        #[Schema(maxLength: 191)] string $square_invoice_number,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        ?string $currency = null,
        string $match_method = 'manual',
        ?int $score = null,
        ?array $reasons = null,
        bool $replace = false,
    ): array {
        $body = compact('expected_version', 'square_invoice_id', 'square_invoice_number');
        foreach (compact('currency', 'match_method', 'score', 'reasons', 'replace') as $name => $value) {
            if ($this->arguments->has($request, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-link", $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function unlink(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('DELETE', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-link", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function customer(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id): array
    {
        return $this->send('GET', "workspaces/{$workspace_id}/clients/{$client_id}/square-customer", null);
    }

    /** @return array<string, mixed> */
    public function setCustomer(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(maxLength: 191)] string $square_customer_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('PUT', "workspaces/{$workspace_id}/clients/{$client_id}/square-customer", compact('expected_version', 'square_customer_id'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function clearCustomer(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('DELETE', "workspaces/{$workspace_id}/clients/{$client_id}/square-customer", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function markAgreement(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(pattern: '^[0-9]{6}$')] string $series_key,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('PUT', "workspaces/{$workspace_id}/agreements/{$agreement_id}/square-series", compact('expected_version', 'series_key', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function clearAgreement(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('DELETE', "workspaces/{$workspace_id}/agreements/{$agreement_id}/square-series", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @param array<string, mixed>|null $body
     * @return array<string, mixed> */
    private function send(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $response = $this->api->send($method, $path, json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
