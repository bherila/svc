<?php

namespace App\Support\Payments\Inventory;

final readonly class WorkspaceImpact
{
    public function __construct(
        public WorkspaceRef $workspace,
        public ImpactRole $role,
        public ImpactCounts $counts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['workspace' => $this->workspace->toArray(), 'role' => $this->role->value, 'counts' => $this->counts->toArray()];
    }
}
