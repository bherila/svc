<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/** New organization tools dispatch through the identical registry REST binding. */
final class AgentMcpTaskOrganizationTools
{
    private const array TASK_INPUT = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title'], 'properties' => [
        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        'description' => ['type' => ['string', 'null'], 'maxLength' => 10000],
        'is_visible_to_client' => ['type' => 'boolean'],
        'starts_on' => ['type' => ['string', 'null'], 'format' => 'date'],
        'due_on' => ['type' => ['string', 'null'], 'format' => 'date'],
        'assignee_ids' => ['type' => 'array', 'maxItems' => 10, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']],
    ]];

    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string,mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        bool $container = false, #[Schema(format: 'uuid')] ?string $parent_id = null, #[Schema(format: 'uuid')] ?string $list_id = null): array
    {
        return $this->rest->call('task_organization.get', ['workspace' => $workspace_id, 'project' => $project_id], query: compact('container', 'parent_id', 'list_id'));
    }

    /** @return array<string,mixed> */
    public function listsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(minLength: 1, maxLength: 255)] string $name, #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request, ?bool $is_visible_to_client = null): array
    {
        return $this->rest->call('task_organization.lists.create', ['workspace' => $workspace_id, 'project' => $project_id],
            compact('name', 'expected_graph_version') + $this->provided($request, compact('is_visible_to_client')), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function listsUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $list_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request, #[Schema(minLength: 1, maxLength: 255)] ?string $name = null, ?bool $is_visible_to_client = null): array
    {
        return $this->rest->call('task_organization.lists.update', ['workspace' => $workspace_id, 'project' => $project_id, 'list' => $list_id],
            compact('expected_graph_version', 'expected_version') + $this->provided($request, compact('name', 'is_visible_to_client')), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function listsArchive(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $list_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.lists.archive', ['workspace' => $workspace_id, 'project' => $project_id, 'list' => $list_id],
            compact('expected_graph_version', 'expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function listsRestore(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $list_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.lists.restore', ['workspace' => $workspace_id, 'project' => $project_id, 'list' => $list_id],
            compact('expected_graph_version', 'expected_version'), $idempotency_key);
    }

    /** @param list<string> $ordered_ids
     * @return array<string,mixed> */
    public function listsReorder(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(maxItems: 100, uniqueItems: true, items: ['type' => 'string', 'format' => 'uuid'])] array $ordered_ids,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_container_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.lists.reorder', ['workspace' => $workspace_id, 'project' => $project_id],
            compact('expected_container_version', 'ordered_ids'), $idempotency_key);
    }

    /** @param array<string,mixed> $task
     * @return array<string,mixed> */
    public function tasksCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(definition: self::TASK_INPUT)] array $task, #[Schema(format: 'uuid')] ?string $parent_id, #[Schema(format: 'uuid')] ?string $list_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.tasks.create', ['workspace' => $workspace_id, 'project' => $project_id],
            compact('expected_graph_version', 'task', 'parent_id', 'list_id'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function tasksMove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $task_id, #[Schema(format: 'uuid')] ?string $parent_id, #[Schema(format: 'uuid')] ?string $list_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_graph_version, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.tasks.move', ['workspace' => $workspace_id, 'project' => $project_id, 'task' => $task_id],
            compact('expected_graph_version', 'expected_version', 'parent_id', 'list_id'), $idempotency_key);
    }

    /** @param list<string> $ordered_ids
     * @return array<string,mixed> */
    public function tasksReorder(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] ?string $parent_id, #[Schema(format: 'uuid')] ?string $list_id,
        #[Schema(maxItems: 100, uniqueItems: true, items: ['type' => 'string', 'format' => 'uuid'])] array $ordered_ids,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_container_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('task_organization.tasks.reorder', ['workspace' => $workspace_id, 'project' => $project_id],
            compact('expected_container_version', 'ordered_ids', 'parent_id', 'list_id'), $idempotency_key);
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed> */
    private function provided(RequestContext $request, array $values): array
    {
        return array_filter($values, fn (mixed $value, string $name): bool => $this->arguments->has($request, $name), ARRAY_FILTER_USE_BOTH);
    }
}
