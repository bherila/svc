<?php

namespace App\Services\Mcp;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\DeleteTimeEntryAction;
use App\Services\AgentApi\LogTimeEntriesAction;
use App\Services\AgentApi\UpdateTimeEntryAction;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

final class AgentMcpWriteTools
{
    public function __construct(
        private readonly InternalAgentApiTransport $api,
        private readonly RequestArguments $requestArguments,
        private readonly LogTimeEntriesAction $logTime,
        private readonly UpdateTimeEntryAction $updateTime,
        private readonly DeleteTimeEntryAction $deleteTime,
        private readonly AgentTimeEntryPresenter $timeEntries,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    public function forContext(McpRequestContext $context): self
    {
        return new self(
            $this->api,
            $this->requestArguments,
            $this->logTime,
            $this->updateTime,
            $this->deleteTime,
            $this->timeEntries,
            $this->accounts,
            $context,
        );
    }

    /** @param list<array<string, mixed>> $entries
     * @return array<string, mixed> */
    public function timeEntriesLog(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 20)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        $context = $this->context('time:write');
        $context = $this->accounts->resolve($context, $workspace_id);
        $workspace = $context->workspace;
        if (! $workspace instanceof Workspace) {
            throw new \LogicException('MCP time logging requires a workspace context.');
        }
        $actor = User::query()->findOrFail($context->principal->subject->id);
        $ids = $this->logTime->run(
            $actor,
            $workspace,
            $context->principal->clientId,
            $idempotency_key,
            $entries,
        );
        $entriesById = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('public_id', $ids)
            ->with('project')
            ->get()
            ->keyBy('public_id');

        return ['data' => collect($ids)->map(function (string $id) use ($entriesById, $workspace): array {
            $entry = $entriesById->get($id);
            if (! $entry instanceof ClientTimeEntry) {
                throw new ToolCallException('The SVC API request could not be completed.');
            }

            return $this->timeEntries->present($workspace, $entry);
        })->values()->all()];
    }

    /** @return array<string, mixed> */
    public function timeEntriesUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $entry_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        #[Schema(format: 'date')] ?string $worked_on = null,
        #[Schema(minimum: 1, maximum: 1440)] ?int $minutes = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        ?bool $is_billable = null,
        ?bool $is_deferred = null,
        ?bool $is_visible_to_client = null,
        #[Schema(maxLength: 10000)] ?string $client_visible_description = null,
    ): array {
        $body = compact('expected_version');
        foreach (compact('worked_on', 'minutes', 'description', 'is_billable', 'is_deferred', 'is_visible_to_client', 'client_visible_description') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        $context = $this->workspaceContext($workspace_id, 'time:write');
        $workspace = $this->workspace($context);
        $actor = User::query()->findOrFail($context->principal->subject->id);
        $entry = $this->updateTime->run($actor, $workspace, $context->principal->clientId, $idempotency_key, $entry_id, $body);

        return ['data' => $this->timeEntries->present($workspace, $entry)];
    }

    /** @return array<string, mixed> */
    public function timeEntriesDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $entry_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        $context = $this->workspaceContext($workspace_id, 'time:write');
        $workspace = $this->workspace($context);
        $actor = User::query()->findOrFail($context->principal->subject->id);
        $id = $this->deleteTime->run($actor, $workspace, $context->principal->clientId, $idempotency_key, $entry_id, $expected_version);

        return ['data' => ['deleted_id' => $id]];
    }

    /** @param list<array{id: string, expected_version: string}> $entries
     * @return array<string, mixed> */
    public function timeEntriesApprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 100)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/time-entries/approve", compact('entries'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function tasksCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(minLength: 1, maxLength: 255)] string $title, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, ?string $description = null, ?bool $is_visible_to_client = null): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/projects/{$project_id}/tasks", array_filter(compact('title', 'description', 'is_visible_to_client'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function tasksUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $task_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 255)] ?string $title = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        #[Schema(enum: ['open', 'in_progress', 'completed'])] ?string $status = null,
        ?bool $is_visible_to_client = null,
    ): array {
        $body = compact('expected_version');
        foreach (compact('title', 'description', 'status', 'is_visible_to_client') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->send('PATCH', "workspaces/{$workspace_id}/tasks/{$task_id}", $body, $idempotency_key);
    }

    /** @param list<string> $time_entry_ids
     * @param list<array<string, mixed>> $manual_lines
     * @return array<string, mixed> */
    public function invoicesCreateDraft(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $company_id, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, #[Schema(maxItems: 100)] array $time_entry_ids = [], #[Schema(maxItems: 100)] array $manual_lines = [], ?string $currency = null, ?string $due_date = null, ?string $notes = null): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices", array_filter(compact('company_id', 'time_entry_ids', 'manual_lines', 'currency', 'due_date', 'notes'), static fn (mixed $value): bool => $value !== null), $idempotency_key);
    }

    /** @param list<string> $time_entry_ids
     * @param list<array<string, mixed>> $manual_lines
     * @return array<string, mixed> */
    public function invoicesUpdateDraft(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $invoice_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        #[Schema(maxItems: 100)] array $time_entry_ids,
        #[Schema(maxItems: 100)] array $manual_lines,
        RequestContext $context,
        ?string $currency = null,
        ?string $due_date = null,
        ?string $notes = null,
    ): array {
        $body = compact('expected_version', 'time_entry_ids', 'manual_lines');
        foreach (compact('currency', 'due_date', 'notes') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->send('PATCH', "workspaces/{$workspace_id}/invoices/{$invoice_id}", $body, $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesDiscardDraft(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 1000)] string $reason, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/discard", compact('expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesIssue(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/issue", compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @param list<string> $recipients
     * @return array<string, mixed> */
    public function invoicesSend(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minItems: 1, maxItems: 10)] array $recipients, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/send", compact('expected_version', 'recipients', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function invoicesVoid(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 1000)] string $reason, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/void", compact('expected_version', 'reason', 'confirm'), $idempotency_key);
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    private function send(string $method, string $path, array $body, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
        $response = $this->api->send($method, $path, json: $body, headers: $headers);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException($response->status === 403 ? 'This connection lacks the required permission.' : 'The SVC API request could not be completed.');
    }

    private function context(string $scope): McpRequestContext
    {
        $context = $this->requestContext ?? throw new \LogicException('MCP write tools require a request context.');
        if (! $context->principal->hasScope($scope)) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $context;
    }

    private function workspaceContext(string $workspaceId, string $scope): McpRequestContext
    {
        $context = $this->accounts->resolve($this->context($scope), $workspaceId);
        if (! $context->workspace instanceof Workspace) {
            throw new \LogicException('MCP write tools require a workspace context.');
        }

        return $context;
    }

    private function workspace(McpRequestContext $context): Workspace
    {
        $workspace = $context->workspace;
        if (! $workspace instanceof Workspace) {
            throw new \LogicException('MCP write tools require a workspace context.');
        }

        return $workspace;
    }
}
