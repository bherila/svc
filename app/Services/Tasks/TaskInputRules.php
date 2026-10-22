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
            'title' => ['string', 'min:1', 'max:255'],
            'status' => ['string', 'in:open,in_progress,completed'],
        ] + self::optional();
    }

    /** @return array<string, list<string>> */
    private static function optional(): array
    {
        return [
            // Non-implicit validation rules skip absent keys. validated()
            // therefore keeps omission distinct from explicit nullable clears.
            'description' => ['nullable', 'string', 'max:10000'],
            'is_visible_to_client' => ['boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'assignee_ids' => ['array', 'list', 'max:'.self::MAX_ASSIGNEES],
            'assignee_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }
}
