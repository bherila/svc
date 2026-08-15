<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StorageStatusCommand extends Command
{
    protected $signature = 'svc:storage:status
        {--write : Write, verify, and remove a synthetic health probe}
        {--format=text : Output text or json}';

    protected $description = 'Verify the private SVC storage disk without exposing paths or data';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $disk = config('svc.filesystem_disk');

        if (! is_string($disk) || $disk === '' || config("filesystems.disks.{$disk}") === null) {
            return $this->report($format, [
                'configured' => false,
                'write_probe' => false,
                'cleanup' => false,
            ], self::FAILURE);
        }

        if (! $this->option('write')) {
            return $this->report($format, [
                'configured' => true,
                'write_probe' => null,
                'cleanup' => null,
            ], self::SUCCESS);
        }

        $path = '_health/'.Str::uuid()->toString();
        $payload = random_bytes(32);
        $writeProbe = false;
        $cleanup = false;

        $storage = null;

        try {
            $storage = Storage::disk($disk);
            $storage->put($path, $payload);
            $writeProbe = hash_equals(hash('sha256', $payload), hash('sha256', $storage->get($path)));
        } catch (Throwable) {
            // Health output is intentionally redacted. Filesystem exception details can
            // contain absolute paths or provider metadata and belong in private logs.
        } finally {
            if ($storage !== null) {
                try {
                    $storage->delete($path);
                    $cleanup = ! $storage->exists($path);
                } catch (Throwable) {
                    $cleanup = false;
                }
            }
        }

        return $this->report($format, [
            'configured' => true,
            'write_probe' => $writeProbe,
            'cleanup' => $cleanup,
        ], $writeProbe && $cleanup ? self::SUCCESS : self::FAILURE);
    }

    /** @param array{configured: bool, write_probe: ?bool, cleanup: ?bool} $status */
    private function report(string $format, array $status, int $exitCode): int
    {
        if ($format === 'json') {
            $this->line((string) json_encode($status, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Configured', $status['configured'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Write probe', $this->statusLabel($status['write_probe']));
            $this->components->twoColumnDetail('Cleanup', $this->statusLabel($status['cleanup']));
        }

        return $exitCode;
    }

    private function statusLabel(?bool $status): string
    {
        return match ($status) {
            true => 'passed',
            false => 'failed',
            null => 'not run',
        };
    }
}
