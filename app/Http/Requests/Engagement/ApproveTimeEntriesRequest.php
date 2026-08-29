<?php

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTimeEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * How many entries one approval may carry.
     *
     * Named rather than inlined because the screen has to know it: a
     * selection the page allows and the request refuses is rejected whole,
     * and the operator is given no way to make it smaller.
     */
    public const MAX_ENTRIES = 200;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*.id' => ['required', 'string'],
            'entries.*.expected_version' => ['required', 'string'],
            // Shape only. Whether a rate override is *complete* - both an
            // amount and a currency, never one - is the mutation service's
            // call, because a wildcard `required_with` cannot see across two
            // keys of the same array element and would pass a half-override
            // through to be arbitrated anyway.
            'entries.*.billing_rate_amount' => ['sometimes', 'integer', 'min:0'],
            'entries.*.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
