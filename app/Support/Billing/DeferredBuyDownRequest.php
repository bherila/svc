<?php

declare(strict_types=1);

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
        /** @var array{hours?: numeric-string|int|float, amount?: int|numeric-string, round_up?: bool|0|1|'0'|'1', discount_amount?: int|numeric-string, discount_reason?: string|null} $data */
        $data = Validator::make($input, [
            'hours' => ['numeric', 'gt:0', 'max:1000000', 'required_without:amount', 'prohibits:amount'],
            'amount' => ['integer', 'min:1', 'max:2147483647', 'required_without:hours', 'prohibits:hours'],
            'round_up' => ['boolean'],
            'discount_amount' => ['integer', 'min:0', 'max:2147483647'],
            'discount_reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $rawMinutes = isset($data['hours']) ? $data['hours'] * 60 : null;
        $minutes = $rawMinutes !== null ? (int) round($rawMinutes) : null;
        if ($minutes !== null && ($minutes < 1 || self::exceedsMinuteTolerance(abs($minutes - $rawMinutes)))) {
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

    /** The difference is between a positive integer minute and its binary float input. */
    private static function exceedsMinuteTolerance(float $difference): bool
    {
        return $difference > 0.00001;
    }
}
