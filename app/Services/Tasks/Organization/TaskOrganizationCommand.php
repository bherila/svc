<?php

namespace App\Services\Tasks\Organization;

use App\Services\Tasks\TaskInputRules;
use App\Support\AgentApi\ExplicitConfirmation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Presence, public identities and optimistic revisions validated once for every adapter. */
final readonly class TaskOrganizationCommand
{
    /** @param array<string, mixed> $data */
    private function __construct(public string $operation, public array $data) {}

    public function name(): string
    {
        $name = $this->data['name'] ?? null;
        if (! is_string($name)) {
            throw new \LogicException('This command has no list name.');
        }

        return $name;
    }

    /** @return list<string> */
    public function orderedIds(): array
    {
        $ids = $this->data['ordered_ids'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new \LogicException('This command has no sibling order.');
        }
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new \LogicException('This command has an invalid identity.');
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(string $operation, array $input): self
    {
        $version = ['required', 'string', 'size:64'];
        $identity = ['required', 'uuid'];
        $container = ['parent_id' => ['present', 'nullable', 'uuid'], 'list_id' => ['present', 'nullable', 'uuid']];
        $order = ['ordered_ids' => ['present', 'array', 'list', 'max:'.TaskGraph::MAX_SIBLINGS], 'ordered_ids.*' => ['required', 'uuid', 'distinct']];
        $rules = match ($operation) {
            'tasks.create' => ['expected_graph_version' => $version] + $container
                + ['task' => ['required', 'array:title,description,is_visible_to_client,starts_on,due_on,assignee_ids']]
                + array_combine(array_map(static fn (string $key): string => 'task.'.$key, array_keys(TaskInputRules::create())), array_values(TaskInputRules::create())),
            'lists.create' => ['expected_graph_version' => $version, 'name' => ['required', 'string', 'max:255'], 'is_visible_to_client' => ['sometimes', 'filled', 'boolean']],
            'lists.update' => ['list_id' => $identity, 'expected_version' => $version, 'expected_graph_version' => $version,
                'name' => ['sometimes', 'filled', 'string', 'min:1', 'max:255'], 'is_visible_to_client' => ['sometimes', 'filled', 'boolean']],
            'lists.archive' => ['list_id' => $identity, 'expected_version' => $version, 'expected_graph_version' => $version, 'confirm' => ['required', new ExplicitConfirmation]],
            'lists.restore' => ['list_id' => $identity, 'expected_version' => $version, 'expected_graph_version' => $version],
            'tasks.move' => ['task_id' => $identity, 'expected_version' => $version, 'expected_graph_version' => $version] + $container,
            'tasks.reorder' => ['expected_container_version' => $version] + $container + $order,
            'lists.reorder' => ['expected_container_version' => $version] + $order,
            default => abort(404),
        };
        $unknown = array_diff(array_keys($input), array_filter(array_keys($rules), static fn (string $key): bool => ! str_contains($key, '.')));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['organization' => 'Unsupported organization field.']);
        }

        return new self($operation, Validator::make($input, $rules)->validate());
    }
}
