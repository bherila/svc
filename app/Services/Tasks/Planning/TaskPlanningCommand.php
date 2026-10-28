<?php

namespace App\Services\Tasks\Planning;

use App\Support\AgentApi\ExplicitConfirmation;
use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One presence/shape boundary for native, Agent and trusted CLI adapters. */
final readonly class TaskPlanningCommand
{
    /** @param array<string,mixed> $data */
    private function __construct(public string $operation, public array $data, public ?TaskLabelName $name) {}

    /** @param array<string,mixed> $input */
    public static function fromInput(string $operation, array $input): self
    {
        $version = ['required', 'string', 'size:64'];
        $field = match ($operation) {
            'labels.replace' => 'label_ids',
            'dependencies.replace' => 'prerequisite_ids',
            'contacts.replace' => 'contact_ids',
            'labels.create', 'labels.update', 'labels.archive', 'labels.restore' => null,
            default => abort(404),
        };
        // TaskLabelName below owns name presence, type, Unicode normalization
        // and length. The empty rule set keeps the closed field in validated data.
        $rules = match ($operation) {
            'labels.create' => ['name' => []],
            'labels.update' => ['name' => [], 'expected_version' => $version],
            'labels.archive' => ['expected_version' => $version, 'confirm' => ['required', new ExplicitConfirmation]],
            'labels.restore' => ['expected_version' => $version],
            default => ['expected_version' => $version],
        };
        if ($field !== null) {
            if (! array_key_exists($field, $input) || ! is_array($input[$field])) {
                throw ValidationException::withMessages([$field => 'Supply a list of identities; use an empty list to clear it.']);
            }
            $limit = match ($field) {
                'label_ids' => 20,
                'prerequisite_ids' => TaskDependencyGraph::MAX_PREREQUISITES,
                'contact_ids' => TaskContactResponsibilityFacts::MAX_CONTACTS,
            };
            $rules += [$field => ['list', 'max:'.$limit], $field.'.*' => ['required', 'string', 'uuid', 'distinct']];
        }
        if ($operation === 'dependencies.replace') {
            $rules['expected_graph_version'] = $version;
        }
        if (array_diff(array_keys($input), array_filter(array_keys($rules), static fn (string $key): bool => ! str_contains($key, '.'))) !== []) {
            throw ValidationException::withMessages(['planning' => 'Unsupported task planning field.']);
        }
        $name = null;
        if (in_array($operation, ['labels.create', 'labels.update'], true)) {
            try {
                $name = TaskLabelName::fromInput($input['name'] ?? null);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['name' => $exception->getMessage()]);
            }
            $input['name'] = $name->name;
        }

        return new self($operation, Validator::make($input, $rules)->validate(), $name);
    }

    /** @return list<string> */
    public function ids(): array
    {
        $field = match ($this->operation) {
            'labels.replace' => 'label_ids', 'dependencies.replace' => 'prerequisite_ids', 'contacts.replace' => 'contact_ids',
            default => throw new \LogicException('This command has no association set.'),
        };

        // Construction is private: fromInput already checked the explicit array,
        // list shape and every UUID. The readonly data cannot be edited afterward.
        /** @var list<string> $ids */
        $ids = $this->data[$field];

        return $ids;
    }

    public function version(string $field = 'expected_version'): string
    {
        $version = $this->data[$field] ?? null;
        if (! is_string($version)) {
            throw new \LogicException('This command has no expected revision.');
        }

        return $version;
    }
}
