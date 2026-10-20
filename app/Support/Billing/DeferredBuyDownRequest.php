<?php

namespace App\Support\Billing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class DeferredBuyDownRequest
{
    private function __construct(
        public ?int $minutes,
        public ?int $amount,
        public bool $roundUp,
        public int $discount,
        public ?string $discountReason,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $data = Validator::make($input, [
            'hours' => ['numeric', 'gt:0', 'max:1000000', 'required_without:amount', 'prohibits:amount'],
            'amount' => ['integer', 'min:1', 'max:2147483647', 'required_without:hours', 'prohibits:hours'],
            'round_up' => ['sometimes', 'boolean'],
            'discount_amount' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'discount_reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $minutes = isset($data['hours']) ? (int) round((float) $data['hours'] * 60) : null;
        if ($minutes !== null && ($minutes < 1 || abs($minutes - (float) $data['hours'] * 60) > 0.00001)) {
            throw ValidationException::withMessages(['hours' => 'Use a whole number of minutes.']);
        }
        $discount = (int) ($data['discount_amount'] ?? 0);
        $reason = isset($data['discount_reason']) ? trim($data['discount_reason']) : null;
        if ($discount > 0 && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['discount_reason' => 'Explain the discount.']);
        }

        return new self($minutes, isset($data['amount']) ? (int) $data['amount'] : null,
            (bool) ($data['round_up'] ?? false), $discount, $reason);
    }
}
