<?php

namespace Tests\Unit\Tasks\Organization;

use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Services\Tasks\TaskInputRules;
use App\Support\AgentApi\ExplicitConfirmation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[CoversClass(TaskOrganizationCommand::class)]
#[CoversClass(TaskInputRules::class)]
#[CoversClass(ExplicitConfirmation::class)]
final class TaskOrganizationCommandTest extends TestCase
{
    public function test_list_visibility_preserves_omission_and_false_or_zero_but_rejects_raw_empty_values(): void
    {
        foreach (['lists.create', 'lists.update'] as $operation) {
            $input = ['expected_graph_version' => str_repeat('a', 64)] + ($operation === 'lists.create'
                ? ['name' => 'Synthetic visibility list']
                : ['list_id' => '00000000-0000-4000-8000-000000000001', 'expected_version' => str_repeat('b', 64)]);
            $this->assertArrayNotHasKey('is_visible_to_client', TaskOrganizationCommand::fromInput($operation, $input)->data);
            foreach ([false, 0, '0'] as $value) {
                $this->assertSame($value, TaskOrganizationCommand::fromInput($operation, $input + ['is_visible_to_client' => $value])->data['is_visible_to_client']);
            }
            foreach ([null, '', '  '] as $value) {
                try {
                    TaskOrganizationCommand::fromInput($operation, $input + ['is_visible_to_client' => $value]);
                    $this->fail('A raw empty list visibility value was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('is_visible_to_client', $exception->errors());
                }
            }
        }
    }

    public function test_optional_list_name_preserves_omission_but_rejects_raw_cli_empty_whitespace_and_null(): void
    {
        $input = ['list_id' => '00000000-0000-4000-8000-000000000001', 'expected_version' => str_repeat('a', 64), 'expected_graph_version' => str_repeat('b', 64)];
        $this->assertSame($input, TaskOrganizationCommand::fromInput('lists.update', $input)->data);
        foreach (['', '   ', null] as $name) {
            try {
                TaskOrganizationCommand::fromInput('lists.update', $input + ['name' => $name]);
                $this->fail('An empty/blank/null list name was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name', $exception->errors());
            }
        }
    }

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

    public function test_reorder_keeps_every_ordered_identity_and_requires_an_explicit_complete_list(): void
    {
        $ids = ['00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000001'];
        foreach (['tasks.reorder', 'lists.reorder'] as $operation) {
            $base = ['expected_container_version' => str_repeat('a', 64)]
                + ($operation === 'tasks.reorder' ? ['parent_id' => null, 'list_id' => null] : []);
            $command = TaskOrganizationCommand::fromInput($operation, $base + ['ordered_ids' => $ids]);
            $this->assertSame($operation, $command->operation);
            $this->assertSame($base + ['ordered_ids' => $ids], $command->data);
            $this->assertSame($ids, $command->orderedIds());
            $this->assertInvalid($operation, $base, 'ordered_ids');
            $this->assertInvalid($operation, $base + ['ordered_ids' => ['']], 'ordered_ids.0');
            $this->assertInvalid($operation, $base + ['ordered_ids' => [$ids[0], $ids[0]]], 'ordered_ids.0');
            $this->assertInvalid($operation, $base + ['ordered_ids' => ['key' => $ids[0]]], 'ordered_ids');
        }
    }

    public function test_archive_restore_and_list_creation_have_distinct_required_public_fields(): void
    {
        $version = str_repeat('a', 64);
        $base = ['list_id' => '00000000-0000-4000-8000-000000000001', 'expected_version' => $version, 'expected_graph_version' => $version];
        $this->assertInvalid('lists.archive', $base, 'confirm');
        $restore = TaskOrganizationCommand::fromInput('lists.restore', $base);
        $this->assertSame('lists.restore', $restore->operation);
        $this->assertSame($base, $restore->data);
        $this->assertInvalid('lists.create', ['expected_graph_version' => $version], 'name');
        $this->assertInvalid('lists.create', ['expected_graph_version' => $version, 'name' => ''], 'name');
        $created = TaskOrganizationCommand::fromInput('lists.create', ['expected_graph_version' => $version, 'name' => 'Synthetic list']);
        $this->assertSame('Synthetic list', $created->name());
        try {
            $created->orderedIds();
            $this->fail('A list creation exposed a sibling order.');
        } catch (\LogicException $exception) {
            $this->assertSame('This command has no sibling order.', $exception->getMessage());
        }
        $this->expectException(\LogicException::class);
        $restore->name();
    }

    public function test_task_creation_preserves_all_nested_planning_fields_and_validates_their_actual_rules(): void
    {
        $base = ['expected_graph_version' => str_repeat('a', 64), 'parent_id' => null, 'list_id' => null];
        $task = ['title' => 'Synthetic task', 'description' => null, 'is_visible_to_client' => false,
            'starts_on' => '2026-10-22', 'due_on' => null, 'assignee_ids' => ['00000000-0000-4000-8000-000000000001']];
        $this->assertSame($base + ['task' => $task], TaskOrganizationCommand::fromInput('tasks.create', $base + ['task' => $task])->data);
        $this->assertInvalid('tasks.create', $base, 'task');
        foreach (['title' => '', 'starts_on' => '2026-02-30', 'due_on' => 'not-a-date', 'is_visible_to_client' => null, 'assignee_ids' => ['invalid']] as $field => $value) {
            $invalid = $task;
            $invalid[$field] = $value;
            $this->assertInvalid('tasks.create', $base + ['task' => $invalid], $field === 'assignee_ids' ? 'task.assignee_ids.0' : 'task.'.$field);
        }
        $this->assertInvalid('tasks.create', $base + ['task' => $task, 'task.title' => 'forged'], 'organization');
        $this->assertInvalid('lists.reorder', ['expected_container_version' => str_repeat('a', 64), 'ordered_ids' => [], 'ordered_ids.*' => 'forged'], 'organization');
    }

    public function test_unknown_operations_fail_with_the_public_not_found_boundary(): void
    {
        $this->expectException(HttpException::class);
        try {
            TaskOrganizationCommand::fromInput('tasks.unknown', []);
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    private function assertInvalid(string $operation, array $input, string $field): void
    {
        try {
            TaskOrganizationCommand::fromInput($operation, $input);
            $this->fail('Invalid '.$field.' was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
