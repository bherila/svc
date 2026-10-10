<?php

namespace App\Exceptions;

use App\Support\Access\AccessRefusal;
use DomainException;

/**
 * An access mutation was refused, and nothing was written.
 *
 * A `DomainException`, so an HTTP caller that does not handle it renders the
 * refusal as a 422 rather than a server error. The reason is carried as a code
 * so a caller can choose its own status and wording - an authorization refusal
 * and a "change the role instead" refusal want different answers.
 */
final class AccessMutationRefused extends DomainException
{
    public function __construct(public readonly AccessRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
