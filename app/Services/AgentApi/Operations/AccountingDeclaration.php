<?php

namespace App\Services\AgentApi\Operations;

/**
 * What distinguishes an accounting read on its declaration: the code-owned
 * response component its output is published as and, for an export, the
 * non-JSON media type it can also stream. The catalog reads these fields
 * instead of recognising operations by id.
 */
final readonly class AccountingDeclaration
{
    public function __construct(public string $component, public ?string $downloadMediaType = null) {}
}
