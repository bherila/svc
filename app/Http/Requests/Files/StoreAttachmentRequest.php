<?php

namespace App\Http\Requests\Files;

use App\Services\Files\AttachmentRecordResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'recordType' => ['required', 'string', Rule::in(AttachmentRecordResolver::allowedTypes())],
            'recordPublicId' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:51200'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return array_merge($this->all(), [
            'recordType' => $this->route('recordType'),
            'recordPublicId' => $this->route('recordPublicId'),
        ]);
    }
}
