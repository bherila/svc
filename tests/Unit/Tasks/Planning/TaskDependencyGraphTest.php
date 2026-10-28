<?php

namespace Tests\Unit\Tasks\Planning;

use App\Services\Tasks\Organization\TaskGraph;
use App\Services\Tasks\Planning\TaskDependencyChange;
use App\Services\Tasks\Planning\TaskDependencyEdge;
use App\Services\Tasks\Planning\TaskDependencyGraph;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskDependencyGraph::class)]
#[CoversClass(TaskDependencyEdge::class)]
#[CoversClass(TaskDependencyChange::class)]
#[CoversClass(TaskGraph::class)]
final class TaskDependencyGraphTest extends TestCase
{
    public function test_a_diamond_has_distinct_prerequisite_and_dependent_directions(): void
    {
        $graph = TaskDependencyGraph::fromFacts(['a', 'b', 'c', 'd'], [
            new TaskDependencyEdge('a', 'c'), new TaskDependencyEdge('a', 'b'),
            new TaskDependencyEdge('b', 'd'), new TaskDependencyEdge('c', 'd'),
        ]);
        $this->assertSame(['b', 'c'], $graph->prerequisites('a'));
        $this->assertSame(['a'], $graph->dependents('b'));
        $this->assertSame(['b', 'c'], $graph->dependents('d'));
        $this->assertSame([], $graph->prerequisites('d'));
        $this->assertSame([['a', 'b'], ['a', 'c'], ['b', 'd'], ['c', 'd']], $this->pairs($graph));
    }

    public function test_replacement_and_clear_leave_the_original_graph_unchanged(): void
    {
        $original = TaskDependencyGraph::fromFacts(['a', 'b', 'c'], [new TaskDependencyEdge('a', 'b')]);
        $replaced = $original->replacePrerequisites('a', ['c']);
        $cleared = $replaced->replacePrerequisites('a', []);
        $this->assertSame([['a', 'b']], $this->pairs($original));
        $this->assertSame([['a', 'c']], $this->pairs($replaced));
        $this->assertSame([], $this->pairs($cleared));
        $this->assertSame([], $replaced->dependents('b'));
        $this->assertSame(['a'], $replaced->dependents('c'));
        $this->assertSame($this->pairs($original), $this->pairs($original->replacePrerequisites('a', ['b'])));
    }

    public function test_replacement_revision_targets_cover_both_edge_views_without_touching_unchanged_neighbors(): void
    {
        $graph = TaskDependencyGraph::fromFacts(['a', 'b', 'c', 'd'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('a', 'c')]);
        $change = $graph->changePrerequisites('a', ['c', 'd']);
        $this->assertSame(['d'], $change->addedIds);
        $this->assertSame(['b'], $change->removedIds);
        $this->assertSame(['a', 'b', 'd'], $change->taskIdsToAdvance);
        $this->assertTrue($change->changed());
        $this->assertSame([['a', 'c'], ['a', 'd']], $this->pairs($change->graph));
        $this->assertSame([['a', 'b'], ['a', 'c']], $this->pairs($graph));

        $same = $graph->changePrerequisites('a', ['c', 'b']);
        $this->assertSame([], $same->addedIds);
        $this->assertSame([], $same->removedIds);
        $this->assertSame(['a'], $same->taskIdsToAdvance);
        $this->assertFalse($same->changed());

        $clear = $graph->changePrerequisites('a', []);
        $this->assertSame(['b', 'c'], $clear->removedIds);
        $this->assertSame(['a', 'b', 'c'], $clear->taskIdsToAdvance);
        $this->assertTrue($clear->changed());
    }

    public function test_unsorted_facts_produce_deterministic_edges_dependents_and_list_shaped_mixed_deltas(): void
    {
        $graph = TaskDependencyGraph::fromFacts(['z', 'd', 'c', 'a', 'b'], [new TaskDependencyEdge('z', 'b'), new TaskDependencyEdge('c', 'd'), new TaskDependencyEdge('a', 'd')]);
        $this->assertSame([['a', 'd'], ['c', 'd'], ['z', 'b']], $this->pairs($graph));
        $this->assertSame(['a', 'c'], $graph->dependents('d'));
        $mixed = TaskDependencyGraph::fromFacts(['a', 'b', 'c', 'd', 'z'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('a', 'c'), new TaskDependencyEdge('a', 'd')])->changePrerequisites('a', ['b', 'd', 'z']);
        $this->assertSame(['z'], $mixed->addedIds);
        $this->assertSame(['c'], $mixed->removedIds);
        $this->assertSame(['a', 'c', 'z'], $mixed->taskIdsToAdvance);
    }

    public function test_long_acyclic_chains_and_exact_task_and_prerequisite_limits_are_supported(): void
    {
        $ids = array_map(static fn (int $id): string => 'task'.$id, range(1, 1000));
        $edges = [];
        for ($id = 1; $id < 1000; $id++) {
            $edges[] = new TaskDependencyEdge('task'.$id, 'task'.($id + 1));
        }
        $graph = TaskDependencyGraph::fromFacts($ids, $edges);
        $this->assertCount(999, $graph->edges());
        $this->assertSame(['task1000'], $graph->prerequisites('task999'));
        $this->assertSame([], $graph->prerequisites('task1000'));
        $prerequisites = array_slice($ids, 1, 25);
        sort($prerequisites, SORT_STRING);
        $this->assertSame($prerequisites, $graph->replacePrerequisites('task1', $prerequisites)->prerequisites('task1'));
        $this->assertSame(1000, TaskGraph::MAX_TASKS);
        $this->assertSame(25, TaskDependencyGraph::MAX_PREREQUISITES);
    }

