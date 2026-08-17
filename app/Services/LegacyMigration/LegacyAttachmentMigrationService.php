<?php

namespace App\Services\LegacyMigration;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\LegacyAttachmentCopy;
use App\Models\LegacyMigrationItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class LegacyAttachmentMigrationService
{
    private const MAX_BYTES = 52428800;

    /**
     * @var array<string, array{parent_table:string,parent_column:string,record_type:string,model:class-string<Model&WorkspaceOwned>}>
     */
    private const MAPPINGS = [
        'files_for_client_companies' => [
            'parent_table' => 'client_companies',
            'parent_column' => 'client_company_id',
            'record_type' => 'company',
            'model' => ClientCompany::class,
        ],
        'files_for_projects' => [
            'parent_table' => 'client_projects',
            'parent_column' => 'project_id',
            'record_type' => 'project',
            'model' => ClientProject::class,
        ],
        'files_for_tasks' => [
            'parent_table' => 'client_tasks',
            'parent_column' => 'task_id',
            'record_type' => 'task',
            'model' => ClientTask::class,
        ],
        'files_for_agreements' => [
            'parent_table' => 'client_agreements',
            'parent_column' => 'agreement_id',
            'record_type' => 'agreement',
            'model' => ClientAgreement::class,
        ],
    ];

    public function __construct(
        private readonly SourceGuard $sourceGuard,
        private readonly AttachmentStorageService $storage,
    ) {}

    /** @return array<string, mixed> */
    public function run(string $sourceName, string $workspaceIdentifier, string $uploaderPublicId, bool $apply = false): array
    {
        $source = $this->sourceGuard->resolve($sourceName);
        $this->sourceGuard->assertDistinctFromDestination($source);
        $this->assertDefaultDestination();

        $workspace = Workspace::query()
            ->where('public_id', $workspaceIdentifier)
            ->orWhere('slug', $workspaceIdentifier)
            ->first();
        if (! $workspace) {
            throw new SourceConfigurationException('workspace_not_found');
        }

        $uploader = User::query()->where('public_id', $uploaderPublicId)->first();
        if (! $uploader || ! $workspace->users()->whereKey($uploader->getKey())->exists()) {
            throw new SourceConfigurationException('workspace_uploader_not_found');
        }

        $root = $this->attachmentRoot();
        $connection = $this->sourceGuard->connection($source);
        $items = LegacyMigrationItem::query()
            ->where('source_identity_hash', $source['identity_hash'])
            ->where('target_type', 'attachment')
            ->whereIn('source_table', array_keys(self::MAPPINGS))
            ->whereIn('status', ['planned_copy', 'imported'])
            ->whereHas('run', fn ($query) => $query->where('workspace_id', $workspace->getKey()))
            ->orderBy('source_table')
            ->orderBy('source_key')
            ->get();

        $counts = [
            'source_rows' => $items->count(),
            'planned' => 0,
            'copied' => 0,
            'idempotent' => 0,
            'failed' => 0,
            'failure_reasons' => [],
        ];

        foreach ($items as $item) {
            try {
                $outcome = $this->processItem($connection, $source['identity_hash'], $root, $workspace, $uploader, $item, $apply);
                $counts[$outcome]++;
            } catch (AttachmentCopyException $exception) {
                $counts['failed']++;
                $counts['failure_reasons'][$exception->reasonCode] = ($counts['failure_reasons'][$exception->reasonCode] ?? 0) + 1;
            } catch (Throwable) {
                $counts['failed']++;
                $counts['failure_reasons']['copy_unavailable'] = ($counts['failure_reasons']['copy_unavailable'] ?? 0) + 1;
            }
        }

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'status' => $counts['failed'] === 0 ? 'completed' : 'completed_with_failures',
            'counts' => $counts,
            'redacted' => true,
        ];
    }

    /** @return 'planned'|'copied'|'idempotent' */
    private function processItem(mixed $connection, string $sourceIdentityHash, string $root, Workspace $workspace, User $uploader, LegacyMigrationItem $item, bool $apply): string
    {
        $mapping = self::MAPPINGS[$item->source_table] ?? null;
        if ($mapping === null) {
            throw new AttachmentCopyException('source_table_not_supported');
        }

        $rowObject = $connection->table($item->source_table)->where('id', $item->source_key)->first();
        if (! $rowObject) {
            throw new AttachmentCopyException('source_row_missing');
        }
        $row = (array) $rowObject;
        if (! hash_equals($item->source_fingerprint, Fingerprint::row($row))) {
            throw new AttachmentCopyException('source_changed');
        }
        if ($this->optionalString($row['deleted_at'] ?? null) !== null) {
            throw new AttachmentCopyException('source_attachment_deleted');
        }

        $sourceFile = $this->sourceFile($root, $row);
        $record = $this->record($workspace, $sourceIdentityHash, $mapping, $row);
        $publicId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            implode(':', ['svc', 'legacy-attachment', $sourceIdentityHash, $item->source_table, $item->source_key]),
        )->toString();

        $attachment = ClientAttachment::query()->where('public_id', $publicId)->first();
        $copy = LegacyAttachmentCopy::query()->where('legacy_migration_item_id', $item->getKey())->first();
        if ($copy) {
            if (! $attachment) {
                throw new AttachmentCopyException('incomplete_copy_ledger');
            }
            $this->assertExistingCopy($workspace, $record, $mapping['record_type'], $sourceFile, $copy, $attachment);

            return 'idempotent';
        }
        if ($attachment) {
            $this->assertExistingAttachment($workspace, $record, $mapping['record_type'], $sourceFile, $attachment);
            if (! $apply) {
                return 'planned';
            }
            $this->completeLedger($item, $workspace, $attachment, $sourceFile);

            return 'idempotent';
        }

        if (! $apply) {
            return 'planned';
        }

        $uploadedFile = new UploadedFile(
            $sourceFile['path'],
            $this->originalFilename($row),
            $this->optionalString($row['mime_type'] ?? null),
            null,
            true,
        );
        $attachment = $this->storage->store($workspace, $record, $uploadedFile, $uploader, $publicId);
        if ($attachment->sha256 !== $sourceFile['sha256'] || $attachment->bytes !== $sourceFile['bytes']) {
            $this->storage->discardMigrationCopy($attachment);
            throw new AttachmentCopyException('source_changed_during_copy');
        }

        try {
            $this->completeLedger($item, $workspace, $attachment, $sourceFile);
        } catch (Throwable $exception) {
            $this->storage->discardMigrationCopy($attachment);
            throw $exception;
        }

        return 'copied';
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $row
     * @return Model&WorkspaceOwned
     */
    private function record(Workspace $workspace, string $sourceIdentityHash, array $mapping, array $row): Model
    {
        $sourceParentKey = $row[$mapping['parent_column']] ?? null;
        if ($sourceParentKey === null || $sourceParentKey === '') {
            throw new AttachmentCopyException('parent_source_key_missing');
        }

        $parentItem = LegacyMigrationItem::query()
            ->where('source_identity_hash', $sourceIdentityHash)
            ->where('source_table', $mapping['parent_table'])
            ->where('source_key', (string) $sourceParentKey)
            ->where('status', 'imported')
            ->first();
        if (! $parentItem || ! is_string($parentItem->target_public_id)) {
            throw new AttachmentCopyException('parent_mapping_missing');
        }

        $modelClass = $mapping['model'];
        $record = $modelClass::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('public_id', $parentItem->target_public_id)
            ->first();
        if (! $record) {
            throw new AttachmentCopyException('parent_record_missing');
        }

        return $record;
    }

    /** @param array<string, mixed> $row
     * @return array{path:string,path_hash:string,sha256:string,bytes:int}
     */
    private function sourceFile(string $root, array $row): array
    {
        $relativePath = $this->optionalString($row['s3_path'] ?? null);
        if ($relativePath === null || str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || str_starts_with($relativePath, '/')) {
            throw new AttachmentCopyException('source_path_invalid');
        }
        $segments = explode('/', $relativePath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new AttachmentCopyException('source_path_invalid');
        }
        $candidate = $root;
        foreach ($segments as $segment) {
            $candidate .= '/'.$segment;
            if (is_link($candidate)) {
                throw new AttachmentCopyException('source_path_invalid');
            }
        }

        $path = realpath($root.'/'.$relativePath);
        if (! is_string($path) || ! str_starts_with($path, $root.'/') || ! is_file($path) || ! is_readable($path)) {
            throw new AttachmentCopyException('source_object_missing');
        }

        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (! is_int($bytes) || ! is_string($sha256)) {
            throw new AttachmentCopyException('source_object_unreadable');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new AttachmentCopyException('source_object_too_large');
        }

        $claimedBytes = $row['file_size_bytes'] ?? null;
        if ($claimedBytes !== null && $claimedBytes !== '' && (int) $claimedBytes !== $bytes) {
            throw new AttachmentCopyException('source_size_mismatch');
        }

        return [
            'path' => $path,
            'path_hash' => hash('sha256', $relativePath),
            'sha256' => $sha256,
            'bytes' => $bytes,
        ];
    }

    /** @param array{path_hash:string,sha256:string,bytes:int} $sourceFile */
    private function assertExistingCopy(Workspace $workspace, Model $record, string $recordType, array $sourceFile, LegacyAttachmentCopy $copy, ClientAttachment $attachment): void
    {
        $matches = $copy->workspace_id === $workspace->getKey()
            && $copy->client_attachment_id === $attachment->getKey()
            && hash_equals($copy->source_path_hash, $sourceFile['path_hash'])
            && hash_equals($copy->source_sha256, $sourceFile['sha256'])
            && $copy->source_bytes === $sourceFile['bytes']
            && hash_equals($copy->destination_object_key_hash, hash('sha256', $attachment->object_key))
            && $attachment->workspace_id === $workspace->getKey();
        if (! $matches) {
            throw new AttachmentCopyException('copy_integrity_mismatch');
        }

        $this->assertExistingAttachment($workspace, $record, $recordType, $sourceFile, $attachment);
    }

    /** @param array{path_hash:string,sha256:string,bytes:int} $sourceFile */
    private function assertExistingAttachment(Workspace $workspace, Model $record, string $recordType, array $sourceFile, ClientAttachment $attachment): void
    {
        $matches = $attachment->workspace_id === $workspace->getKey()
            && $attachment->record_type === $recordType
            && $attachment->record_public_id === (string) $record->getAttribute('public_id')
            && $attachment->sha256 === $sourceFile['sha256']
            && $attachment->bytes === $sourceFile['bytes'];
        if (! $matches) {
            throw new AttachmentCopyException('copy_integrity_mismatch');
        }

        try {
            $this->storage->assertAvailableObjectMatches($attachment);
        } catch (RuntimeException) {
            throw new AttachmentCopyException('destination_object_mismatch');
        }
    }

    /** @param array{path_hash:string,sha256:string,bytes:int} $sourceFile */
    private function completeLedger(LegacyMigrationItem $item, Workspace $workspace, ClientAttachment $attachment, array $sourceFile): void
    {
        DB::transaction(function () use ($item, $workspace, $attachment, $sourceFile): void {
            LegacyAttachmentCopy::query()->create([
                'legacy_migration_item_id' => $item->getKey(),
                'workspace_id' => $workspace->getKey(),
                'client_attachment_id' => $attachment->getKey(),
                'source_path_hash' => $sourceFile['path_hash'],
                'source_sha256' => $sourceFile['sha256'],
                'source_bytes' => $sourceFile['bytes'],
                'destination_object_key_hash' => hash('sha256', $attachment->object_key),
                'copied_at' => now(),
            ]);
            $item->forceFill([
                'target_public_id' => $attachment->public_id,
                'status' => 'imported',
                'reason_code' => null,
            ])->save();
        });
    }

    private function attachmentRoot(): string
    {
        $configured = Config::get('legacy-migration.attachment_root');
        if (! is_string($configured) || $configured === '' || is_link($configured)) {
            throw new SourceConfigurationException('attachment_root_unavailable');
        }
        $root = realpath($configured);
        if (! is_string($root) || $root === DIRECTORY_SEPARATOR || ! is_dir($root) || ! is_readable($root)) {
            throw new SourceConfigurationException('attachment_root_unavailable');
        }

        return rtrim($root, '/');
    }

    private function assertDefaultDestination(): void
    {
        $destination = Config::get('legacy-migration.destination_connection') ?: Config::get('database.default');
        if ($destination !== Config::get('database.default')) {
            throw new SourceConfigurationException('attachment_destination_must_be_default');
        }
    }

    /** @param array<string, mixed> $row */
    private function originalFilename(array $row): string
    {
        $filename = $this->optionalString($row['original_filename'] ?? null)
            ?? $this->optionalString($row['stored_filename'] ?? null)
            ?? 'legacy-attachment';

        $filename = basename(str_replace('\\', '/', $filename));

        return in_array($filename, ['', '.', '..'], true) ? 'legacy-attachment' : $filename;
    }

    private function optionalString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
