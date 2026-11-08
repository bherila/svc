<?php

namespace App\Support\Payments\Inventory;

use App\Support\Payments\PaymentProcessor;

final readonly class ProcessorInventory
{
    /**
     * @param  list<WorkspaceImpact>  $impacts
     * @param  list<ExistingConnection>  $connections
     */
    public function __construct(
        public PaymentProcessor $processor,
        public bool $configured,
        public ?string $keyHint,
        public ?string $mode,
        public OwnershipStatus $ownership,
        public ?WorkspaceRef $owner,
        public string $ownershipRule,
        public MigrationOutcome $outcome,
        public array $impacts,
        public array $connections,
        public ?SquareLocationFacts $square,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'environment_credential' => ['hint' => $this->keyHint, 'mode' => $this->mode],
            'account' => ['id' => null, 'checked' => false, 'note' => 'A preview never calls '.$this->processor->label().'; --apply reads the account and stores its id.'],
            'ownership' => [
                'status' => $this->ownership->value,
                'owner' => $this->owner?->toArray(),
                'rule' => $this->ownershipRule,
                'needs_explicit_workspace' => $this->ownership->needsExplicitChoice(),
            ],
            'outcome' => $this->outcome->value,
            'dependent_workspaces' => array_map(static fn (WorkspaceImpact $impact): array => $impact->toArray(), $this->impacts),
            'existing_connections' => array_map(static fn (ExistingConnection $connection): array => $connection->toArray(), $this->connections),
            'square_location' => $this->square?->toArray(),
        ];
    }
}
