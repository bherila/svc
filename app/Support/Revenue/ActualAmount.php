<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * Money already invoiced or collected for one client, on one placement date,
 * in one band: minor units, before tax.
 */
final readonly class ActualAmount
{
    public function __construct(
        public int $clientId,
        public CarbonImmutable $date,
        public RevenueBand $band,
        public int $amount,
    ) {}
}
