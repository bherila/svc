<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpCollaborationTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentRestDocumentation::class)]
final class CollaborationOperationContractTest extends TestCase
{
    private const array OPERATIONS = [
        'topics.list' => ['topicsList', 'GET', null, 'CollaborationTopicListResponse', '/topics'],
        'topics.get' => ['topicsGet', 'GET', null, 'CollaborationTopicResponse', '/topics/{topic}'],
        'topics.create' => ['topicsCreate', 'POST', 'CollaborationTopicCreateRequest', 'CollaborationTopicResponse', '/topics'],
        'topics.update' => ['topicsUpdate', 'PATCH', 'CollaborationTopicUpdateRequest', 'CollaborationTopicResponse', '/topics/{topic}'],
        'topics.delete' => ['topicsDelete', 'DELETE', 'CollaborationDeleteRequest', 'CollaborationTopicResponse', '/topics/{topic}'],
        'comments.list' => ['commentsList', 'GET', null, 'CollaborationCommentListResponse', '/comments/{parentType}/{parentPublicId}'],
        'comments.create' => ['commentsCreate', 'POST', 'CollaborationCommentCreateRequest', 'CollaborationCommentResponse', '/comments/{parentType}/{parentPublicId}'],
        'comments.update' => ['commentsUpdate', 'PATCH', 'CollaborationCommentUpdateRequest', 'CollaborationCommentResponse', '/comments/{comment}'],
        'comments.delete' => ['commentsDelete', 'DELETE', 'CollaborationDeleteRequest', 'CollaborationCommentResponse', '/comments/{comment}'],
    ];

    public function test_mcp_and_rest_keep_current_parent_authority_and_versioned_confirmed_deletions(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            foreach (self::OPERATIONS as $name => [$handler, $method, $request, , $suffix]) {
                $id = 'collaboration.'.$name;
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertOperation($catalog, $operation, $name, $handler, $method, $request, $suffix);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    /** @param 'GET'|'POST'|'PATCH'|'DELETE' $method */
    private function assertOperation(AgentOperationCatalog $catalog, Operation $operation, string $name, string $handler, string $method, ?string $request, string $suffix): void
    {
        $id = 'collaboration.'.$name;
        $write = $method !== 'GET';
        $delete = $method === 'DELETE';
        $versioned = $write && $name !== 'topics.create';
        $scopes = match ($method) {
            'GET' => ['collaboration:read'],
            'POST' => $name === 'topics.create' ? ['collaboration:write'] : ['collaboration:write', 'collaboration:read'],
            'PATCH', 'DELETE' => ['collaboration:write', 'collaboration:read'],
        };
        $effect = match ($method) {
            'GET' => Effect::Read, 'DELETE' => Effect::Destructive, 'POST', 'PATCH' => Effect::LocalWrite,
        };
        $idempotency = match ($method) {
            'GET' => IdempotencyKey::None, 'POST', 'PATCH', 'DELETE' => IdempotencyKey::HeaderAndArgument,
        };
        $specificFlags = match ($method) {
            'GET' => [], 'POST', 'PATCH', 'DELETE' => [AgentDeploymentFlags::COLLABORATION],
        };
        $switch = match ($method) {
            'GET' => 'read', 'POST', 'PATCH', 'DELETE' => 'write',
        };
        $flags = [...$specificFlags, 'mcp', 'mcp.switch:mcp.'.$switch, 'mcp.switch:'.$id];
        $cutovers = match ($method) {
            'GET' => [], 'POST', 'PATCH', 'DELETE' => ['AGENT_API_WRITES_ENABLED', 'AGENT_API_COLLABORATION_WRITES_ENABLED'],
        };
        $this->assertSame([AgentMcpCollaborationTools::class, $handler], $operation->mcp->handler, $id);
        $this->assertSame($method, $operation->rest->method, $id);
        $this->assertSame('/workspaces/{workspace}/clients/{client}/collaboration'.$suffix, $operation->rest->path, $id);
        $this->assertSame($method === 'POST' ? [201] : [200], $operation->rest->successStatuses, $id);
        $this->assertSame($scopes, $operation->requirement->scopes, $id);
        $this->assertSame($effect, $operation->effect, $id);
        $this->assertTrue($operation->isIdempotent(), $id);
        // Project contributors can comment; management is decided by
        // the current parent action, never a blanket workspace role.
        $this->assertFalse($catalog->isManagerOnly($id), $id);
        $this->assertSame($versioned, $operation->safety->expectedVersion, $id);
        $this->assertSame($delete, $operation->safety->confirm, $id);
        $this->assertSame($idempotency, $operation->safety->idempotencyKey, $id);
        $this->assertSame($flags, $operation->requirement->flags, $id);
        $this->assertSame($cutovers, $operation->extensions['x-svc-flags'], $id);
        if ($request === null) {
            $this->assertNull($operation->input, $id);
        } else {
            $this->assertInstanceOf(SchemaRef::class, $operation->input, $id);
            $this->assertSame($id, $operation->input->requestOf, $id);
        }
        $this->assertInstanceOf(SchemaRef::class, $operation->output, $id);
        $this->assertSame($id, $operation->output->responseOf, $id);
    }

    public function test_rest_contract_preserves_tenant_parent_scopes_and_full_comment_continuation(): void
    {
        $documents = AgentRestDocumentation::operations();
        foreach (self::OPERATIONS as $name => [, $method, $request, $response]) {
            $id = 'collaboration.'.$name;
            $document = $documents[$id];
            $parameters = ['WorkspaceId', $this->identity('client')];
            if (in_array($name, ['topics.get', 'topics.update', 'topics.delete'], true)) {
                $parameters[] = $this->identity('topic');
            }
            if (in_array($name, ['comments.list', 'comments.create'], true)) {
                $parameters[] = ['name' => 'parentType', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['task', 'topic']]];
                $parameters[] = $this->identity('parentPublicId');
            }
            if (in_array($name, ['comments.update', 'comments.delete'], true)) {
                $parameters[] = $this->identity('comment');
            }
            if ($method !== 'GET') {
                $parameters[] = 'IdempotencyKey';
            }
            if ($name === 'topics.list') {
                $parameters = [...$parameters,
                    ['name' => 'kind', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['note', 'discussion']]],
                    ['name' => 'project_id', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000]],
                ];
            }
            if ($name === 'comments.list') {
                $parameters = [...$parameters,
                    ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 1]],
                    ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 4096]],
                ];
            }
            $scopes = $method === 'GET' ? ['collaboration:read'] : ($name === 'topics.create' ? ['collaboration:write'] : ['collaboration:write', 'collaboration:read']);
            $this->assertSame($method, $document['method'], $id);
            $this->assertSame($scopes, $document['scopes'], $id);
            $this->assertSame($parameters, $document['parameters'], $id);
            $this->assertArrayHasKey('response', $document, $id);
            $this->assertSame($response, $document['response'] ?? null, $id);
            $this->assertArrayHasKey('errors', $document, $id);
            $this->assertSame([401, 403, 404, 409, 422, 429], array_keys($document['errors'] ?? []), $id);
            if ($request === null) {
                $this->assertArrayNotHasKey('request', $document, $id);
            } else {
                $this->assertArrayHasKey('request', $document, $id);
                $this->assertSame($request, $document['request'] ?? null, $id);
            }
        }
    }

    /** @return array<string, mixed> */
    private function identity(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
    }
}
