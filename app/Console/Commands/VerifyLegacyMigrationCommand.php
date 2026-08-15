<?php

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacyMigrationService;
use App\Services\LegacyMigration\SourceConfigurationException;
use Illuminate\Console\Command;
use Throwable;

class VerifyLegacyMigrationCommand extends Command
{
    protected $signature = 'svc:migrate:legacy:verify
        {--run= : Migration run public UUID}
        {--workspace= : Workspace public UUID or slug}
        {--format=text : Output text or json}';

    protected $description = 'Verify a legacy migration run using redacted ledger state';

    public function handle(LegacyMigrationService $migration): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            return $this->failure($format, 'invalid_format');
        }

        try {
            $summary = $migration->verify((string) ($this->option('run') ?: ''), (string) ($this->option('workspace') ?: ''));
        } catch (SourceConfigurationException $exception) {
            return $this->failure($format, $exception->reasonCode);
        } catch (Throwable) {
            return $this->failure($format, 'verification_unavailable');
        }

        if ($format === 'json') {
            $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Run', $summary['run_public_id']);
            $this->components->twoColumnDetail('Status', $summary['status']);
            $this->components->twoColumnDetail('Failures', (string) $summary['failure_count']);
            $this->components->twoColumnDetail('Missing targets', (string) $summary['missing_target_count']);
            $this->components->twoColumnDetail('Result', $summary['ok'] ? 'passed' : 'attention required');
        }

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function failure(string $format, string $reason): int
    {
        $payload = ['ok' => false, 'reason_code' => $reason, 'redacted' => true];
        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->error('Verification could not run: '.$reason);
        }

        return self::FAILURE;
    }
}
