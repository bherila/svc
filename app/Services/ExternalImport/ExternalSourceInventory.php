<?php

namespace App\Services\ExternalImport;

final class ExternalSourceInventory
{
    public function __construct(
        private readonly SourceGuard $sourceGuard,
        private readonly ImporterRegistry $registry,
        private readonly InventoryService $inventory,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(string $sourceName): array
    {
        $source = $this->sourceGuard->resolve($sourceName);
        $connection = $this->sourceGuard->connection($source);
        $inventory = $this->inventory->inspect(
            $connection,
            $this->registry->all(),
            $this->sourceGuard->runtimeName($source),
        );

        return [
            'ok' => true,
            'mode' => 'source-only-read-only',
            'source' => $source['key'],
            'source_identity_hash' => $source['identity_hash'],
            'redacted' => true,
            'counts' => [
                'tables' => count($inventory),
                'source_rows' => array_sum(array_column($inventory, 'row_count')),
                'duplicates' => array_sum(array_column($inventory, 'duplicate_count')),
                'orphans' => array_sum(array_column($inventory, 'orphan_count')),
                'missing_key_columns' => count(array_filter(
                    $inventory,
                    static fn (array $details): bool => $details['key_column_present'] !== true,
                )),
            ],
            'inventory' => $inventory,
            'fingerprints' => array_map(
                static fn (array $details): string => (string) $details['fingerprint'],
                $inventory,
            ),
            'high_water_marks' => array_map(
                static fn (array $details): array => $details['high_water_mark'],
                $inventory,
            ),
        ];
    }
}
