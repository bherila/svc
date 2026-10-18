<?php

namespace App\Services\Credentials;

use RuntimeException;

/** More credentials matched than the caller allowed; nothing was revoked. */
final class AmbiguousRevocation extends RuntimeException
{
    public function __construct(public readonly int $matched)
    {
        parent::__construct("{$matched} live tokens share that name.");
    }
}
