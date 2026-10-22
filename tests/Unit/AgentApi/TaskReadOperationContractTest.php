<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentRestDocumentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentRestDocumentation::class)]
final class TaskReadOperationContractTest extends TestCase
{
    public function test_project_detail_keeps_project_scope_and_tenant_bound_optional_task_continuation(): void
    {
        $document = AgentRestDocumentation::operations()['projects.get'];

        // A projects-only token can read the base project. The transport checks
        // tasks:read separately before embedding tasks or honoring this cursor.
        $this->assertSame('GET', $document['method']);
        $this->assertSame(['projects:read'], $document['scopes']);
        $this->assertSame([
            'WorkspaceId',
            'ProjectId',
            ['name' => 'tasks_cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 2048]],
        ], $document['parameters']);
        $this->assertSame('ProjectDetailResponse', $document['response']);
        $this->assertArrayNotHasKey('request', $document);
    }
}
