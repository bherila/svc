<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

final class AgentMcpDeferredBuyDownTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function preview(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id,
        RequestContext $request): array
    {
        return $this->rest->call('deferred_backlog.preview', ['workspace' => $workspace_id, 'agreement' => $agreement_id],
            query: $this->provided($request));
    }

    /** @return array<string, mixed> */
    public function create(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_backlog_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
    ): array {
        return $this->rest->call('invoices.create_deferred_buydown', ['workspace' => $workspace_id, 'agreement' => $agreement_id],
            compact('expected_backlog_version') + $this->provided($request), $idempotency_key);
    }

    /** @return array<string, mixed> */
    private function provided(RequestContext $request): array
    {
        $values = [];
        foreach (['hours', 'amount', 'round_up', 'discount_amount', 'discount_reason'] as $field) {
            if ($this->arguments->has($request, $field)) {
                $values[$field] = $this->arguments->value($request, $field, null);
            }
        }

        return $values;
    }
}
