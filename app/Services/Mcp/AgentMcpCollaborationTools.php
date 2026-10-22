<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/** Every tool enters its registered REST operation, including scopes, cutovers and receipts. */
final class AgentMcpCollaborationTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function topicsList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['note', 'discussion'])] ?string $kind = null, #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(minimum: 1, maximum: 100000)] int $page = 1): array
    {
        return $this->rest->call('collaboration.topics.list', ['workspace' => $workspace_id, 'client' => $client_id],
            query: array_filter(compact('kind', 'project_id', 'page'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function topicsGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $topic_id): array
    {
        return $this->rest->call('collaboration.topics.get', ['workspace' => $workspace_id, 'client' => $client_id, 'topic' => $topic_id]);
    }

    /** @return array<string, mixed> */
    public function topicsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['note', 'discussion'])] string $kind, #[Schema(minLength: 1, maxLength: 255)] string $title,
        #[Schema(minLength: 1, maxLength: 50000)] string $body, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('collaboration.topics.create', ['workspace' => $workspace_id, 'client' => $client_id],
            compact('kind', 'title', 'body') + $this->provided($request, ['project_id', 'is_visible_to_client']), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function topicsUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $topic_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('collaboration.topics.update', ['workspace' => $workspace_id, 'client' => $client_id, 'topic' => $topic_id],
            compact('expected_version') + $this->provided($request, ['title', 'body', 'is_visible_to_client']), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function topicsDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $topic_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('collaboration.topics.delete', ['workspace' => $workspace_id, 'client' => $client_id, 'topic' => $topic_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function commentsList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['task', 'topic'])] string $parent_type, #[Schema(format: 'uuid')] string $parent_id,
        #[Schema(minimum: 1, maximum: 30)] int $limit = 1, #[Schema(minimum: 1, maximum: 100000)] int $comments_page = 1,
        #[Schema(format: 'uuid')] ?string $comment = null): array
    {
        return $this->rest->call('collaboration.comments.list', ['workspace' => $workspace_id, 'client' => $client_id, 'parentType' => $parent_type, 'parentPublicId' => $parent_id],
            query: array_filter(compact('limit', 'comments_page', 'comment'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function commentsCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['task', 'topic'])] string $parent_type, #[Schema(format: 'uuid')] string $parent_id,
        #[Schema(minLength: 1, maxLength: 50000)] string $body, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('collaboration.comments.create', ['workspace' => $workspace_id, 'client' => $client_id, 'parentType' => $parent_type, 'parentPublicId' => $parent_id],
            compact('body', 'expected_version') + $this->provided($request, ['is_visible_to_client']), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function commentsUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $comment_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('collaboration.comments.update', ['workspace' => $workspace_id, 'client' => $client_id, 'comment' => $comment_id],
            compact('expected_version') + $this->provided($request, ['body', 'is_visible_to_client']), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function commentsDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $comment_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('collaboration.comments.delete', ['workspace' => $workspace_id, 'client' => $client_id, 'comment' => $comment_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
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
}
