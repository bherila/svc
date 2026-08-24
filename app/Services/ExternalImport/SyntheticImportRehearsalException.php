<?php

namespace App\Services\ExternalImport;

use RuntimeException;

final class SyntheticImportRehearsalException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
