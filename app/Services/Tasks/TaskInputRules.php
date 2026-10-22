<?php

namespace App\Services\Tasks;

use Illuminate\Validation\ValidationException;

final class TaskInputRules
{
    public const MAX_ASSIGNEES = 10;

    /** @return array<string, list<string>> */
    public static function create(): array
    {
        return ['title' => ['required', 'string', 'max:255']] + self::optional();
    }

    /** @return array<string, list<string>> */
    public static function patch(): array
    {
        return [
            'title' => ['filled', 'string', 'max:255'],
            'status' => ['filled', 'string', 'in:open,in_progress,completed'],
        ] + self::optional();
    }

    /** @return array<string, list<string>> */
    private static function optional(): array
    {
        return [
            // Non-implicit validation rules skip absent keys. validated()
            // therefore keeps omission distinct from explicit nullable clears.
            'description' => ['nullable', 'string', 'max:10000'],
            'is_visible_to_client' => ['filled', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'assignee_ids' => ['list', 'max:'.self::MAX_ASSIGNEES],
            'assignee_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /** Laravel skips non-implicit array/list rules on empty strings.
     * @param  array<string, mixed>  $attributes
     */
    public static function assertAssigneeArray(array $attributes): void
    {
        if (array_key_exists('assignee_ids', $attributes) && ! is_array($attributes['assignee_ids'])) {
            throw ValidationException::withMessages(['assignee_ids' => 'Assignees must be a list of account IDs; use an empty list to clear them.']);
        }
    }
}
