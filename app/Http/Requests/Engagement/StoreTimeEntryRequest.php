<?php

namespace App\Http\Requests\Engagement;

use App\Models\Workspace;
use App\Support\Engagement\TimeSheetWindow;
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
            'worked_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.TimeSheetWindow::start($this->workspaceTimezone())->toDateString(), 'before_or_equal:'.TimeSheetWindow::end($this->workspaceTimezone())->toDateString()],
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

    private function workspaceTimezone(): string
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace ? $workspace->timezone : 'UTC';
    }
}
