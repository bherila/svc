<?php

namespace App\Http\Requests;

use App\Services\Tasks\TaskInputRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['is_visible_to_client' => ['required', 'boolean']] + TaskInputRules::create();
    }
}
