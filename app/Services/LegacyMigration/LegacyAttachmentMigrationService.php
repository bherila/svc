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

        $record = $this->record($workspace, $sourceIdentityHash, $mapping, $row);
        $publicId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            implode(':', ['svc', 'legacy-attachment', $sourceIdentityHash, $item->source_table, $item->source_key]),
        )->toString();

        $attachment = ClientAttachment::query()->where('public_id', $publicId)->first();
        $copy = LegacyAttachmentCopy::query()->where('legacy_migration_item_id', $item->getKey())->first();
        $sourceFile = $this->sourceFile($root, $row, $apply && ! $copy && ! $attachment);
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

        $temporaryPath = $sourceFile['temporary_path'] ?? null;
        if (! is_string($temporaryPath)) {
            throw new AttachmentCopyException('source_snapshot_unavailable');
        }

        try {
            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $this->originalFilename($row),
                $this->optionalString($row['mime_type'] ?? null),
                null,
                true,
            );
            $attachment = null;
            try {
                $attachment = $this->storage->store($workspace, $record, $uploadedFile, $uploader, $publicId);
                if ($attachment->sha256 !== $sourceFile['sha256'] || $attachment->bytes !== $sourceFile['bytes']) {
                    throw new AttachmentCopyException('source_snapshot_mismatch');
                }
                $this->completeLedger($item, $workspace, $attachment, $sourceFile);
            } catch (Throwable $exception) {
                if ($attachment instanceof ClientAttachment) {
                    $this->storage->discardMigrationCopy($attachment);
                }
                throw $exception;
            }
        } finally {
            @unlink($temporaryPath);
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
     * @return array{path_hash:string,sha256:string,bytes:int,temporary_path?:string}
     */
    private function sourceFile(string $root, array $row, bool $materialize = false): array
    {
        $relativePath = $this->optionalString($row['s3_path'] ?? null);
        if ($relativePath === null || str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || str_starts_with($relativePath, '/')) {
            throw new AttachmentCopyException('source_path_invalid');
        }
        $segments = explode('/', $relativePath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new AttachmentCopyException('source_path_invalid');
        }
        $candidate = $root.'/'.$relativePath;
        $states = $this->capturePathStates($root, $segments);
        $path = realpath($candidate);
        if (! is_string($path) || ! str_starts_with($path, $root.'/')) {
            throw new AttachmentCopyException('source_object_missing');
        }
        $source = @fopen($candidate, 'rb');
        if (! is_resource($source)) {
            throw new AttachmentCopyException('source_object_unreadable');
        }

        $temporaryPath = null;
        $temporary = null;
        try {
            if (! flock($source, LOCK_SH)) {
                throw new AttachmentCopyException('source_lock_unavailable');
            }
            $opened = fstat($source);
            $this->assertPathStatesUnchanged($states);
            $finalState = $states[array_key_last($states)];
            if (! is_array($opened) || ! $this->sameObject($finalState, $opened)) {
                throw new AttachmentCopyException('source_path_changed');
            }

            if ($materialize) {
                $temporaryPath = tempnam(sys_get_temp_dir(), 'svc-legacy-attachment-');
                if (! is_string($temporaryPath) || ! chmod($temporaryPath, 0600)) {
                    throw new AttachmentCopyException('source_snapshot_unavailable');
                }
                $temporary = @fopen($temporaryPath, 'wb');
                if (! is_resource($temporary)) {
                    throw new AttachmentCopyException('source_snapshot_unavailable');
                }
            }

            $snapshot = $this->readAndHash($source, $temporary);
            if (is_resource($temporary) && ! fflush($temporary)) {
                throw new AttachmentCopyException('source_snapshot_unavailable');
            }
            $afterSnapshot = fstat($source);
            if (! is_array($afterSnapshot) || ! $this->sameVersion($opened, $afterSnapshot)) {
                throw new AttachmentCopyException('source_changed_during_snapshot');
            }
            if (! rewind($source)) {
                throw new AttachmentCopyException('source_object_unreadable');
            }
            $verification = $this->readAndHash($source);
            $afterVerification = fstat($source);
            $this->assertPathStatesUnchanged($states);
            if (! is_array($afterVerification)
                || ! $this->sameVersion($opened, $afterVerification)
                || $snapshot !== $verification) {
                throw new AttachmentCopyException('source_changed_during_snapshot');
            }

            $claimedBytes = $row['file_size_bytes'] ?? null;
            if ($claimedBytes !== null && $claimedBytes !== '' && (int) $claimedBytes !== $snapshot['bytes']) {
                throw new AttachmentCopyException('source_size_mismatch');
            }

            $result = [
                'path_hash' => hash('sha256', $relativePath),
                'sha256' => $snapshot['sha256'],
                'bytes' => $snapshot['bytes'],
            ];
            if (is_string($temporaryPath)) {
                $result['temporary_path'] = $temporaryPath;
            }

            return $result;
        } catch (Throwable $exception) {
            if (is_string($temporaryPath)) {
                @unlink($temporaryPath);
            }
            throw $exception;
        } finally {
            if (is_resource($temporary)) {
                fclose($temporary);
            }
            @flock($source, LOCK_UN);
            fclose($source);
        }
    }

    /**
     * @param  list<string>  $segments
     * @return array<string, array<string|int, int>>
     */
    private function capturePathStates(string $root, array $segments): array
    {
        $states = [];
        $candidate = $root;
        foreach (array_merge([''], $segments) as $index => $segment) {
            if ($segment !== '') {
                $candidate .= '/'.$segment;
            }
            clearstatcache(true, $candidate);
            $state = @lstat($candidate);
            $expectedType = $index === count($segments) ? 0100000 : 0040000;
            if (! is_array($state) || (((int) $state['mode']) & 0170000) !== $expectedType) {
                throw new AttachmentCopyException('source_path_invalid');
            }
            $states[$candidate] = $state;
        }

        return $states;
    }

    /** @param array<string, array<string|int, int>> $states */
    private function assertPathStatesUnchanged(array $states): void
    {
        foreach ($states as $path => $before) {
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (! is_array($after) || ! $this->sameObject($before, $after)) {
                throw new AttachmentCopyException('source_path_changed');
            }
        }
    }

    /**
     * @param  array<string|int, int>  $left
     * @param  array<string|int, int>  $right
     */
    private function sameObject(array $left, array $right): bool
    {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (((int) $left['mode']) & 0170000) === (((int) $right['mode']) & 0170000);
    }

    /**
     * @param  array<string|int, int>  $left
     * @param  array<string|int, int>  $right
     */
    private function sameVersion(array $left, array $right): bool
    {
        return $this->sameObject($left, $right)
            && (int) $left['size'] === (int) $right['size']
            && (int) $left['mtime'] === (int) $right['mtime']
            && (int) $left['ctime'] === (int) $right['ctime'];
    }

    /** @return array{sha256:string,bytes:int} */
    private function readAndHash(mixed $source, mixed $destination = null): array
    {
        $hash = hash_init('sha256');
        $bytes = 0;
        while (! feof($source)) {
            $chunk = fread($source, 1024 * 1024);
            if ($chunk === false) {
                throw new AttachmentCopyException('source_object_unreadable');
            }
            if ($chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_BYTES) {
                throw new AttachmentCopyException('source_object_too_large');
            }
            hash_update($hash, $chunk);
            if (is_resource($destination)) {
                $this->writeAll($destination, $chunk);
            }
        }

        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    private function writeAll(mixed $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new AttachmentCopyException('source_snapshot_unavailable');
            }
            $offset += $written;
        }
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
