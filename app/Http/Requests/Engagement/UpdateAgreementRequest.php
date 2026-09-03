<?php

namespace App\Http\Requests\Engagement;

use App\Models\ClientAgreement;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting an agreement that was recorded wrong.
 *
 * Every field is `sometimes`, and that is the whole design: the rename control
 * sends a title and nothing else, and a request that omits a term must leave
 * that term alone rather than blanking it. A `nullable` field that is *present*
 * and null is a deliberate erasure - "this agreement states no hourly rate" -
 * and the workflow writes it as such. Absent and null are different answers
 * here, and the distinction is what keeps a two-field form from quietly
 * clearing the nine terms it never showed.
 *
 * Terms are taken in the units the columns hold - minor currency units and
 * whole minutes - rather than in hours and dollars. The conversion happens once,
 * in the form that collects them, so nothing here has to decide what `1.75`
 * meant.
 *
 * What is deliberately not editable: `status`, `activated_at`, `signed_at` and
 * the signature fields. Those are lifecycle, they have their own endpoints that
 * record activity and enforce the order of events, and an agreement that can be
 * marked signed by editing a form is an agreement nobody signed.
 */
class UpdateAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            // Required when present, for the same reason the create request
            // requires it (#147): `client_agreements.starts_on` is NOT NULL
            // because a null had seven incompatible readings.
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            // The ordering of the two dates is checked in `withValidator`
            // rather than with `after_or_equal:starts_on`, because a request
            // that changes only the end date carries no start for the rule to
            // read - and a rule with nothing to compare against passes
            // anything, including an end date before the start.
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'billing_cadence' => ['sometimes', 'required', Rule::in(['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual'])],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'agreement_text' => ['sometimes', 'nullable', 'string', 'max:30000'],
            'is_visible_to_client' => ['sometimes', 'boolean'],

            'hourly_rate_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'retainer_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'retainer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'period_retainer_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'period_retainer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'catch_up_threshold_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rollover_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
            // No vocabulary: nothing in the billing engine reads this column,
            // and the importer records that the source expressed rollover
            // through `rollover_months` instead. Free text, so a wrong value
            // can be corrected rather than becoming permanent because no list
            // admits it.
            'rollover_policy' => ['sometimes', 'nullable', 'string', 'max:40'],
            'first_cycle_proration' => ['sometimes', 'nullable', Rule::in(['prorate_hours', 'full_period', 'align_next_cycle'])],
            'bill_overage_interim' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * The two dates are only ever meaningful as a pair.
     *
     * Whichever of them this request carries is compared against the value the
     * row will actually hold afterwards, so changing one at a time cannot
     * produce an agreement that ends before it starts.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $agreement = $this->route('clientAgreement');
            $stored = $agreement instanceof ClientAgreement ? $agreement : null;

            $startsOn = $this->has('starts_on')
                ? $this->input('starts_on')
                : $stored?->starts_on?->toDateString();

            $endsOn = $this->has('ends_on')
                ? $this->input('ends_on')
                : $stored?->ends_on?->toDateString();

            if (! is_string($startsOn) || ! is_string($endsOn)) {
                return;
            }

            if ($endsOn < $startsOn) {
                $validator->errors()->add('ends_on', 'The agreement cannot end before it starts.');
            }
        });
    }
}
