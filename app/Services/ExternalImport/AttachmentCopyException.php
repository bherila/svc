<?php

namespace App\Services\ExternalImport;

use RuntimeException;

final class AttachmentCopyException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
