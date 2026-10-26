<?php

namespace App\Services\Tasks\Organization;

use App\Services\Tasks\TaskAccessFacts;

/** Current scoped records and authority captured under the organization mutex. */
final readonly class TaskOrganizationContext
{
    public function __construct(public TaskOrganizationRecords $records, public TaskAccessFacts $access) {}
}
