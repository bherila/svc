<?php

namespace App\Console\Commands;

use App\Services\Files\AttachmentStorageService;
use Illuminate\Console\Command;

class RepairAttachmentsCommand extends Command
{
    protected $signature = 'svc:attachments:repair
        {--apply : Apply cleanup and corruption-state mutations}
        {--staged-age=60 : Age in minutes before a staged object is considered abandoned}
        {--retention-days=7 : Retention period in days for logically deleted rows}
        {--format=text : Output text or json}';

    protected $description = 'Find abandoned, missing, or corrupt private attachment objects';

    public function handle(AttachmentStorageService $storage): int
    {
        $format = (string) $this->option('format');
        $stagedAge = filter_var($this->option('staged-age'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $retentionDays = filter_var($this->option('retention-days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (! in_array($format, ['text', 'json'], true) || $stagedAge === false || $retentionDays === false) {
            $this->error('Invalid repair options.');

            return self::INVALID;
        }

        $counts = $storage->repair((bool) $this->option('apply'), $stagedAge, $retentionDays);
        $payload = [
            'apply' => (bool) $this->option('apply'),
            ...$counts,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Mode', $payload['apply'] ? 'apply' : 'dry-run');
            $this->components->twoColumnDetail('Staged rows', (string) $counts['staged_rows']);
            $this->components->twoColumnDetail('Orphaned staged objects', (string) $counts['orphaned_staged_objects']);
            $this->components->twoColumnDetail('Missing objects', (string) $counts['missing_objects']);
            $this->components->twoColumnDetail('Hash mismatches', (string) $counts['hash_mismatches']);
            $this->components->twoColumnDetail('Purged rows', (string) $counts['purged_rows']);
        }

        return self::SUCCESS;
    }
}
