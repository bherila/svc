<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

/** Current scoped business-contact facts contain no account or invitation identity. */
final readonly class TaskContactFact
{
    public function __construct(public string $id, public string $companyId, public bool $archived) {}
}
