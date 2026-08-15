<?php

namespace App\Services\LegacyMigration;

use RuntimeException;

final class SyntheticMigrationRehearsalException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
