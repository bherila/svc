<?php

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyMigrationService;
use App\Services\LegacyMigration\SourceConfigurationException;
use Illuminate\Console\Command;
use Throwable;

class MigrateLegacyCommand extends Command
{
    protected $signature = 'svc:migrate:legacy
        {--source=legacy : Explicit configured source name}
        {--workspace= : Workspace public UUID or slug}
        {--apply : Permit destination writes; without this the command is a dry run}
        {--format=text : Output text or json}';

    protected $description = 'Inventory or migrate a configured legacy source; dry-run by default';

    public function handle(LegacyMigrationService $migration): int
    {
        $format = (string) $this->option('format');
        $workspace = (string) $this->option('workspace');
        if (! in_array($format, ['text', 'json'], true) || $workspace === '') {
            return $this->failure($format, 'invalid_arguments');
        }

        try {
            $summary = $migration->run((string) $this->option('source'), $workspace, (bool) $this->option('apply'));
        } catch (SourceConfigurationException $exception) {
            return $this->failure($format, $exception->reasonCode);
        } catch (Throwable) {
            return $this->failure($format, 'migration_unavailable');
        }

        if ($format === 'json') {
            $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Mode', $summary['mode']);
            $this->components->twoColumnDetail('Source', $summary['source']);
            $this->components->twoColumnDetail('Rows', (string) $summary['counts']['source_rows']);
            $this->components->twoColumnDetail('Imported', (string) ($summary['counts']['imported'] ?? 0));
            $this->components->twoColumnDetail('Skipped', (string) ($summary['counts']['skipped'] ?? 0));
            $this->components->twoColumnDetail('Planned attachment copies', (string) ($summary['counts']['planned_copy'] ?? 0));
            $this->components->twoColumnDetail('Failures', (string) ($summary['counts']['failed'] ?? 0));
        }

        $status = $summary['status'] ?? null;

        return $status === null || $status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function failure(string $format, string $reason): int
    {
        $payload = ['ok' => false, 'reason_code' => $reason, 'redacted' => true];
        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->error('Migration could not run: '.$reason);
        }

        return self::FAILURE;
    }
}
