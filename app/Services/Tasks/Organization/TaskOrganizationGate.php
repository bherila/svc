<?php

namespace App\Services\Tasks\Organization;

final class TaskOrganizationGate
{
    public static function assertEnabled(): void
    {
        abort_unless(config('task_organization.enabled') === true, 404);
    }
}
