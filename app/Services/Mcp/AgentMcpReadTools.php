<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Thin MCP adapter over AgentReadService; it never re-enters an HTTP route.
 *
 * The optional context exists only so contract-schema tests can reflect the
 * handlers without manufacturing an authenticated Passport credential. The
 * server factory always supplies an immutable request context before use.
 */
final class AgentMcpReadTools
{
    public function __construct(
        private readonly AgentReadService $reads,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function context(): array
    {
        $context = $this->requestContext();
        $this->requireScope($context, 'identity:read');

        return ['data' => $this->reads->context($context->principal->subject, fn (string $scope): bool => $context->principal->hasScope($scope))];
    }

    /** @return array<string, mixed> */
    public function summary(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        $context = $this->workspace($workspace_id, 'identity:read');

        return ['data' => $this->reads->summary($context->principal->subject, $context->workspace, fn (string $scope): bool => $context->principal->hasScope($scope))];
    }

    /** @return array<string, mixed> */
    public function projects(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(enum: ['active', 'archived', 'completed'])] ?string $status = null,
        #[Schema(maxLength: 200)] ?string $query = null,
    ): array {
        $context = $this->workspace($workspace_id, 'projects:read');

        return $this->reads->projects($context->principal->subject, $context->workspace, $limit, $cursor, $status, $query);
    }

    /** @return array<string, mixed> */
    public function project(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id): array
    {
        $context = $this->workspace($workspace_id, 'projects:read');

        return ['data' => $this->reads->project($context->principal->subject, $context->workspace, $project_id, $context->principal->hasScope('tasks:read'))];
    }

    /** @return array<string, mixed> */
    public function tasks(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'tasks:read');

        return $this->reads->tasks($context->principal->subject, $context->workspace, $project_id, $limit, $cursor);
    }

    /** @return array<string, mixed> */
    public function task(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id): array
    {
        $context = $this->workspace($workspace_id, 'tasks:read');

        return ['data' => $this->reads->task($context->principal->subject, $context->workspace, $task_id)];
    }

    /** @return array<string, mixed> */
    public function timeEntries(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(enum: ['draft', 'approved', 'invoiced'])] ?string $status = null,
        #[Schema(format: 'date')] ?string $from = null,
        #[Schema(format: 'date')] ?string $to = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'time:read');

        return $this->reads->timeEntries($context->principal->subject, $context->workspace, $project_id, $status, $from, $to, $limit, $cursor);
    }

    /** @return array<string, mixed> */
    public function invoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['draft', 'issued', 'partially_paid', 'paid', 'void'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'billing:read');

        return $this->reads->invoices($context->principal->subject, $context->workspace, $status, $limit, $cursor);
    }

    /** @return array<string, mixed> */
    public function invoice(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        $context = $this->workspace($workspace_id, 'billing:read');

        return ['data' => $this->reads->invoice($context->principal->subject, $context->workspace, $invoice_id)];
    }

    private function requestContext(): McpRequestContext
    {
        return $this->requestContext ?? throw new LogicException('MCP read tools require a request context.');
    }

    private function workspace(string $workspaceId, string $scope): McpRequestContext
    {
        $context = $this->requestContext();
        $this->requireScope($context, $scope);

        return $this->accounts->resolve($context, $workspaceId);
    }

    private function requireScope(McpRequestContext $context, string $scope): void
    {
        if (! $context->principal->hasScope($scope)) {
            throw new ToolCallException('This connection lacks the required permission.');
        }
    }
}
