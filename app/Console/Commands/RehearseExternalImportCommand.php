<?php

namespace App\Console\Commands;

use App\Services\ExternalImport\SyntheticImportRehearsal;
use App\Services\ExternalImport\SyntheticImportRehearsalException;
use Illuminate\Console\Command;
use Throwable;

class RehearseExternalImportCommand extends Command
{
    protected $signature = 'svc:import:external:rehearse
        {--format=text : Output text or json}';

    protected $description = 'Rehearse the external import twice using disposable synthetic SQLite databases';

    public function handle(SyntheticImportRehearsal $rehearsal): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            return $this->failure($format, 'invalid_format');
        }

        try {
            $summary = $rehearsal->run();
        } catch (SyntheticImportRehearsalException $exception) {
            return $this->failure($format, $exception->reasonCode);
        } catch (Throwable) {
            return $this->failure($format, 'rehearsal_unavailable');
        }

        if ($format === 'json') {
            $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Mode', (string) $summary['mode']);
            $this->components->twoColumnDetail('Source rows', (string) $summary['source_rows']);
            $this->components->twoColumnDetail('First run', (string) $summary['first_run']['status']);
            $this->components->twoColumnDetail('Second run', (string) $summary['second_run']['status']);
            $this->components->twoColumnDetail('Checks', 'passed');
            $this->components->twoColumnDetail('Artifacts', (string) $summary['artifacts']);
        }

        return self::SUCCESS;
    }

    private function failure(string $format, string $reason): int
    {
        $payload = ['ok' => false, 'reason_code' => $reason, 'redacted' => true];
        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->error('Synthetic rehearsal could not run: '.$reason);
        }

        return self::FAILURE;
    }
}
