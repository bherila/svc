<?php

declare(strict_types=1);

namespace App\Services\Tasks\Organization;

use DomainException;

/** A task parent or a root-list container, identified only by public UUIDs. */
final readonly class TaskContainer
{
    public function __construct(public ?string $parentId, public ?string $listId)
    {
        if ($parentId !== null && $listId !== null) {
            throw new DomainException('A subtask inherits its root list.');
        }
    }

    public function key(): string
    {
        return json_encode([$this->parentId, $this->listId], JSON_THROW_ON_ERROR);
    }
}
