<?php

declare(strict_types=1);

namespace App\Services\Tasks\Planning;

use App\Services\Tasks\Organization\TaskGraph;
use DomainException;

/** Database-free facts from one scoped project; never changes lifecycle or ancestry. */
final readonly class TaskDependencyGraph
{
    public const int MAX_PREREQUISITES = 25;

    public const int MAX_EDGES = 5000;

    /** @param array<string, list<string>> $prerequisites */
    private function __construct(private array $prerequisites) {}

    /**
     * Public task identities must already come from one authorized project.
     *
     * @param  array<array-key, string>  $taskIds
     * @param  array<array-key, TaskDependencyEdge>  $edges
     */
    public static function fromFacts(array $taskIds, array $edges): self
    {
        if (! array_is_list($taskIds) || count($taskIds) > TaskGraph::MAX_TASKS
            || ! array_is_list($edges) || count($edges) > self::MAX_EDGES) {
            throw new DomainException('Dependency management supports at most 1000 tasks and 5000 edges per project.');
        }
        $prerequisites = [];
        foreach ($taskIds as $id) {
            if ($id === '' || isset($prerequisites[$id])) {
                throw new DomainException('The task dependency graph is invalid.');
            }
            $prerequisites[$id] = [];
        }
        foreach ($edges as $edge) {
            if (! isset($prerequisites[$edge->taskId], $prerequisites[$edge->prerequisiteId])) {
                throw new DomainException('The task dependency graph is invalid.');
            }
            $prerequisites[$edge->taskId][] = $edge->prerequisiteId;
        }

        return self::validated($prerequisites);
    }

    /** Complete replacement; omission and identity validation belong to the shared command DTO.
     * @param  array<array-key, string>  $ids
     */
    public function replacePrerequisites(string $taskId, array $ids): self
    {
        $this->prerequisites($taskId);
        if (! array_is_list($ids)) {
            throw new DomainException('Supply a list of prerequisite task identities.');
        }
        $prerequisites = $this->prerequisites;
        $prerequisites[$taskId] = $ids;

        return self::validated($prerequisites);
    }

    /** A successful new-key command advances its source even for an unchanged set.
     * @param  array<array-key, string>  $ids
     */
    public function changePrerequisites(string $taskId, array $ids): TaskDependencyChange
    {
        $next = $this->replacePrerequisites($taskId, $ids);
        $before = $this->prerequisites($taskId);
        $after = $next->prerequisites($taskId);
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        $advance = array_values(array_unique([$taskId, ...$added, ...$removed]));
        sort($advance, SORT_STRING);

        return new TaskDependencyChange($next, $added, $removed, $advance);
    }

    /** @return list<string> */
    public function prerequisites(string $taskId): array
    {
        return $this->prerequisites[$taskId] ?? throw new DomainException('The requested task is unavailable.');
    }

    /** @return list<string> */
    public function dependents(string $taskId): array
    {
        $this->prerequisites($taskId);

        return array_keys(array_filter($this->prerequisites, static fn (array $ids): bool => in_array($taskId, $ids, true)));
    }

    /** @return list<TaskDependencyEdge> */
    public function edges(): array
    {
        $edges = [];
        foreach ($this->prerequisites as $id => $ids) {
            foreach ($ids as $prerequisite) {
                $edges[] = new TaskDependencyEdge($id, $prerequisite);
            }
        }

        return $edges;
    }

    /** @param array<string, list<string>> $prerequisites */
    private static function validated(array $prerequisites): self
    {
        $incoming = array_fill_keys(array_keys($prerequisites), 0);
        $edgeCount = 0;
        foreach ($prerequisites as $taskId => &$ids) {
            if (count($ids) > self::MAX_PREREQUISITES) {
                throw new DomainException('A task supports at most 25 prerequisites.');
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new DomainException('Supply each prerequisite once.');
            }
            foreach ($ids as $id) {
                if ($id === $taskId || ! isset($prerequisites[$id])) {
                    throw new DomainException('Choose other tasks in the same project.');
                }
                $incoming[$id]++;
                $edgeCount++;
            }
            sort($ids, SORT_STRING);
        }
        unset($ids);
        if ($edgeCount > self::MAX_EDGES) {
            throw new DomainException('A project supports at most 5000 dependency edges.');
        }
        ksort($prerequisites, SORT_STRING);
        $queue = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        for ($index = 0; $index < count($queue); $index++) {
            foreach ($prerequisites[$queue[$index]] as $id) {
                $incoming[$id]--;
                if ($incoming[$id] === 0) {
                    $queue[] = $id;
                }
            }
        }
        if (count($queue) !== count($prerequisites)) {
            throw new DomainException('Task dependencies must not contain a cycle.');
        }

        return new self($prerequisites);
    }
}
