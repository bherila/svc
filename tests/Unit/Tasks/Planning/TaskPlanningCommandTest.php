<?php

namespace Tests\Unit\Tasks\Planning;

use App\Services\Tasks\Planning\TaskContactResponsibilityFacts;
use App\Services\Tasks\Planning\TaskDependencyGraph;
use App\Services\Tasks\Planning\TaskLabelName;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Support\AgentApi\ExplicitConfirmation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TaskPlanningCommand::class)]
#[CoversClass(TaskLabelName::class)]
#[CoversClass(TaskDependencyGraph::class)]
#[CoversClass(TaskContactResponsibilityFacts::class)]
#[CoversClass(ExplicitConfirmation::class)]
final class TaskPlanningCommandTest extends TestCase
{
    public function test_empty_lists_clear_and_versions_are_kept_opaque_without_origin_fields(): void
    {
        foreach (['labels.replace' => 'label_ids', 'contacts.replace' => 'contact_ids', 'dependencies.replace' => 'prerequisite_ids'] as $operation => $field) {
            $input = [$field => [], 'expected_version' => str_repeat('t', 64)];
            if ($operation === 'dependencies.replace') {
                $input['expected_graph_version'] = str_repeat('g', 64);
            }
            $command = TaskPlanningCommand::fromInput($operation, $input);
            $this->assertSame($operation, $command->operation);
            $this->assertSame([], $command->ids());
            $this->assertSame(str_repeat('t', 64), $command->version());
            $actual = $command->data;
            ksort($input);
            ksort($actual);
            $this->assertSame($input, $actual);
            if ($operation === 'dependencies.replace') {
                $this->assertSame(str_repeat('g', 64), $command->version('expected_graph_version'));
            }
        }
        $label = TaskPlanningCommand::fromInput('labels.create', ['name' => "\u{00a0}Équipe\u{3000}"]);
        $this->assertSame('Équipe', $label->data['name']);
        $this->assertSame(TaskLabelName::fromInput('équipe')->key, $label->name->key);
    }

    public static function invalidInputs(): iterable
    {
        $version = str_repeat('v', 64);
        $id = '00000000-0000-4000-8000-000000000001';
        foreach (['labels.replace' => 'label_ids', 'contacts.replace' => 'contact_ids', 'dependencies.replace' => 'prerequisite_ids'] as $operation => $field) {
            $base = ['expected_version' => $version] + ($operation === 'dependencies.replace' ? ['expected_graph_version' => $version] : []);
            $limit = match ($field) {
                'label_ids' => 20, 'contact_ids' => 10, 'prerequisite_ids' => 25
            };
            yield $operation.' too many' => [$operation, $base + [$field => array_map(static fn (int $id): string => sprintf('00000000-0000-4000-8000-%012d', $id), range(1, $limit + 1))]];
            foreach (['missing' => null, 'blank' => '', 'null' => null, 'object' => ['id' => $id], 'duplicate' => [$id, $id], 'non-UUID' => ['foreign-id'], 'number' => [1]] as $kind => $value) {
                yield $operation.' '.$kind => [$operation, $kind === 'missing' ? $base : $base + [$field => $value]];
            }
            foreach (['absent', '', null, 1, str_repeat('v', 63), str_repeat('v', 65)] as $value) {
                $input = $base + [$field => []];
                if ($value === 'absent') {
                    unset($input['expected_version']);
                } else {
                    $input['expected_version'] = $value;
                }
                yield $operation.' version '.json_encode($value) => [$operation, $input];
            }
            foreach (['origin', 'workspace_id', 'client_project_id', 'status', 'completed_at', 'is_visible_to_client', 'user_id'] as $fieldName) {
                yield $operation.' immutable '.$fieldName => [$operation, $base + [$field => [], $fieldName => 'caller-value']];
            }
        }
        yield 'graph version absent' => ['dependencies.replace', ['expected_version' => $version, 'prerequisite_ids' => []]];
        yield 'graph version stale shape' => ['dependencies.replace', ['expected_version' => $version, 'expected_graph_version' => str_repeat('g', 63), 'prerequisite_ids' => []]];
        foreach (['', "\u{2003}", "one\u{2028}two", null] as $name) {
            yield 'invalid label '.json_encode($name) => ['labels.create', ['name' => $name]];
        }
        foreach ([false, 1, 'true', null] as $confirmation) {
            yield 'archive confirmation '.json_encode($confirmation) => ['labels.archive', ['expected_version' => $version, 'confirm' => $confirmation]];
        }
    }

    public function test_raw_adapter_shapes_cannot_bypass_shared_validation(): void
    {
        foreach (self::invalidInputs() as $case => [$operation, $input]) {
            try {
                TaskPlanningCommand::fromInput($operation, $input);
                $this->fail('Invalid input accepted: '.$case);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), $case);
            }
        }
    }
}
