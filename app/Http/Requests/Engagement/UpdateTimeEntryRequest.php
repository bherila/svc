<?php

namespace App\Http\Requests\Engagement;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string'],
            'worked_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$this->lastDateTheSheetShows()],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_deferred' => ['sometimes', 'boolean'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'client_visible_description' => ['nullable', 'string', 'max:10000'],
            // Nullable rather than absent-or-string: clearing an attribution
            // is a change the operator has to be able to make, and an omitted
            // key cannot say "none" while `null` can.
            'task_id' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * The last date the sheet will show, on the workspace's own clock.
     *
     * A mistyped year was accepted, written, and then dropped by the window
     * the sheet displays - the dialog closed as though it had saved, and the
     * only screen offering correction or deletion no longer listed the row.
     * Refusing the date keeps the operator's mistake in front of them.
     */
    protected function lastDateTheSheetShows(): string
    {
        $workspace = $this->route('workspace');
        $timezone = $workspace instanceof Workspace ? $workspace->timezone : 'UTC';

        return CarbonImmutable::now($timezone)->endOfMonth()->toDateString();
    }
}
