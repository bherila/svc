<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/** The toggles a revenue read takes, as the dashboard and the planning reads share them. */
final readonly class RevenueQuery
{
    public function __construct(
        public RevenueBasis $basis = RevenueBasis::Invoiced,
        public bool $includeExpenses = true,
        public bool $includeProjectedOverage = false,
        public string $band = 'all',
        public ?string $clientId = null,
    ) {}
}
