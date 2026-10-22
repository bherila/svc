<?php

namespace Tests\Unit\Tasks\Organization;

use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskGraph;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskGraph::class)]
#[CoversClass(TaskContainer::class)]
final class TaskGraphTest extends TestCase
{
    public function test_reparenting_preserves_inherited_root_list_and_rejects_a_conflicting_child_list(): void
    {
        $graph = $this->graph(['a' => [null, 'private'], 'b' => [null, null], 'c' => ['b', null]]);
        $moved = $graph->move('b', new TaskContainer('a', null));
        $this->assertSame('private', $moved->rootList('c'));
        $this->assertNull($moved->tasks['b']['list']);
        $this->assertSame('b', $moved->tasks['c']['parent']);
        $this->assertNull($graph->tasks['b']['parent'], 'The original snapshot is immutable.');
        $this->expectException(DomainException::class);
        $graph->move('b', new TaskContainer('a', 'private'));
    }

    public function test_a_move_cannot_introduce_a_self_edge_or_cycle(): void
    {
        $graph = $this->graph(['a' => [null, null], 'b' => ['a', null], 'c' => ['b', null]]);
        foreach (['a', 'c'] as $parent) {
            try {
                $graph->move('a', new TaskContainer($parent, null));
                $this->fail('A cyclic move was accepted.');
            } catch (DomainException) {
                $this->assertNull($graph->tasks['a']['parent']);
            }
        }
    }

    public function test_depth_eight_is_allowed_but_moving_a_subtree_below_it_is_refused(): void
    {
        $tasks = ['root' => [null, null], 'moving' => [null, null], 'child' => ['moving', null]];
        $parent = 'root';
        for ($depth = 1; $depth <= 7; $depth++) {
            $tasks['ancestor'.$depth] = [$parent, null];
            $parent = 'ancestor'.$depth;
        }
        $graph = $this->graph($tasks);
        $this->assertSame('ancestor7', $graph->move('child', new TaskContainer('ancestor7', null))->tasks['child']['parent']);
        $this->expectException(DomainException::class);
        $graph->move('moving', new TaskContainer('ancestor7', null));
    }

    public function test_reorder_requires_the_complete_sibling_set_and_keeps_other_containers_out(): void
    {
        $graph = $this->graph(['a' => [null, null], 'b' => [null, null], 'c' => [null, 'private']]);
        $container = new TaskContainer(null, null);
        $this->assertSame(['b', 'a'], $graph->reorder($container, ['b', 'a'])->siblings($container));
        foreach ([['a'], ['a', 'a'], ['a', 'c'], ['a', 'b', 'foreign']] as $ids) {
            try {
                $graph->reorder($container, $ids);
                $this->fail('An incomplete or foreign sibling set was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('Supply the complete current sibling order.', $exception->getMessage());
            }
        }
    }

    public function test_current_sibling_versions_and_container_identity_change_the_revision(): void
    {
        $graph = $this->graph(['a' => [null, null], 'b' => [null, 'private']]);
        $container = new TaskContainer(null, null);
        $changed = $graph->tasks;
        $changed['a']['version'] = 'new-assignment-revision';
        $this->assertNotSame($graph->containerVersion($container), (new TaskGraph($changed, $graph->lists, 0))->containerVersion($container));
        $this->assertNotSame($graph->containerVersion($container), $graph->containerVersion(new TaskContainer(null, 'private')));
    }

    public function test_analysis_caps_fail_explicitly_instead_of_silently_omitting_graph_rows(): void
    {
        $nodes = array_fill_keys(array_map(static fn (int $id): string => 'task-'.$id, range(1, 1000)), [null, null]);
        $graph = $this->graph($nodes);
        $this->assertCount(1000, $graph->tasks);
        try {
            $this->graph($nodes + ['overflow' => [null, null]]);
            $this->fail('The graph silently omitted an over-budget task.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('1000 tasks and 100 lists', $exception->getMessage());
        }
        $lists = [];
        for ($id = 1; $id <= 101; $id++) {
            $lists['list-'.$id] = ['id' => $id, 'deleted' => true, 'position' => $id, 'version' => 'retained-version'];
        }
        $this->expectException(DomainException::class);
        new TaskGraph([], $lists, 0);
    }

    public function test_exactly_one_hundred_siblings_reorder_but_one_hundred_and_one_fail_explicitly(): void
    {
        $nodes = array_fill_keys(array_map(static fn (int $id): string => 'task-'.$id, range(1, 100)), [null, null]);
        $graph = $this->graph($nodes);
        $container = new TaskContainer(null, null);
        $ids = array_reverse(array_keys($nodes));
        $this->assertSame($ids, $graph->reorder($container, $ids)->siblings($container));
        $graph = $this->graph($nodes + ['overflow' => [null, null]]);
        $this->expectExceptionMessage('A reorder supports at most 100 siblings.');
        $graph->reorder($container, array_keys($graph->tasks));
    }

    public function test_retained_archived_list_is_valid_but_cannot_receive_new_descendants(): void
    {
        $graph = $this->graph(['a' => [null, 'private'], 'b' => ['a', null], 'moving' => [null, null]]);
        $lists = $graph->lists;
        $lists['private']['deleted'] = true;
        $archived = new TaskGraph($graph->tasks, $lists, 1);
        $archived->assertValid();
        $this->assertSame('private', $archived->rootList('b'));
        $this->assertNull($archived->move('b', new TaskContainer(null, null))->rootList('b'));
        $this->expectExceptionMessage('The destination list is archived.');
        $archived->move('moving', new TaskContainer('b', null));
    }

    /** @param array<string, array{?string, ?string}> $nodes */
    private function graph(array $nodes): TaskGraph
    {
        $tasks = [];
        foreach ($nodes as $id => [$parent, $list]) {
            $tasks[$id] = ['id' => count($tasks) + 1, 'parent' => $parent, 'list' => $list, 'position' => 0, 'version' => 'version-'.$id];
        }

        return new TaskGraph($tasks, ['private' => ['id' => 1, 'deleted' => false, 'position' => 0, 'version' => 'list-version']], 0);
    }
}
