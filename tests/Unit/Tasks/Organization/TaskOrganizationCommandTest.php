<?php

namespace Tests\Unit\Tasks\Organization;

use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Services\Tasks\TaskInputRules;
use App\Support\AgentApi\ExplicitConfirmation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TaskOrganizationCommand::class)]
#[CoversClass(TaskInputRules::class)]
#[CoversClass(ExplicitConfirmation::class)]
final class TaskOrganizationCommandTest extends TestCase
{
    public function test_mutation_versions_and_complete_nullable_container_identity_are_required(): void
    {
        $version = str_repeat('a', 64);
        $valid = ['task_id' => '00000000-0000-4000-8000-000000000001', 'expected_version' => $version,
            'expected_graph_version' => $version, 'parent_id' => null, 'list_id' => null];
        $this->assertSame($valid, TaskOrganizationCommand::fromInput('tasks.move', $valid)->data);
        foreach (array_keys($valid) as $field) {
            $input = $valid;
            unset($input[$field]);
            try {
                TaskOrganizationCommand::fromInput('tasks.move', $input);
                $this->fail('Missing '.$field.' was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_scoped_ownership_cannot_be_overridden_by_top_level_or_nested_task_body_fields(): void
    {
        $valid = ['expected_graph_version' => str_repeat('a', 64), 'parent_id' => null, 'list_id' => null,
            'task' => ['title' => 'Synthetic validated task']];
        $this->assertSame('Synthetic validated task', TaskOrganizationCommand::fromInput('tasks.create', $valid)->data['task']['title']);
        foreach (['workspace_id', 'client_project_id', 'parent_task_id', 'client_task_list_id', 'organization_position', 'lock_version', 'status', 'completed_at', 'milestone_price_amount', 'client_invoice_line_id'] as $field) {
            foreach ([false, true] as $nested) {
                $input = $valid;
                if ($nested) {
                    $input['task'][$field] = 'synthetic-forged-value';
                } else {
                    $input[$field] = 'synthetic-forged-value';
                }
                try {
                    TaskOrganizationCommand::fromInput('tasks.create', $input);
                    $this->fail('A forged ownership/lifecycle field was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        }
    }

    public function test_archiving_needs_explicit_true_and_empty_reorder_is_a_valid_complete_empty_set(): void
    {
        $version = str_repeat('a', 64);
        $input = ['list_id' => '00000000-0000-4000-8000-000000000001', 'expected_graph_version' => $version, 'expected_version' => $version];
        foreach ([null, false, 0, 'yes'] as $confirm) {
            try {
                TaskOrganizationCommand::fromInput('lists.archive', $input + ['confirm' => $confirm]);
                $this->fail('An implicit archive confirmation was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('confirm', $exception->errors());
            }
        }
        $this->assertTrue(TaskOrganizationCommand::fromInput('lists.archive', $input + ['confirm' => true])->data['confirm']);
        $empty = TaskOrganizationCommand::fromInput('tasks.reorder', ['expected_container_version' => $version, 'parent_id' => null, 'list_id' => null, 'ordered_ids' => []]);
        $this->assertSame([], $empty->orderedIds());
    }
}
