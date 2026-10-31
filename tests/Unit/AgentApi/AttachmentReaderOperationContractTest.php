<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentRestDocumentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentRestDocumentation::class)]
final class AttachmentReaderOperationContractTest extends TestCase
{
    public function test_generic_file_reads_preserve_tenant_parent_identity_and_read_only_collaboration_admission(): void
    {
        $document = AgentRestDocumentation::operations()['attachments.list'];
        $this->assertSame('GET', $document['method']);
        $this->assertSame(['files:read'], $document['scopes']);
        $this->assertSame('AttachmentListResponse', $document['response'] ?? null);
        $parameters = $document['parameters'];
        $this->assertCount(7, $parameters);
        $this->assertSame('WorkspaceId', $parameters[0]);
        $this->assertSame(['name' => 'recordType', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string', 'enum' => ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'topic', 'comment']]], $parameters[1]);
        $this->assertSame(['name' => 'recordPublicId', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string', 'format' => 'uuid']], $parameters[2]);
        $this->assertIsArray($parameters[3]);
        $this->assertIsArray($parameters[4]);
        $this->assertPagination($parameters[3], $parameters[4]);
        $this->assertSame(['name' => 'presentation', 'in' => 'query', 'description' => 'Optional native panel representation for trusted first-party sessions only. It never grants parent authority. Portal always narrows to the client-safe whitelist; bearer and MCP callers are refused.',
            'schema' => ['type' => 'string', 'enum' => ['native', 'portal']]], $parameters[5]);
        $this->assertSame(['name' => 'client', 'in' => 'query', 'description' => 'Required only for the portal panel representation; exact current company and parent access is rechecked.', 'schema' => ['type' => 'string', 'format' => 'uuid']], $parameters[6]);
    }

    public function test_expense_receipt_pages_keep_the_separate_tenant_parent_scope_and_same_continuation_bounds(): void
    {
        $document = AgentRestDocumentation::operations()['expenses.receipts.list'];
        $this->assertSame('GET', $document['method']);
        $this->assertSame(['expenses:read'], $document['scopes']);
        $this->assertSame('ExpenseReceiptListResponse', $document['response'] ?? null);
        $parameters = $document['parameters'];
        $this->assertCount(4, $parameters);
        $this->assertSame(['WorkspaceId', 'ExpenseId'], array_slice($parameters, 0, 2));
        $this->assertIsArray($parameters[2]);
        $this->assertIsArray($parameters[3]);
        $this->assertPagination($parameters[2], $parameters[3]);
    }

    public function test_each_upload_door_documents_policy_unavailability_without_enabling_new_writers(): void
    {
        $documents = AgentRestDocumentation::operations();
        foreach (['attachments.upload', 'attachments.upload_url', 'expenses.receipts.upload', 'expenses.receipts.upload_url'] as $operation) {
            $this->assertSame([503], array_keys($documents[$operation]['errors'] ?? []), $operation);
        }
    }

    /** @param array<string, mixed> $limit
     * @param  array<string, mixed>  $cursor
     */
    private function assertPagination(array $limit, array $cursor): void
    {
        // Omission must retain the existing 100-row contract. Explicit callers
        // can request one row, and every returned continuation fits its input.
        $this->assertSame(['name' => 'limit', 'in' => 'query', 'required' => false,
            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100]], $limit);
        $this->assertSame(['name' => 'cursor', 'in' => 'query', 'required' => false,
            'schema' => ['type' => 'string', 'maxLength' => 4096]], $cursor);
    }
}
