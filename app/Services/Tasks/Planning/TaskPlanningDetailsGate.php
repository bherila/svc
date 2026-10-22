<?php

namespace App\Services\Tasks\Planning;

final class TaskPlanningDetailsGate
{
    public static function assertEnabled(): void
    {
        abort_unless(config('task_planning_details.enabled') === true, 404);
    }
}
