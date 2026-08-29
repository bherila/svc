<?php

namespace App\Http\Requests\Engagement;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'worked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->lastDateTheSheetShows()],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['required', 'string', 'max:10000'],
            'task_id' => ['nullable', 'string'],
            'is_billable' => ['sometimes', 'boolean'],
            'is_deferred' => ['sometimes', 'boolean'],
            'is_visible_to_client' => ['sometimes', 'boolean'],
            'client_visible_description' => ['nullable', 'string', 'max:10000'],
            'billing_rate_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
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
