<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Proof of the exact payment revision and review an owner intends to acknowledge. */
final readonly class ReconciliationReviewAcknowledgement
{
    private function __construct(
        public string $expectedPaymentVersion,
        public ReconciliationReviewReason $expectedReviewReason,
        public CarbonImmutable $expectedReviewFlaggedAt,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input): self
    {
        $rules = [
            'expected_payment_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_review_reason' => ['required', Rule::in(array_column(ReconciliationReviewReason::cases(), 'value'))],
            'expected_review_flagged_at' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ];
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Acknowledgement accepts only the current payment version and review proof.'));
        }
        $validated = Validator::make($input, $rules)->validate();

        return new self(
            $validated['expected_payment_version'],
            ReconciliationReviewReason::from($validated['expected_review_reason']),
            CarbonImmutable::parse($validated['expected_review_flagged_at'])->utc(),
        );
    }
}
