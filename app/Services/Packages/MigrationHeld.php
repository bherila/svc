<?php

namespace App\Services\Packages;

use App\Support\Packages\Migration\MigrationHold;
use RuntimeException;

/** A due move that waits, thrown inside its transaction so everything it did rolls back (MIG-3). */
final class MigrationHeld extends RuntimeException
{
    public function __construct(public readonly MigrationHold $hold, public readonly ?string $detail = null)
    {
        parent::__construct($hold->message());
    }
}
