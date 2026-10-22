<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

/** Explicit pivot delta and revision targets; applying it remains transactional. */
final readonly class TaskDependencyChange
{
    /** @param list<string> $addedIds
     * @param  list<string>  $removedIds
     * @param  list<string>  $taskIdsToAdvance
     */
    public function __construct(public TaskDependencyGraph $graph, public array $addedIds, public array $removedIds, public array $taskIdsToAdvance) {}

    public function changed(): bool
    {
        return $this->addedIds !== [] || $this->removedIds !== [];
    }
}
