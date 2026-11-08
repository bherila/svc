<?php

namespace App\Support\Payments\Inventory;

use App\Support\Payments\PaymentProcessor;

/** Everything a read-only preview can say about moving the environment credentials. */
final readonly class MigrationInventory
{
    /** @param array<string, ProcessorInventory> $processors keyed by processor value */
    public function __construct(public ?WorkspaceRef $named, public array $processors) {}

    public function for(PaymentProcessor $processor): ProcessorInventory
    {
        return $this->processors[$processor->value];
    }

    /** Whether --apply must wait for an explicit --workspace. */
    public function needsExplicitWorkspace(): bool
    {
        foreach ($this->processors as $inventory) {
            if ($inventory->ownership->needsExplicitChoice()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'read_only' => true,
            'named_workspace' => $this->named?->toArray(),
            'needs_explicit_workspace' => $this->needsExplicitWorkspace(),
            'processors' => array_map(static fn (ProcessorInventory $inventory): array => $inventory->toArray(), $this->processors),
        ];
    }
}
