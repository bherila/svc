<?php

namespace App\Services\History;

use App\Models\HistoricalBlob;
use App\Models\HistoricalEntry;
use App\Models\HistoricalRecord;
use App\Models\HistoricalSource;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class HistoricalBlobRepository
{
    public function preserve(Workspace $workspace, HistoricalEntry $entry, string $localPath, string $filename, string $sha256, int $bytes): HistoricalBlob
    {
        Validator::make(['filename' => $filename, 'sha' => $sha256, 'bytes' => $bytes], [
            'filename' => ['required', 'string', 'max:512', 'not_regex:/[\x00-\x1F\x7F\/\\\\]/'],
            'sha' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'bytes' => ['required', 'integer', 'min:0'],
        ])->validate();
        if (! is_file($localPath) || filesize($localPath) !== $bytes || ! hash_equals($sha256, (string) hash_file('sha256', $localPath))) {
            throw new DomainException('The historical file does not match its verified size and digest.');
        }

        return DB::transaction(function () use ($workspace, $entry, $localPath, $filename, $sha256, $bytes): HistoricalBlob {
            $actual = HistoricalEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)->firstOrFail();
            $record = HistoricalRecord::query()->where('workspace_id', $workspace->id)->whereKey($actual->historical_record_id)->firstOrFail();
            HistoricalSource::query()->where('workspace_id', $workspace->id)->whereKey($record->historical_source_id)->tap(Locks::forUpdate())->firstOrFail();
            $existing = HistoricalBlob::query()->where('workspace_id', $workspace->id)->where('historical_entry_id', $actual->id)->first();
            if ($existing !== null && ($existing->sha256 !== $sha256 || $existing->filename !== $filename || (int) $existing->bytes !== $bytes)) {
                throw new DomainException('A different file is already preserved for this historical record.');
            }
            $key = 'history/'.$workspace->public_id.'/'.$sha256;
            $disk = Storage::disk('svc_files');
            // Repairing a missing content-addressed copy is safe on a replay;
            // never trust existence alone as proof that its bytes are correct.
            $copy = $disk->exists($key) ? $disk->readStream($key) : false;
            $matches = false;
            if (is_resource($copy)) {
                $context = hash_init('sha256');
                $size = hash_update_stream($context, $copy);
                fclose($copy);
                $matches = $size === $bytes && hash_equals($sha256, hash_final($context));
            }
            if (! $matches) {
                $stream = fopen($localPath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Unable to open the verified historical file.');
                }
                try {
                    $disk->writeStream($key, $stream);
                } finally {
                    fclose($stream);
                }
                $verification = $disk->readStream($key);
                if (! is_resource($verification)) {
                    throw new RuntimeException('The preserved historical file could not be read back.');
                }
                $verificationHash = hash_init('sha256');
                $verifiedBytes = hash_update_stream($verificationHash, $verification);
                fclose($verification);
                if ($verifiedBytes !== $bytes || ! hash_equals($sha256, hash_final($verificationHash))) {
                    throw new DomainException('The copied historical file failed its independent digest check.');
                }
            }

            return $existing ?? HistoricalBlob::query()->create(['workspace_id' => $workspace->id, 'historical_entry_id' => $actual->id,
                'filename' => $filename, 'object_key' => $key, 'bytes' => $bytes, 'sha256' => $sha256]);
        });
    }
}
