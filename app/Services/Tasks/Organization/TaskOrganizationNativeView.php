<?php

namespace App\Services\Tasks\Organization;

/** Immutable native page facts and server-owned capabilities. */
final readonly class TaskOrganizationNativeView
{
    /** @param array<string,mixed> $data */
    public function __construct(public array $data) {}
}
