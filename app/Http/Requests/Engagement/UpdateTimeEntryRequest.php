<?php

namespace App\Http\Requests\Engagement;

use App\Models\Workspace;
use App\Support\Engagement\TimeSheetWindow;
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
            'worked_on' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.TimeSheetWindow::start($this->workspaceTimezone())->toDateString(), 'before_or_equal:'.TimeSheetWindow::end($this->workspaceTimezone())->toDateString()],
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

    private function workspaceTimezone(): string
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace ? $workspace->timezone : 'UTC';
    }
}
