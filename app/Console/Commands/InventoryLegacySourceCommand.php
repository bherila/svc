<?php

namespace App\Console\Commands;

use App\Services\LegacyMigration\LegacySourceInventory;
use App\Services\LegacyMigration\SourceConfigurationException;
use Illuminate\Console\Command;
use Throwable;

class InventoryLegacySourceCommand extends Command
{
    protected $signature = 'svc:migrate:legacy:inventory
        {--source=legacy : Explicit configured read-only source name}
        {--format=text : Output text or json}';

    protected $description = 'Inventory a configured read-only legacy source without resolving or writing a destination';

    public function handle(LegacySourceInventory $inventory): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            return $this->failure($format, 'invalid_format');
        }

        try {
            $summary = $inventory->inspect((string) $this->option('source'));
        } catch (SourceConfigurationException $exception) {
            return $this->failure($format, $exception->reasonCode);
        } catch (Throwable) {
            return $this->failure($format, 'inventory_unavailable');
        }

        if ($format === 'json') {
            $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->twoColumnDetail('Mode', (string) $summary['mode']);
            $this->components->twoColumnDetail('Source', (string) $summary['source']);
            $this->components->twoColumnDetail('Tables', (string) $summary['counts']['tables']);
            $this->components->twoColumnDetail('Rows', (string) $summary['counts']['source_rows']);
            $this->components->twoColumnDetail('Duplicates', (string) $summary['counts']['duplicates']);
            $this->components->twoColumnDetail('Orphans', (string) $summary['counts']['orphans']);
            $this->components->twoColumnDetail('Missing key columns', (string) $summary['counts']['missing_key_columns']);
        }

        return self::SUCCESS;
    }

    private function failure(string $format, string $reason): int
    {
        $payload = ['ok' => false, 'reason_code' => $reason, 'redacted' => true];
        if ($format === 'json') {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->error('Legacy source inventory could not run: '.$reason);
        }

        return self::FAILURE;
    }
}
