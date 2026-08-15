<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AttachmentStorageService
{
    public function __construct(private readonly WorkspaceAuthorization $workspaceAuthorization) {}

    private const MAX_BYTES = 52428800;

    private const STAGED_PREFIX = '_staged';

    /**
     * Stage, persist, promote, verify, and publish an attachment.
     *
     * A failed database transaction removes the staged object. A failed
     * promotion deliberately leaves the staged row for the repair command,
     * because the storage driver may have completed only part of a move.
     */
    public function store(Workspace $workspace, Model&WorkspaceOwned $record, UploadedFile $file, User $uploader): ClientAttachment
    {
        $this->workspaceAuthorization->assertOwnedBy($workspace, $record);
        $metadata = $this->stage($workspace, $record, $file);

        try {
            $attachment = DB::transaction(fn (): ClientAttachment => ClientAttachment::query()->create([
                'public_id' => $metadata['public_id'],
                'workspace_id' => $workspace->id,
                'record_type' => $metadata['record_type'],
                'record_public_id' => $metadata['record_public_id'],
                'object_key' => $metadata['object_key'],
                'staged_object_key' => $metadata['staged_object_key'],
                'original_filename' => $metadata['original_filename'],
                'media_type' => $metadata['media_type'],
                'bytes' => $metadata['bytes'],
                'sha256' => $metadata['sha256'],
                'uploader_id' => $uploader->id,
                'lifecycle_state' => ClientAttachment::STATE_STAGED,
            ]));
        } catch (Throwable $exception) {
            $this->deleteQuietly($metadata['staged_object_key']);

            throw $exception;
        }

        try {
            $disk = $this->disk();
            if (! $disk->move($metadata['staged_object_key'], $metadata['object_key'])) {
                throw new RuntimeException('Attachment promotion failed.');
            }

            $this->assertObjectMatches(
                $metadata['object_key'],
                $metadata['bytes'],
                $metadata['sha256'],
            );

            $attachment->forceFill([
                'staged_object_key' => null,
                'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
                'available_at' => now(),
            ])->save();

            return $attachment->fresh();
        } catch (Throwable $exception) {
            $this->deleteQuietly($metadata['object_key']);

            throw $exception;
        }
    }

    public function findForWorkspace(Workspace $workspace, string $publicId): ClientAttachment
    {
        return ClientAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function download(ClientAttachment $attachment): StreamedResponse
    {
        if ($attachment->lifecycle_state !== ClientAttachment::STATE_AVAILABLE) {
            abort(404);
        }

        $stream = $this->disk()->readStream($attachment->object_key);
        if (! is_resource($stream)) {
            abort(404);
        }

        $filename = $this->downloadFilename($attachment->original_filename);

        return response()->streamDownload(function () use ($stream): void {
            $output = fopen('php://output', 'wb');

            if (! is_resource($output)) {
                fclose($stream);

                return;
            }

            stream_copy_to_stream($stream, $output);
            fclose($output);
            fclose($stream);
        }, $filename, [
            'Content-Type' => $attachment->media_type,
            'Content-Length' => (string) $attachment->bytes,
            'X-Content-SHA256' => $attachment->sha256,
        ]);
    }

    public function requestDeletion(ClientAttachment $attachment): ClientAttachment
    {
        if (in_array($attachment->lifecycle_state, [
            ClientAttachment::STATE_DELETING,
            ClientAttachment::STATE_DELETED,
        ], true)) {
            return $attachment;
        }

        $attachment->forceFill([
            'lifecycle_state' => ClientAttachment::STATE_DELETING,
            'deleted_at' => now(),
        ])->save();

        return $attachment->fresh();
    }

    /**
     * @return array{staged_rows:int, orphaned_staged_objects:int, missing_objects:int, hash_mismatches:int, purged_rows:int}
     */
    public function repair(bool $apply, int $stagedAgeMinutes = 60, int $retentionDays = 7): array
    {
        $disk = $this->disk();
        $counts = [
            'staged_rows' => 0,
            'orphaned_staged_objects' => 0,
            'missing_objects' => 0,
            'hash_mismatches' => 0,
            'purged_rows' => 0,
        ];
        $stagedCutoff = CarbonImmutable::now()->subMinutes($stagedAgeMinutes);
        $retentionCutoff = CarbonImmutable::now()->subDays($retentionDays);

        $knownStagedKeys = array_fill_keys(
            ClientAttachment::query()
                ->where('lifecycle_state', ClientAttachment::STATE_STAGED)
                ->whereNotNull('staged_object_key')
                ->pluck('staged_object_key')
                ->all(),
            true,
        );

        ClientAttachment::query()
            ->where('lifecycle_state', ClientAttachment::STATE_STAGED)
            ->where('created_at', '<=', $stagedCutoff)
            ->cursor()
            ->each(function (ClientAttachment $attachment) use (&$counts, $apply, $disk): void {
                $counts['staged_rows']++;
                $stagedKey = $attachment->staged_object_key;

                if ($apply) {
                    $this->deleteIfPresent($disk, $stagedKey);
                    $this->deleteIfPresent($disk, $attachment->object_key);
                    $attachment->forceFill([
                        'lifecycle_state' => ClientAttachment::STATE_DELETED,
                        'deleted_at' => $attachment->deleted_at ?? now(),
                    ])->save();
                }
            });

        foreach ($disk->allFiles(self::STAGED_PREFIX) as $stagedKey) {
            if (isset($knownStagedKeys[$stagedKey])) {
                continue;
            }

            try {
                $isOld = $disk->lastModified($stagedKey) <= $stagedCutoff->getTimestamp();
            } catch (Throwable) {
                $isOld = false;
            }

            if (! $isOld) {
                continue;
            }

            $counts['orphaned_staged_objects']++;
            if ($apply) {
                $this->deleteIfPresent($disk, $stagedKey);
            }
        }

        ClientAttachment::query()
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->cursor()
            ->each(function (ClientAttachment $attachment) use (&$counts, $apply): void {
                try {
                    $this->assertObjectMatches($attachment->object_key, $attachment->bytes, $attachment->sha256);
                } catch (Throwable $exception) {
                    if ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'digest')) {
                        $counts['hash_mismatches']++;
                    } else {
                        $counts['missing_objects']++;
                    }

                    if ($apply) {
                        $attachment->forceFill(['lifecycle_state' => ClientAttachment::STATE_CORRUPT])->save();
                    }
                }
            });

        ClientAttachment::query()
            ->where('lifecycle_state', ClientAttachment::STATE_DELETING)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $retentionCutoff)
            ->cursor()
            ->each(function (ClientAttachment $attachment) use (&$counts, $apply, $disk): void {
                if (! $apply) {
                    $counts['purged_rows']++;

                    return;
                }

                $this->deleteIfPresent($disk, $attachment->object_key);
                $this->deleteIfPresent($disk, $attachment->staged_object_key);
                $attachment->forceFill(['lifecycle_state' => ClientAttachment::STATE_DELETED])->save();
                $counts['purged_rows']++;
            });

        return $counts;
    }

    /** @return array{public_id:string, record_type:string, record_public_id:string, object_key:string, staged_object_key:string, original_filename:string, media_type:string, bytes:int, sha256:string} */
    private function stage(Workspace $workspace, Model $record, UploadedFile $file): array
    {
        $source = fopen($file->getPathname(), 'rb');
        if (! is_resource($source)) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $temporary = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($temporary)) {
            fclose($source);

            throw new RuntimeException('The upload staging stream could not be created.');
        }

        try {
            $hash = hash_init('sha256');
            $bytes = 0;

            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('The uploaded file could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) {
                    throw new RuntimeException('The uploaded file exceeds the attachment size limit.');
                }

                hash_update($hash, $chunk);
                $this->writeAll($temporary, $chunk);
            }

            $publicId = (string) Str::uuid();
            $recordType = $this->recordTypeFor($record);
            $recordPublicId = (string) $record->getAttribute('public_id');
            $stagedObjectKey = self::STAGED_PREFIX.'/'.$publicId;
            $objectKey = sprintf(
                'workspaces/%s/%s/%s/%s',
                $workspace->public_id,
                $recordType,
                $recordPublicId,
                $publicId,
            );

            rewind($temporary);
            if (! $this->disk()->writeStream($stagedObjectKey, $temporary)) {
                throw new RuntimeException('The uploaded file could not be staged.');
            }

            return [
                'public_id' => $publicId,
                'record_type' => $recordType,
                'record_public_id' => $recordPublicId,
                'object_key' => $objectKey,
                'staged_object_key' => $stagedObjectKey,
                'original_filename' => $file->getClientOriginalName(),
                'media_type' => $this->mediaType($file),
                'bytes' => $bytes,
                'sha256' => hash_final($hash),
            ];
        } finally {
            fclose($temporary);
            fclose($source);
        }
    }

    private function assertObjectMatches(string $key, int $expectedBytes, string $expectedDigest): void
    {
        $stream = $this->disk()->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('The attachment object is missing.');
        }

        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('The attachment object could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }

            $digest = hash_final($hash);
            if ($bytes !== $expectedBytes || ! hash_equals($expectedDigest, $digest)) {
                throw new RuntimeException('The attachment object has a digest or byte-count mismatch.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function disk(): FilesystemAdapter
    {
        $diskName = config('svc.filesystem_disk');
        if (! is_string($diskName) || $diskName === '' || config("filesystems.disks.{$diskName}") === null) {
            throw new RuntimeException('The private attachment disk is not configured.');
        }

        return Storage::disk($diskName);
    }

    private function recordTypeFor(Model $record): string
    {
        return match ($record::class) {
            'App\\Models\\ClientCompany' => 'company',
            'App\\Models\\ClientProject' => 'project',
            'App\\Models\\ClientTask' => 'task',
            'App\\Models\\ClientProposal' => 'proposal',
            'App\\Models\\ClientAgreement' => 'agreement',
            'App\\Models\\ClientInvoice' => 'invoice',
            default => throw new RuntimeException('The attachment record type is not supported.'),
        };
    }

    private function mediaType(UploadedFile $file): string
    {
        $mediaType = $file->getMimeType();

        return is_string($mediaType) && $mediaType !== '' ? $mediaType : 'application/octet-stream';
    }

    private function downloadFilename(string $filename): string
    {
        $filename = basename(str_replace(['\\', "\0"], '/', $filename));
        $filename = preg_replace('/[\r\n]+/', ' ', $filename) ?: 'download';

        return $filename === '.' ? 'download' : $filename;
    }

    private function writeAll(mixed $stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The upload staging stream could not be written.');
            }

            $offset += $written;
        }
    }

    private function deleteIfPresent(FilesystemAdapter $disk, ?string $key): void
    {
        if (! is_string($key) || $key === '' || ! $disk->exists($key)) {
            return;
        }

        if (! $disk->delete($key)) {
            throw new RuntimeException('An attachment object could not be deleted.');
        }
    }

    private function deleteQuietly(string $key): void
    {
        try {
            $this->deleteIfPresent($this->disk(), $key);
        } catch (Throwable) {
            // Repair is the compensating path if a storage driver is unavailable.
        }
    }
}