    public function test_five_thousand_edges_fit_but_replacement_cannot_add_the_five_thousand_and_first(): void
    {
        $ids = array_map(static fn (int $id): string => 'task'.$id, range(0, 225));
        $edges = [];
        for ($source = 0; $source < 200; $source++) {
            for ($prerequisite = 200; $prerequisite < 225; $prerequisite++) {
                $edges[] = new TaskDependencyEdge('task'.$source, 'task'.$prerequisite);
            }
        }
        $graph = TaskDependencyGraph::fromFacts($ids, $edges);
        $this->assertCount(5000, $graph->edges());
        $this->assertSame(5000, TaskDependencyGraph::MAX_EDGES);
        $this->expectException(DomainException::class);
        $graph->replacePrerequisites('task225', ['task200']);
    }

    public static function invalidGraphs(): iterable
    {
        yield 'self edge' => [['a'], [new TaskDependencyEdge('a', 'a')]];
        yield 'opposite edges' => [['a', 'b'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('b', 'a')]];
        yield 'transitive cycle' => [['a', 'b', 'c'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('b', 'c'), new TaskDependencyEdge('c', 'a')]];
        yield 'duplicate edge' => [['a', 'b'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('a', 'b')]];
        yield 'missing source' => [['a'], [new TaskDependencyEdge('foreign-task', 'a')]];
        yield 'missing prerequisite' => [['a'], [new TaskDependencyEdge('a', 'foreign-task')]];
        yield 'duplicate task' => [['a', 'a'], []];
        yield 'empty identity' => [[''], []];
        yield 'sparse task identities' => [[1 => 'a'], []];
        yield 'sparse edge list' => [['a', 'b'], [1 => new TaskDependencyEdge('a', 'b')]];
        yield 'too many tasks' => [array_map(static fn (int $id): string => 'task'.$id, range(1, 1001)), []];
        yield 'too many edges' => [['a', 'b'], array_fill(0, 5001, new TaskDependencyEdge('a', 'b'))];
        yield 'too many prerequisites' => [array_map(static fn (int $id): string => 'task'.$id, range(0, 26)),
            array_map(static fn (int $id): TaskDependencyEdge => new TaskDependencyEdge('task0', 'task'.$id), range(1, 26))];
    }

    #[DataProvider('invalidGraphs')]
    public function test_invalid_scoped_facts_fail_without_echoing_foreign_identities(array $tasks, array $edges): void
    {
        try {
            TaskDependencyGraph::fromFacts($tasks, $edges);
            $this->fail('Invalid graph was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('foreign-task', $exception->getMessage());
        }
    }

    public static function invalidReplacements(): iterable
    {
        yield 'unknown task' => ['foreign-task', []];
        yield 'self edge' => ['a', ['a']];
        yield 'duplicate identities' => ['a', ['b', 'b']];
        yield 'foreign prerequisite' => ['a', ['foreign-task']];
        yield 'opposite edge cycle' => ['b', ['a']];
        yield 'transitive cycle' => ['c', ['a']];
        yield 'sparse list' => ['a', [1 => 'b']];
    }

    #[DataProvider('invalidReplacements')]
    public function test_failed_replacement_preserves_the_original_graph(string $task, array $ids): void
    {
        $graph = TaskDependencyGraph::fromFacts(['a', 'b', 'c'], [new TaskDependencyEdge('a', 'b'), new TaskDependencyEdge('b', 'c')]);
        try {
            $graph->replacePrerequisites($task, $ids);
            $this->fail('Invalid replacement was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('foreign-task', $exception->getMessage());
            $this->assertSame([['a', 'b'], ['b', 'c']], $this->pairs($graph));
        }
    }

    public function test_small_dag_variations_accept_safe_replacements_and_reject_new_cycles(): void
    {
        $ids = array_map(static fn (int $id): string => 'task'.$id, range(0, 11));
        for ($variation = 0; $variation < 10; $variation++) {
            $edges = [];
            for ($source = 0; $source < 11; $source++) {
                for ($target = $source + 1; $target < 12; $target++) {
                    if (($source * 17 + $target * 31 + $variation) % 7 === 0) {
                        $edges[] = new TaskDependencyEdge('task'.$source, 'task'.$target);
                    }
                }
            }
            $graph = TaskDependencyGraph::fromFacts($ids, $edges)->replacePrerequisites('task0', ['task11']);
            $this->assertSame(['task11'], $graph->prerequisites('task0'));
            try {
                $graph->replacePrerequisites('task11', ['task0']);
                $this->fail('A cycle was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('Task dependencies must not contain a cycle.', $exception->getMessage());
            }
        }
    }

    public function test_empty_project_has_no_edges(): void
    {
        $this->assertSame([], TaskDependencyGraph::fromFacts([], [])->edges());
        $this->expectException(DomainException::class);
        TaskDependencyGraph::fromFacts([], [])->dependents('missing');
    }

    private function pairs(TaskDependencyGraph $graph): array
    {
        return array_map(static fn (TaskDependencyEdge $edge): array => [$edge->taskId, $edge->prerequisiteId], $graph->edges());
    }
}
