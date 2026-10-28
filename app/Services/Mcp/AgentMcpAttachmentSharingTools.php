<?php

namespace App\Services\Mcp;

use Mcp\Capability\Attribute\Schema;

/** File bytes travel only through the authenticated REST prepared URLs. */
final class AgentMcpAttachmentSharingTools
{
    public function __construct(private readonly AgentMcpRestCall $rest) {}

    /** @return array<string,mixed> */
    public function visibilityGet(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $attachment_id): array
    {
        return $this->rest->call('attachments.visibility.get', ['workspace' => $workspace_id, 'attachment' => $attachment_id]);
    }

    /** @return array<string,mixed> */
    public function visibilityUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $attachment_id,
        bool $is_visible_to_client, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_parent_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('attachments.visibility.update', ['workspace' => $workspace_id, 'attachment' => $attachment_id],
            compact('is_visible_to_client', 'expected_version', 'expected_parent_version'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function collaborationList(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['topic', 'comment'])] string $record_type, #[Schema(format: 'uuid')] string $record_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 100, #[Schema(maxLength: 4096)] ?string $cursor = null): array
    {
        return $this->rest->call('collaboration.attachments.list', $this->path($workspace_id, $client_id, $record_type, $record_id),
            query: compact('limit', 'cursor'));
    }

    /** @return array<string,mixed> */
    public function collaborationDownloadUrl(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['topic', 'comment'])] string $record_type, #[Schema(format: 'uuid')] string $record_id,
        #[Schema(format: 'uuid')] string $attachment_id): array
    {
        return $this->rest->call('collaboration.attachments.download_url', $this->path($workspace_id, $client_id, $record_type, $record_id, $attachment_id));
    }

    /** @return array<string,mixed> */
    public function collaborationUploadUrl(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['topic', 'comment'])] string $record_type, #[Schema(format: 'uuid')] string $record_id): array
    {
        return $this->rest->call('collaboration.attachments.upload_url', $this->path($workspace_id, $client_id, $record_type, $record_id));
    }

    /** @return array<string,mixed> */
    public function collaborationVisibilityUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['topic', 'comment'])] string $record_type, #[Schema(format: 'uuid')] string $record_id,
        #[Schema(format: 'uuid')] string $attachment_id, bool $is_visible_to_client,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 64, maxLength: 64)] string $expected_parent_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('collaboration.attachments.visibility.update', $this->path($workspace_id, $client_id, $record_type, $record_id, $attachment_id),
            compact('is_visible_to_client', 'expected_version', 'expected_parent_version'), $idempotency_key);
    }

    /** @return array<string,mixed> */
    public function collaborationDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['topic', 'comment'])] string $record_type, #[Schema(format: 'uuid')] string $record_id,
        #[Schema(format: 'uuid')] string $attachment_id, bool $confirm,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 64, maxLength: 64)] string $expected_parent_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('collaboration.attachments.delete', $this->path($workspace_id, $client_id, $record_type, $record_id, $attachment_id),
            compact('confirm', 'expected_version', 'expected_parent_version'), $idempotency_key);
    }

    /** @return array<string,string> */
    private function path(string $workspace, string $client, string $recordType, string $recordPublicId, ?string $attachment = null): array
    {
        return compact('workspace', 'client', 'recordType', 'recordPublicId') + ($attachment === null ? [] : compact('attachment'));
    }
}
