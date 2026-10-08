<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\ClientMutationRules;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/** MCP writes use the REST routes, including their authorization, versions and receipts. */
final class AgentMcpClientWriteTools
{
    public function __construct(
        private readonly InternalAgentApiTransport $api,
        private readonly RequestArguments $arguments,
    ) {}

    /** @return array<string, mixed> */
    public function clientsCreate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(minLength: 1, maxLength: 160)] string $name,
        RequestContext $request,
        #[Schema(maxLength: 255)] ?string $billing_email = null,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/clients", compact('name') + $this->provided($request, ['billing_email']), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function clientsUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->send('PATCH', "workspaces/{$workspace_id}/clients/{$client_id}", compact('expected_version') + $this->provided($request, array_keys(ClientMutationRules::clientUpdate())), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function clientsArchive(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/clients/{$client_id}/archive", compact('expected_version'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function clientsRestore(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/clients/{$client_id}/restore", compact('expected_version'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function agreementsCreate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/clients/{$client_id}/agreements", compact('expected_version') + $this->provided($request, array_keys(ClientMutationRules::agreementCreate())), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function agreementsUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->send('PATCH', "workspaces/{$workspace_id}/agreements/{$agreement_id}", compact('expected_version') + $this->provided($request, array_keys(ClientMutationRules::agreementUpdate())), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function agreementsActivate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/agreements/{$agreement_id}/activate", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function agreementsTerminate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(format: 'date')] ?string $ends_on = null,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/agreements/{$agreement_id}/terminate", compact('expected_version', 'confirm') + $this->provided($request, ['ends_on']), $idempotency_key);
    }

    /** @param list<string> $fields
     * @return array<string, mixed> */
    private function provided(RequestContext $request, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            if ($this->arguments->has($request, $field)) {
                $values[$field] = $this->arguments->value($request, $field, null);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    private function send(string $method, string $path, array $body, string $key): array
    {
        $response = $this->api->send($method, $path, json: $body, headers: ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
