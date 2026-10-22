<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\TaskAncestryFacts;
use App\Services\Tasks\TaskAncestryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskAncestryFacts::class)]
#[CoversClass(TaskAncestryRepository::class)]
final class TaskAncestryFactsTest extends TestCase
{
    public function test_invalid_facts_carry_only_generic_disclosure_state(): void
    {
        foreach ([TaskAncestryFacts::invalid(), TaskAncestryFacts::classify(1, [], [])] as $facts) {
            $this->assertSame('invalid', $facts->organizationState());
            $this->assertSame(['validOrganization' => false, 'visibleToClient' => false], get_object_vars($facts));
        }
    }

    public function test_a_task_without_a_list_is_a_valid_root_whether_public_or_private(): void
    {
        foreach ([true, false] as $visible) {
            $row = ['id' => 1, 'project_id' => 4, 'parent_id' => null, 'list_id' => null, 'visible' => $visible, 'project_visible' => true];
            $facts = TaskAncestryFacts::classify(1, [1 => $row], []);
            $this->assertSame('valid', $facts->organizationState());
            $this->assertSame(['validOrganization' => true, 'visibleToClient' => $visible], get_object_vars($facts));
        }
    }

    public function test_eight_parent_edges_are_valid_but_a_ninth_edge_fails_closed(): void
    {
        foreach ([8 => true, 9 => false] as $edges => $valid) {
            $tasks = [];
            for ($id = 1; $id <= $edges + 1; $id++) {
                $tasks[$id] = ['id' => $id, 'project_id' => 4, 'parent_id' => $id <= $edges ? $id + 1 : null, 'list_id' => null, 'visible' => true, 'project_visible' => true];
            }
            $facts = TaskAncestryFacts::classify(1, $tasks, []);
            $this->assertSame($valid ? 'valid' : 'invalid', $facts->organizationState());
            $this->assertSame(['validOrganization' => $valid, 'visibleToClient' => $valid], get_object_vars($facts));
        }
    }

    public static function rootLists(): iterable
    {
        yield 'public task in public list' => [true, ['project_id' => 4, 'visible' => true, 'deleted' => false], true, true];
        yield 'private task in public list' => [false, ['project_id' => 4, 'visible' => true, 'deleted' => false], true, false];
        yield 'public task in private list' => [true, ['project_id' => 4, 'visible' => false, 'deleted' => false], true, false];
        yield 'private task in private list' => [false, ['project_id' => 4, 'visible' => false, 'deleted' => false], true, false];
        yield 'deleted list' => [true, ['project_id' => 4, 'visible' => true, 'deleted' => true], false, false];
        yield 'missing list' => [true, null, false, false];
        yield 'foreign project list' => [true, ['project_id' => 5, 'visible' => true, 'deleted' => false], false, false];
    }

    #[DataProvider('rootLists')]
    public function test_descendants_inherit_root_list_disclosure_without_foreign_metadata(bool $taskVisible, ?array $list, bool $valid, bool $visible): void
    {
        $child = ['id' => 1, 'project_id' => 4, 'parent_id' => 2, 'list_id' => null, 'visible' => true, 'project_visible' => true];
        $root = ['id' => 2, 'project_id' => 4, 'parent_id' => null, 'list_id' => 3, 'visible' => $taskVisible, 'project_visible' => true];
        $facts = TaskAncestryFacts::classify(1, [1 => $child, 2 => $root], $list === null ? [] : [3 => $list]);
        $this->assertSame($valid ? 'valid' : 'invalid', $facts->organizationState());
        $this->assertSame(['validOrganization' => $valid, 'visibleToClient' => $visible], get_object_vars($facts));
    }

    public function test_an_incomplete_or_cyclic_chain_never_becomes_a_public_root(): void
    {
        $child = ['id' => 1, 'project_id' => 4, 'parent_id' => 2, 'list_id' => null, 'visible' => true, 'project_visible' => true];
        $parent = ['id' => 2, 'project_id' => 4, 'parent_id' => null, 'list_id' => null, 'visible' => true, 'project_visible' => true];
        $this->assertFalse(TaskAncestryFacts::classify(1, [1 => $child], [])->validOrganization);
        $this->assertTrue(TaskAncestryFacts::classify(1, [1 => $child, 2 => $parent], [])->visibleToClient);
        $parent['parent_id'] = 1;
        $this->assertFalse(TaskAncestryFacts::classify(1, [1 => $child, 2 => $parent], [])->validOrganization);
        $parent['parent_id'] = null;
        $parent['project_id'] = 5;
        $this->assertFalse(TaskAncestryFacts::classify(1, [1 => $child, 2 => $parent], [])->validOrganization);
    }

    public function test_hidden_project_is_not_disclosed_even_with_public_tasks_and_list(): void
    {
        $row = ['id' => 1, 'project_id' => 4, 'parent_id' => null, 'list_id' => 3, 'visible' => true, 'project_visible' => false];
        $facts = TaskAncestryFacts::classify(1, [1 => $row], [3 => ['project_id' => 4, 'visible' => true, 'deleted' => false]]);
        $this->assertTrue($facts->validOrganization);
        $this->assertFalse($facts->visibleToClient);
        $this->assertSame('valid', $facts->organizationState());
    }

    public function test_batches_accept_a_hundred_ids_and_normalize_duplicates_without_truncating(): void
    {
        $this->assertSame(range(1, 100), TaskAncestryRepository::boundedIds(range(1, 100)));
        $this->assertSame([3, 1], TaskAncestryRepository::boundedIds([3, 1, 3]));
        $this->assertSame([3, 1, 7], TaskAncestryRepository::boundedIds([3, 3, 1, 1, 7]));
        $this->assertSame([], TaskAncestryRepository::boundedIds([]));
    }

    public static function invalidBatches(): iterable
    {
        yield 'overflow' => [range(1, 101)];
        yield 'non-list' => [[1 => 1]];
        yield 'zero' => [[0]];
        yield 'negative' => [[-1]];
        yield 'numeric string' => [['1']];
        yield 'fraction' => [[1.5]];
    }

    #[DataProvider('invalidBatches')]
    public function test_invalid_batches_are_rejected_rather_than_silently_filtered(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskAncestryRepository::boundedIds($ids);
    }
}
