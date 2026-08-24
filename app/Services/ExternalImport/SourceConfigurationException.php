<?php

namespace App\Services\ExternalImport;

use RuntimeException;

class SourceConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
