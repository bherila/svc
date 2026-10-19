<?php

namespace App\Support\Engagement;

/**
 * What one new time entry may say, for every door that logs time.
 *
 * `time_entries.log` (REST, MCP and the browser's time sheet, which all post
 * to the same route) validates each element of its `entries` list with these
 * rules, and `svc:engagement:log-time` validates its arguments with them, so
 * the console refuses exactly what the API refuses. Stated twice they drift:
 * the console once accepted a date outside the sheet's window, which then
 * saved time the only correcting screen will not show.
 */
final class TimeEntryCreationRules
{
    /** The fields an entry may carry; anything else is refused. */
    public const FIELDS = [
        'project_id', 'task_id', 'worked_on', 'minutes', 'description', 'is_billable', 'is_deferred',
        'is_visible_to_client', 'client_visible_description', 'billing_rate_amount', 'currency',
    ];

    /**
     * The per-entry rules, keyed by field.
     *
     * The project is resolved by each door before validation (REST from the
     * payload's `project_id`, the console from its argument), so it is
     * required here only by the door that reads it from the payload.
     *
     * @return array<string, list<string>>
     */
    public static function entry(string $timezone): array
    {
        return [
            'project_id' => ['required', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'worked_on' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.TimeSheetWindow::start($timezone)->toDateString(),
                'before_or_equal:'.TimeSheetWindow::end($timezone)->toDateString(),
            ],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:10000'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_deferred' => ['sometimes', 'boolean'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'client_visible_description' => ['nullable', 'string', 'max:10000'],
            'billing_rate_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /**
     * The same rules for each element of an `entries` list.
     *
     * @return array<string, list<string>>
     */
    public static function entries(string $timezone): array
    {
        $rules = [];
        foreach (self::entry($timezone) as $field => $fieldRules) {
            $rules['entries.*.'.$field] = $fieldRules;
        }

        return $rules;
    }
}
