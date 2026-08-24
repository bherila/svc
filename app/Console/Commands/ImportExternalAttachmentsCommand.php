<?php

namespace App\Console\Commands;

use App\Services\ExternalImport\ExternalAttachmentImportService;
use App\Services\ExternalImport\SourceConfigurationException;
use Illuminate\Console\Command;
use Throwable;

class ImportExternalAttachmentsCommand extends Command
{
    protected $signature = 'svc:import:external:attachments
        {--source=external : Explicit configured read-only source name}
        {--workspace= : Workspace public UUID or slug}
        {--uploader= : SVC public UUID of the workspace member recording the copy}
        {--apply : Copy source files and write provenance; without this the command is a dry run}
        {--format=text : Output text or json}';

    protected $description = 'Copy planned external attachments into private SVC storage; dry-run by default';

    public function handle(ExternalAttachmentImportService $import): int
    {
        $format = (string) $this->option('format');
        $workspace = (string) $this->option('workspace');
        $uploader = (string) $this->option('uploader');
        if (! in_array($format, ['text', 'json'], true) || $workspace === '' || $uploader === '') {
            return $this->failure($format, 'invalid_arguments');
        }

        try {
            $summary = $import->run(
                (string) $this->option('source'),
                $workspace,
                $uploader,
                (bool) $this->option('apply'),
            );
        } catch (SourceConfigurationException $exception) {
            return $this->failure($format, $exception->reasonCode);
        } catch (Throwable) {
            return $this->failure($format, 'attachment_import_unavailable');
        }

        if ($format === 'json') {
            $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Mode', $summary['mode']);
            $this->components->twoColumnDetail('Source rows', (string) $summary['counts']['source_rows']);
            $this->components->twoColumnDetail('Planned', (string) $summary['counts']['planned']);
            $this->components->twoColumnDetail('Copied', (string) $summary['counts']['copied']);
            $this->components->twoColumnDetail('Idempotent', (string) $summary['counts']['idempotent']);
            $this->components->twoColumnDetail('Failures', (string) $summary['counts']['failed']);
        }

        return $summary['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function failure(string $format, string $reason): int
    {
        $payload = ['ok' => false, 'reason_code' => $reason, 'redacted' => true];
        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->error('Attachment import could not run: '.$reason);
        }

        return self::FAILURE;
    }
}
