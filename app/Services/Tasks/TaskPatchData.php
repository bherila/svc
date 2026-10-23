<?php

namespace App\Services\Tasks;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A partial update: absent keys and explicit null remain distinguishable. */
final readonly class TaskPatchData
{
    /** @param array<string, mixed> $changes
     * @param  list<string>|null  $assigneeIds  null means preserve existing assignments
     */
    private function __construct(private array $changes, public ?array $assigneeIds) {}

    /** @param array<string, mixed> $attributes */
    public static function fromValidated(array $attributes): self
    {
        TaskInputRules::assertAssigneeArray($attributes);
        /** @var array<string, mixed>&array{assignee_ids?: list<string>} $data */
        $data = Validator::make($attributes, TaskInputRules::patch())->validate();
        $assignees = $data['assignee_ids'] ?? null;
        unset($data['assignee_ids']);

        return new self($data, $assignees);
    }

    /** @return array<string, mixed> */
    public function attributes(?string $startsOn, ?string $dueOn): array
    {
        /** @var array{starts_on?: ?string, due_on?: ?string} $dates */
        $dates = $this->changes;
        self::assertDateOrder(array_key_exists('starts_on', $dates) ? $dates['starts_on'] : $startsOn,
            array_key_exists('due_on', $dates) ? $dates['due_on'] : $dueOn);

        return $this->changes;
    }

    public static function assertDateOrder(?string $startsOn, ?string $dueOn): void
    {
        if ($startsOn !== null && $dueOn !== null && $dueOn < $startsOn) {
            throw ValidationException::withMessages(['due_on' => 'The due date must be on or after the start date.']);
        }
    }
}
