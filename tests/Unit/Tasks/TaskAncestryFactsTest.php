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
        $facts = TaskAncestryFacts::classify(1, [], []);
        $this->assertSame('invalid', $facts->organizationState());
        $this->assertSame(['validOrganization' => false, 'visibleToClient' => false], get_object_vars($facts));
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
