<?php

namespace App\Http\Requests\Collaboration;

use App\Services\Collaboration\TopicKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollaborationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['kind' => ['nullable', Rule::enum(TopicKind::class)], 'project_id' => ['nullable', 'uuid'], 'comment' => ['nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'comments_page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
    }
}
