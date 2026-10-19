<?php

namespace App\Services\Mcp;

use App\Services\Square\Mirror\SquareMirrorReadService;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * svc's Square mirror over MCP (#438): the same REST operations, receipts and
 * refusals, through the internal transport.
 */
final class AgentMcpSquareMirrorTools
{
    private const string ID = '^[A-Za-z0-9][A-Za-z0-9:_-]*$';

    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @return array<string, mixed> */
    public function sync(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/square/sync", [], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function reconcileInvoice(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-reconcile", [], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $location_id = null,
        #[Schema(enum: SquareMirrorReadService::STATUSES)] ?string $status = null,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $customer_id = null,
        #[Schema(pattern: '^[0-9]{6}$')] ?string $series_key = null,
        #[Schema(format: 'date')] ?string $due_from = null,
        #[Schema(format: 'date')] ?string $due_to = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->send('GET', "workspaces/{$workspace_id}/square/mirror/invoices", null, query: array_filter(
            compact('location_id', 'status', 'customer_id', 'series_key', 'due_from', 'due_to', 'limit', 'cursor'),
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /** @return array<string, mixed> */
    public function series(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $location_id = null,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] ?string $customer_id = null,
        #[Schema(minimum: 1, maximum: 50)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->send('GET', "workspaces/{$workspace_id}/square/series", null, query: array_filter(
            compact('location_id', 'customer_id', 'limit', 'cursor'),
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /** @return array<string, mixed> */
    public function seriesDetail(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(pattern: '^[0-9]{6}$')] string $series_key,
    ): array {
        return $this->send('GET', "workspaces/{$workspace_id}/square/series/".rawurlencode($series_key), null);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body, ?string $key = null, array $query = []): array
    {
        // A query string carries text; the REST route reads it as given.
        $response = $this->api->send($method, $path, query: array_map(strval(...), $query), json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }

    /** @return array<string, mixed> */
    public function candidates(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        bool $include_canceled = false,
        #[Schema(minimum: 1, maximum: 25)] int $limit = 10,
    ): array {
        return $this->send('GET', "workspaces/{$workspace_id}/invoices/{$invoice_id}/square-candidates", null, query: [
            'include_canceled' => $include_canceled ? 'true' : 'false', 'limit' => $limit,
        ]);
    }

    /** @return array<string, mixed> */
    public function unlinkedCustomers(
        #[Schema(format: 'uuid')] string $workspace_id,
        ?bool $with_open_balance = null,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 100,
    ): array {
        $query = ['limit' => $limit];
        if ($with_open_balance !== null) {
            $query['with_open_balance'] = $with_open_balance ? 'true' : 'false';
        }

        return $this->send('GET', "workspaces/{$workspace_id}/square/customers/unlinked", null, query: $query);
    }

    /** @return array<string, mixed> */
    public function importCustomer(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 192, pattern: self::ID)] string $customer_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        bool $confirm,
        ?string $company_name = null,
        ?string $billing_email = null,
        ?string $series_key = null,
        ?string $agreement_title = null,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/square/customers/".rawurlencode($customer_id).'/import', array_filter(
            compact('confirm', 'company_name', 'billing_email', 'series_key', 'agreement_title'),
            static fn (mixed $value): bool => $value !== null,
        ), $idempotency_key);
    }
}
