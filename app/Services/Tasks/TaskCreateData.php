<?php

namespace App\Services\Tasks;

use Illuminate\Support\Facades\Validator;

final readonly class TaskCreateData
{
    /** @param list<string> $assigneeIds */
    private function __construct(
        public string $title,
        public ?string $description,
        public bool $visibleToClient,
        public ?string $startsOn,
        public ?string $dueOn,
        public array $assigneeIds,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(array $attributes): self
    {
        TaskInputRules::assertAssigneeArray($attributes);
        /** @var array{title: string, description?: ?string, is_visible_to_client?: bool, starts_on?: ?string, due_on?: ?string, assignee_ids?: list<string>} $data */
        $data = Validator::make($attributes, TaskInputRules::create())->validate();
        TaskPatchData::assertDateOrder($data['starts_on'] ?? null, $data['due_on'] ?? null);

        return new self($data['title'], $data['description'] ?? null, $data['is_visible_to_client'] ?? false,
            $data['starts_on'] ?? null, $data['due_on'] ?? null, $data['assignee_ids'] ?? []);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['title' => $this->title, 'description' => $this->description, 'is_visible_to_client' => $this->visibleToClient,
            'starts_on' => $this->startsOn, 'due_on' => $this->dueOn, 'status' => 'open'];
    }
}
