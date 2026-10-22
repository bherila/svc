<?php

declare(strict_types=1);

namespace App\Services\Tasks\Organization;

use App\Services\Tasks\TaskAncestryFacts;
use DomainException;

/** Immutable topology facts; no queries, permissions, completion or billing effects. */
final readonly class TaskGraph
{
    public const int MAX_TASKS = 1000;

    public const int MAX_LISTS = 100;

    public const int MAX_SIBLINGS = 100;

    /**
     * @param  array<string, array{id:int,parent:?string,list:?string,position:int,version:string}>  $tasks
     * @param  array<string, array{id:int,deleted:bool,position:int,version:string}>  $lists
     */
    public function __construct(public array $tasks, public array $lists, public int $stateVersion)
    {
        if (count($tasks) > self::MAX_TASKS || count($lists) > self::MAX_LISTS) {
            throw new DomainException('Organization operations support at most 1000 tasks and 100 lists per project.');
        }
    }

    public function assertValid(): void
    {
        foreach (array_keys($this->tasks) as $id) {
            $this->rootList($id);
        }
    }

    public function rootList(string $id): ?string
    {
        $seen = [];
        for ($depth = 0; $depth <= TaskAncestryFacts::MAX_DEPTH; $depth++) {
            $row = $this->tasks[$id] ?? null;
            if ($row === null || isset($seen[$id])) {
                throw new DomainException('The task organization is invalid.');
            }
            $seen[$id] = true;
            if ($row['parent'] !== null) {
                if ($row['list'] !== null) {
                    throw new DomainException('A subtask inherits its root list.');
                }
                $id = $row['parent'];

                continue;
            }
            if ($row['list'] !== null && ! isset($this->lists[$row['list']])) {
                throw new DomainException('The task organization is invalid.');
            }

            return $row['list'];
        }

        throw new DomainException('Task trees may have at most eight parent edges.');
    }

    /** @return list<string> */
    public function siblings(TaskContainer $container): array
    {
        $this->assertContainer($container);
        $rows = array_filter($this->tasks, static fn (array $task): bool => $task['parent'] === $container->parentId
            && ($container->parentId !== null || $task['list'] === $container->listId));
        uasort($rows, static fn (array $left, array $right): int => [$left['position'], $left['id']] <=> [$right['position'], $right['id']]);

        return array_keys($rows);
    }

    public function graphVersion(): string
    {
        return hash('sha256', json_encode([$this->stateVersion, $this->tasks, $this->lists], JSON_THROW_ON_ERROR));
    }

    public function containerVersion(TaskContainer $container): string
    {
        $siblings = [];
        foreach ($this->siblings($container) as $id) {
            $siblings[$id] = [$this->tasks[$id]['version'], $this->tasks[$id]['position']];
        }
        $owner = $container->parentId !== null ? $this->tasks[$container->parentId]['version']
            : ($container->listId !== null ? $this->lists[$container->listId]['version'] : null);

        return hash('sha256', json_encode([$this->stateVersion, $container->key(), $owner, $siblings], JSON_THROW_ON_ERROR));
    }

    public function move(string $id, TaskContainer $target): self
    {
        return $this->place($id, $target, false);
    }

    /** A newly created task has no established order even if it starts unlisted. */
    public function append(string $id, TaskContainer $target): self
    {
        return $this->place($id, $target, true);
    }

    private function place(string $id, TaskContainer $target, bool $append): self
    {
        $this->assertValid();
        $this->assertPlacement($target);
        $task = $this->tasks[$id] ?? throw new DomainException('The requested task is unavailable.');
        if (! $append && $task['parent'] === $target->parentId && $task['list'] === $target->listId) {
            return $this;
        }

        $tasks = $this->tasks;
        $tasks[$id] = [...$task, 'parent' => $target->parentId, 'list' => $target->listId, 'position' => count($this->siblings($target))];
        $next = new self($tasks, $this->lists, $this->stateVersion);
        $next->assertValid();

        return $next;
    }

    public function assertPlacement(TaskContainer $target): void
    {
        $this->assertContainer($target);
        $inherited = $target->parentId === null ? $target->listId : $this->rootList($target->parentId);
        if ($inherited !== null && $this->lists[$inherited]['deleted']) {
            throw new DomainException('The destination list is archived.');
        }
    }

    public function assertCanCreate(TaskContainer $target): void
    {
        if (count($this->tasks) >= self::MAX_TASKS) {
            throw new DomainException('Organization operations support at most 1000 tasks per project.');
        }
        $probe = '__new_task__';
        while (isset($this->tasks[$probe])) {
            $probe .= '_';
        }
        $tasks = $this->tasks;
        $tasks[$probe] = ['id' => PHP_INT_MAX, 'parent' => null, 'list' => null, 'position' => 0, 'version' => 'preview'];
        $placed = (new self($tasks, $this->lists, $this->stateVersion))->append($probe, $target);
        $placed->reorder($target, $placed->siblings($target));
    }

    /** @param list<string> $ids */
    public function reorder(TaskContainer $container, array $ids): self
    {
        $this->assertValid();
        $siblings = $this->siblings($container);
        if (count($siblings) > self::MAX_SIBLINGS) {
            throw new DomainException('A reorder supports at most 100 siblings.');
        }
        $expected = $siblings;
        $given = $ids;
        sort($expected);
        sort($given);
        if ($given !== $expected) {
            throw new DomainException('Supply the complete current sibling order.');
        }
        $tasks = $this->tasks;
        foreach ($ids as $position => $id) {
            $task = $tasks[$id] ?? throw new DomainException('The requested task is unavailable.');
            $tasks[$id] = [...$task, 'position' => $position];
        }

        return new self($tasks, $this->lists, $this->stateVersion);
    }

    private function assertContainer(TaskContainer $container): void
    {
        if (($container->parentId !== null && ! isset($this->tasks[$container->parentId]))
            || ($container->listId !== null && ! isset($this->lists[$container->listId]))) {
            throw new DomainException('The requested container is unavailable.');
        }
    }
}
