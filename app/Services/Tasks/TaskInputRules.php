<?php

namespace App\Services\Tasks;

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
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'status' => ['sometimes', 'string', 'in:open,in_progress,completed'],
        ] + self::optional();
    }

    /** @return array<string, list<string>> */
    private static function optional(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assignee_ids' => ['sometimes', 'array', 'list', 'max:'.self::MAX_ASSIGNEES],
            'assignee_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }
}
