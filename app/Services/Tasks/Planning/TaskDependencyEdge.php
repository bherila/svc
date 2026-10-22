<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

/** A task depends on its prerequisite; neither identity describes parenthood. */
final readonly class TaskDependencyEdge
{
    public function __construct(public string $taskId, public string $prerequisiteId) {}
}
