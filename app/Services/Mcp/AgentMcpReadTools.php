<?php

namespace App\Services\Mcp;

use App\Exceptions\InvalidAgentApiCursor;
use App\Services\AgentApi\AgentExpenseReadService;
use App\Services\AgentApi\AgentInvoiceReviewReadService;
use App\Services\AgentApi\AgentPaymentReadService;
use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use App\Support\AgentApi\InvoiceListFilters;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
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
    public function expensesList(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $company_id = null,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(enum: ['draft', 'approved', 'invoiced'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'expenses:read');
        try {
            return app(AgentExpenseReadService::class)->list($context->principal->subject, $context->workspace, $company_id, $project_id, $status, $limit, $cursor, $context->principal->hasScope('expenses:write'));
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

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

        try {
            return $this->reads->projects($context->principal->subject, $context->workspace, $limit, $cursor, $status, $query);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
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

        try {
            return $this->reads->tasks($context->principal->subject, $context->workspace, $project_id, $limit, $cursor);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
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
        #[Schema(enum: ['unallocated', 'reserved', 'consumed'])] ?string $allocation_state = null,
        ?bool $is_billable = null,
        bool $unallocated = false,
    ): array {
        $context = $this->workspace($workspace_id, 'time:read');

        try {
            return $this->reads->timeEntries($context->principal->subject, $context->workspace, $project_id, $status, $from, $to, $limit, $cursor, $allocation_state, $is_billable, $unallocated);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

    /**
     * @param  array{from: string, to: string}|null  $service_period_overlaps
     * @return array<string, mixed>
     */
    public function invoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['draft', 'issued', 'partially_paid', 'paid', 'void'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'uuid')] ?string $company_id = null,
        #[Schema(enum: ['cadence_period', 'interim_overage', 'terminal', 'ad_hoc'])] ?string $invoice_kind = null,
        #[Schema(format: 'date')] ?string $issue_date_from = null,
        #[Schema(format: 'date')] ?string $issue_date_to = null,
        #[Schema(format: 'date')] ?string $due_date_from = null,
        #[Schema(format: 'date')] ?string $due_date_to = null,
        #[Schema(type: 'object', properties: ['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']], required: ['from', 'to'], additionalProperties: false)] ?array $service_period_overlaps = null,
        ?bool $collectible = null,
        ?bool $overdue = null,
    ): array {
        $context = $this->workspace($workspace_id, 'billing:read');

        try {
            return $this->reads->invoices($context->principal->subject, $context->workspace, $status, $limit, $cursor, InvoiceListFilters::from(array_filter(compact('company_id', 'invoice_kind', 'issue_date_from', 'issue_date_to', 'due_date_from', 'due_date_to', 'service_period_overlaps', 'collectible', 'overdue'), static fn (mixed $value): bool => $value !== null)));
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

    /** @return array<string, mixed> */
    public function invoice(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        $context = $this->workspace($workspace_id, 'billing:read');

        return ['data' => $this->reads->invoice($context->principal->subject, $context->workspace, $invoice_id)];
    }

    /** @return array<string, mixed> */
    public function paymentsList(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $invoice_id = null,
        #[Schema(format: 'uuid')] ?string $company_id = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'payments:read');
        try {
            return app(AgentPaymentReadService::class)->listing(
                $context->principal->subject, $context->workspace, $invoice_id, $company_id, $limit, $cursor);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

    /** @return array<string, mixed> */
    public function invoicesPdf(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        $this->workspace($workspace_id, 'billing:read');
        $response = app(InternalAgentApiTransport::class)->send('GET', "workspaces/{$workspace_id}/invoices/{$invoice_id}/pdf-link");
        if ($response->status !== 200 || $response->json === null) {
            throw new ToolCallException('The invoice PDF is not available to this connection.');
        }

        return $response->json;
    }

    /** @return array<string, mixed> */
    public function billingAuditStaleAndMissing(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        $context = $this->workspace($workspace_id, 'billing:read');

        return ['data' => app(AgentInvoiceReviewReadService::class)->staleAndMissing($context->principal->subject, $context->workspace)->toArray()];
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
