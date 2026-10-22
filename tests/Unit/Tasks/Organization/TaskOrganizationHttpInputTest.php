<?php

namespace Tests\Unit\Tasks\Organization;

use App\Http\Requests\TaskOrganizationInput;
use App\Services\Tasks\Organization\TaskContainer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TaskOrganizationInput::class)]
#[CoversClass(TaskContainer::class)]
final class TaskOrganizationHttpInputTest extends TestCase
{
    public function test_container_selection_refuses_non_boolean_query_values(): void
    {
        foreach (['yes', 'not-a-boolean', 2, [], ['true']] as $value) {
            try {
                TaskOrganizationInput::container(['container' => $value]);
                $this->fail('A malformed container selector must not choose a task scope.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('container', $exception->errors());
            }
        }
    }

    public function test_read_containers_are_explicit_and_cannot_mix_independent_parent_and_list(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $this->assertNull(TaskOrganizationInput::container([]));
        $this->assertNull(TaskOrganizationInput::container(['container' => 'false']));
        $this->assertSame($id, TaskOrganizationInput::container(['container' => 'true', 'parent_id' => $id])->parentId);
        $root = TaskOrganizationInput::container(['container' => true, 'parent_id' => null, 'list_id' => null]);
        $this->assertNull($root->parentId);
        $this->assertNull($root->listId);
        $this->assertSame($id, TaskOrganizationInput::container(['container' => true, 'list_id' => $id])->listId);
        foreach ([['container' => false, 'parent_id' => $id], ['container' => true, 'parent_id' => $id, 'list_id' => $id], ['unknown' => true]] as $query) {
            try {
                TaskOrganizationInput::container($query);
                $this->fail('An ambiguous/unknown read field was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('organization', $exception->errors());
            }
        }
    }

    public function test_route_owned_record_identity_cannot_be_supplied_even_with_the_same_value_in_a_body(): void
    {
        $this->assertSame(['list_id' => 'synthetic-list', 'name' => 'Synthetic list'], TaskOrganizationInput::body(['name' => 'Synthetic list'], 'synthetic-list', null));
        $this->assertSame(['task_id' => 'synthetic-task', 'parent_id' => null, 'list_id' => null], TaskOrganizationInput::body(['parent_id' => null, 'list_id' => null], null, 'synthetic-task'));
        foreach ([['list_id' => 'synthetic-list'], ['list_id' => 'synthetic-foreign-list']] as $input) {
            try {
                TaskOrganizationInput::body($input, 'synthetic-list', null);
                $this->fail('A body-supplied route identity was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(['organization' => ['Supply the record identity in the route only.']], $exception->errors());
            }
        }
    }
}
