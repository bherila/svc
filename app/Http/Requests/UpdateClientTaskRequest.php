<?php

namespace App\Http\Requests;

use App\Services\Tasks\TaskInputRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['expected_version' => ['required', 'string', 'size:64']] + TaskInputRules::patch();
    }
}
