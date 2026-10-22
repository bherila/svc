<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskPatchData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TaskPlanningDataTest extends TestCase
{
    public function test_new_tasks_are_open_and_internal_and_cannot_set_billing_or_ownership_facts(): void
    {
        $data = TaskCreateData::fromValidated(['title' => 'Synthetic new task', 'status' => 'completed', 'completed_at' => '2026-10-20',
            'workspace_id' => 99, 'client_project_id' => 99, 'milestone_price_amount' => 1, 'client_invoice_line_id' => 99]);
        $attributes = $data->attributes();
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

    public function test_partial_updates_distinguish_omitted_fields_from_explicit_clears(): void
    {
        $omitted = TaskPatchData::fromValidated(['title' => 'Synthetic title']);
        $this->assertNull($omitted->assigneeIds);
        $this->assertSame(['title' => 'Synthetic title'], $omitted->attributes('2026-10-20', '2026-10-30'));
        $cleared = TaskPatchData::fromValidated(['starts_on' => null, 'description' => null, 'assignee_ids' => [], 'milestone_price_amount' => 1]);
        $this->assertSame([], $cleared->assigneeIds);
        $this->assertSame(['description' => null, 'starts_on' => null], $cleared->attributes('2026-10-20', '2026-10-30'));
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
        foreach ([[$id, $id], ['named' => $id], array_map(fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), range(1, 11))] as $ids) {
            try {
                TaskPatchData::fromValidated(['assignee_ids' => $ids]);
                $this->fail('Non-list, duplicate or oversized responsibility input must be refused.');
            } catch (ValidationException $exception) {
                $this->assertNotSame([], $exception->errors());
            }
        }
    }
}
