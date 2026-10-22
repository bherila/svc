<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

/** Persistence receives explicit additions/removals, never permission changes. */
final readonly class TaskContactSelection
{
    /** @param list<string> $ids
     * @param  list<string>  $addedIds
     * @param  list<string>  $removedIds
     */
    public function __construct(public array $ids, public array $addedIds, public array $removedIds) {}

    public function changed(): bool
    {
        return $this->addedIds !== [] || $this->removedIds !== [];
    }
}
