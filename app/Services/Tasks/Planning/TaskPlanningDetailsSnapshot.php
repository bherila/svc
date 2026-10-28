<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

/** Authorized value facts; no models, lazy relations, contact records or mutable cache. */
final readonly class TaskPlanningDetailsSnapshot
{
    /** @param list<array{id:string,name:string,is_archived:bool,version:string}> $labels
     * @param array{blocked:bool,incomplete_prerequisite_count:int,dependent_count:int} $dependencySummary */
    public function __construct(public string $taskId, public string $version, public array $labels, public array $dependencySummary) {}
}
