<?php

namespace App\Exceptions;

use App\Support\Packages\PackageRefusal;
use DomainException;

/**
 * A package change the rules refuse, with every reason that applies.
 *
 * A `DomainException`, so the website shows it as a validation message, the
 * API answers 422 with the first reason's code, and MCP repeats that code in
 * its tool error. The message names every reason, so one round trip shows the
 * caller all of them. Nothing is written before it is thrown.
 */
final class ServicePackageRefused extends DomainException implements CodedRefusal
{
    /** @param non-empty-list<PackageRefusal> $refusals */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(implode(' ', array_map(static fn (PackageRefusal $refusal): string => $refusal->message(), $refusals)));
    }

    public static function because(PackageRefusal $refusal): self
    {
        return new self([$refusal]);
    }

    public function refusalCode(): string
    {
        return $this->refusals[0]->value;
    }
}
