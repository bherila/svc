<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskInputRules;
use App\Services\Tasks\TaskPatchData;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TaskCreateData::class)]
#[CoversClass(TaskPatchData::class)]
#[CoversClass(TaskInputRules::class)]
final class TaskPlanningDataTest extends TestCase
{
    public function test_new_tasks_are_open_and_internal_and_cannot_set_billing_or_ownership_facts(): void
    {
        $data = TaskCreateData::fromValidated(['title' => 'Synthetic new task', 'status' => 'completed', 'completed_at' => '2026-10-20',
            'workspace_id' => 99, 'client_project_id' => 99, 'milestone_price_amount' => 1, 'client_invoice_line_id' => 99]);
        $attributes = $data->attributes();
        $this->assertSame('Synthetic new task', $attributes['title']);
        $this->assertSame('open', $attributes['status']);
        $this->assertFalse($attributes['is_visible_to_client']);
        $this->assertNull($attributes['starts_on']);
        $this->assertNull($attributes['due_on']);
        $this->assertNull($attributes['description']);
        $this->assertSame([], $data->assigneeIds);
        foreach (['workspace_id', 'client_project_id', 'milestone_price_amount', 'client_invoice_line_id', 'completed_at'] as $protected) {
            $this->assertArrayNotHasKey($protected, $attributes);
        }
    }

    public function test_create_preserves_explicit_planning_and_visibility_without_putting_assignments_in_task_columns(): void
    {
        $ids = ['00000000-0000-4000-8000-000000000001'];
        $data = TaskCreateData::fromValidated(['title' => 'Synthetic planned task', 'description' => 'Synthetic planning detail',
            'is_visible_to_client' => true, 'starts_on' => '2026-10-20', 'due_on' => '2026-10-30', 'assignee_ids' => $ids]);
        $this->assertSame(['title' => 'Synthetic planned task', 'description' => 'Synthetic planning detail', 'is_visible_to_client' => true,
            'starts_on' => '2026-10-20', 'due_on' => '2026-10-30', 'status' => 'open'], $data->attributes());
        $this->assertSame($ids, $data->assigneeIds);
    }

    public function test_create_requires_a_title_and_rejects_reversed_calendar_dates(): void
    {
        foreach ([['title', []], ['title', ['title' => '']],
            ['due_on', ['title' => 'Synthetic reversed dates', 'starts_on' => '2026-10-30', 'due_on' => '2026-10-20']]] as [$field, $input]) {
            try {
                TaskCreateData::fromValidated($input);
                $this->fail('Invalid required title or calendar order must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_each_calendar_endpoint_can_be_absent_or_cleared_independently(): void
    {
        $this->assertSame('2026-10-20', TaskCreateData::fromValidated(['title' => 'Synthetic start only', 'starts_on' => '2026-10-20'])->startsOn);
        $this->assertSame('2026-10-30', TaskCreateData::fromValidated(['title' => 'Synthetic due only', 'due_on' => '2026-10-30'])->dueOn);
        $this->assertSame(['due_on' => null], TaskPatchData::fromValidated(['due_on' => null])->attributes('2026-10-20', '2026-10-30'));
        $this->assertSame(['starts_on' => null], TaskPatchData::fromValidated(['starts_on' => null])->attributes('2026-10-20', '2026-10-30'));
        $this->assertSame([], TaskPatchData::fromValidated([])->attributes(null, null));
    }

    public function test_partial_updates_distinguish_omitted_fields_from_explicit_clears(): void
    {
        $omitted = TaskPatchData::fromValidated(['title' => 'Synthetic title']);
        $this->assertNull($omitted->assigneeIds);
        $this->assertSame(['title' => 'Synthetic title'], $omitted->attributes('2026-10-20', '2026-10-30'));
        $cleared = TaskPatchData::fromValidated(['starts_on' => null, 'description' => null, 'assignee_ids' => [], 'milestone_price_amount' => 1]);
        $this->assertSame([], $cleared->assigneeIds);
        $this->assertSame(['description' => null, 'starts_on' => null], $cleared->attributes('2026-10-20', '2026-10-30'));
    }

    public function test_patch_refuses_blank_nonnullable_fields_while_omitted_fields_stay_unchanged(): void
    {
        $this->assertSame([], TaskPatchData::fromValidated([])->attributes('2026-10-20', '2026-10-30'));
        foreach (['title', 'status'] as $field) {
            foreach (['', '   ', null] as $blank) {
                try {
                    TaskPatchData::fromValidated([$field => $blank]);
                    $this->fail('A present nonnullable field must not be blank.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey($field, $exception->errors());
                }
            }
        }
    }

    public function test_date_order_uses_the_merged_stored_start_not_just_the_patch(): void
    {
        $this->assertSame(['due_on' => '2026-10-20'], TaskPatchData::fromValidated(['due_on' => '2026-10-20'])->attributes('2026-10-20', null));
        try {
            TaskPatchData::fromValidated(['due_on' => '2026-10-19'])->attributes('2026-10-20', null);
            $this->fail('An earlier due date must be refused against the stored start.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_on', $exception->errors());
        }
    }

    public function test_account_responsibility_is_a_bounded_list_of_distinct_ids(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $ten = array_map(fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), range(1, 10));
        $this->assertSame($ten, TaskPatchData::fromValidated(['assignee_ids' => $ten])->assigneeIds);
        foreach ([[$id, $id], ['named' => $id], [''], array_map(fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), range(1, 11))] as $ids) {
            try {
                TaskPatchData::fromValidated(['assignee_ids' => $ids]);
                $this->fail('Non-list, duplicate or oversized responsibility input must be refused.');
            } catch (ValidationException $exception) {
                $this->assertNotSame([], $exception->errors());
            }
        }
    }
}
