<?php

namespace App\Console\Commands\Storage;

use App\Console\Commands\MutationCommand;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class StorageStatusCommand extends MutationCommand
{
    protected $signature = 'svc:storage:status
        {--write : Deprecated alias for --apply}
        {--format=text : Output text or json}';

    protected $description = 'Verify the private SVC storage disk without exposing paths or data';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->option('write')) {
            $input->setOption('apply', true);
            $this->deprecation('Deprecated: --write; use --apply.');
        }

        return parent::execute($input, $output);
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        $disk = config('svc.filesystem_disk');

        if (! is_string($disk) || $disk === '' || config("filesystems.disks.{$disk}") === null) {
            return $this->report($format, [
                'configured' => false,
                'write_probe' => false,
                'cleanup' => false,
            ], self::FAILURE);
        }

        if (! $this->applying()) {
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
