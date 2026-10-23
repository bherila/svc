<?php

namespace App\Support\Finance;

use App\Services\Finance\PaymentReconciliationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What one finance reconciliation allocation says (#457): how much of the
 * payment the external transaction covers, in integer minor units of the
 * payment's currency, the day it was reconciled, and whether it counts.
 *
 * The body the finance API has always taken: `allocated_amount` and
 * `currency` are required; `reconciled_on` (`Y-m-d`) is optional, with the
 * integer and boolean spellings Laravel's validator has always accepted
 * here. Other fields are ignored.
 *
 * `is_active` is required through OAuth and MCP, so a finance system always
 * sends the state it wants now. Through the finance token it stays optional,
 * and omitting it keeps an existing allocation's state (a new one is
 * active): a delayed retry never reactivates an allocation withdrawn since.
 */
final readonly class ReconciliationAllocation
{
    private function __construct(
        public int $allocatedAmount,
        public string $currency,
        public ?string $reconciledOn,
        /** Null when the request did not say: an existing allocation keeps its state. */
        public ?bool $isActive,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input, bool $statesActivity = false): self
    {
        $validated = Validator::make($input, [
            'allocated_amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'reconciled_on' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => [$statesActivity ? 'required' : 'sometimes', 'boolean'],
        ])->validate();

        return new self(
            (int) $validated['allocated_amount'],
            (string) $validated['currency'],
            isset($validated['reconciled_on']) ? (string) $validated['reconciled_on'] : null,
            // The boolean rule admits exactly true, false, 1, 0, '1' and '0'.
            array_key_exists('is_active', $validated) ? in_array($validated['is_active'], [true, 1, '1'], true) : null,
        );
    }

    /**
     * The attributes {@see PaymentReconciliationService::upsert()} takes.
     *
     * @return array{external_system_slug: string, external_transaction_uuid: string, allocated_amount: int, currency: string, reconciled_on: ?string, is_active: ?bool}
     */
    public function attributesFor(ReconciliationTarget $target): array
    {
        return [
            'external_system_slug' => $target->externalSystem,
            'external_transaction_uuid' => $target->externalTransactionUuid,
            'allocated_amount' => $this->allocatedAmount,
            'currency' => $this->currency,
            'reconciled_on' => $this->reconciledOn,
            'is_active' => $this->isActive,
        ];
    }
}
