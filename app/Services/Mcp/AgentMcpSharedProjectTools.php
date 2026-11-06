<?php

namespace App\Services\Mcp;

use App\Services\SharedProjects\SharedProjectCollaboration;
use App\Services\SharedProjects\SharedProjectReadService;
use Mcp\Capability\Attribute\Schema;

/**
 * The shared project tools. Each calls its REST operation, so the host-or-
 * participation rule, the viewer's role, the idempotency receipt and the audit
 * are the route's and cannot differ between the two transports.
 */
final class AgentMcpSharedProjectTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->rest->call('shared_projects.list', ['workspace' => $workspace_id]);
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id): array
    {
        return $this->rest->call('shared_projects.get', ['workspace' => $workspace_id, 'project' => $project_id]);
    }

    /** @return array<string, mixed> */
    public function tasks(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(minimum: 1, maximum: SharedProjectReadService::PAGE)] int $limit = SharedProjectReadService::PAGE,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('shared_projects.tasks.list', ['workspace' => $workspace_id, 'project' => $project_id],
            query: array_map(strval(...), array_filter(compact('limit', 'cursor'), static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @param  list<array{user_id: string, workspace_id: string}>  $assignees
     * @return array<string, mixed>
     */
    public function assign(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $task_id,
        #[Schema(maxItems: SharedProjectCollaboration::ASSIGNEES)] array $assignees,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('shared_projects.tasks.assign', ['workspace' => $workspace_id, 'project' => $project_id, 'task' => $task_id],
            ['assignees' => $assignees], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function comments(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $task_id,
        #[Schema(minimum: 1, maximum: SharedProjectReadService::PAGE)] int $limit = SharedProjectReadService::PAGE,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('shared_projects.comments.list', ['workspace' => $workspace_id, 'project' => $project_id, 'task' => $task_id],
            query: array_map(strval(...), array_filter(compact('limit', 'cursor'), static fn (mixed $value): bool => $value !== null)));
    }

    /** @return array<string, mixed> */
    public function commentCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id,
        #[Schema(format: 'uuid')] string $task_id, #[Schema(minLength: 1, maxLength: 50000)] string $body,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('shared_projects.comments.create', ['workspace' => $workspace_id, 'project' => $project_id, 'task' => $task_id],
            ['body' => $body], $idempotency_key);
    }
}
