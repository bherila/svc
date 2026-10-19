<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The natural key of one finance reconciliation allocation (#457): the
 * received payment, the external finance system's slug and that system's
 * transaction UUID. The path names the allocation, which is what makes
 * writing it idempotent without an idempotency key.
 */
final readonly class ReconciliationTarget
{
    public const int SYSTEM_MAX_LENGTH = 80;

    /** A lowercase slug, as the route constrains it and the column stores it. */
    public const string SYSTEM_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private function __construct(
        public string $paymentPublicId,
        public string $externalSystem,
        public string $externalTransactionUuid,
    ) {}

    /**
     * Field errors keep the names the finance API has always reported:
     * `external_system_slug` and `external_transaction_uuid`.
     *
     * @throws ValidationException
     */
    public static function of(string $paymentPublicId, string $externalSystem, string $externalTransactionUuid): self
    {
        $validated = Validator::make([
            'payment' => $paymentPublicId,
            'external_system_slug' => $externalSystem,
            'external_transaction_uuid' => $externalTransactionUuid,
        ], [
            'payment' => ['required', 'uuid'],
            'external_system_slug' => ['required', 'string', 'max:'.self::SYSTEM_MAX_LENGTH, 'regex:/^'.self::SYSTEM_PATTERN.'$/'],
            'external_transaction_uuid' => ['required', 'uuid'],
        ])->validate();

        return new self(
            (string) $validated['payment'],
            (string) $validated['external_system_slug'],
            strtolower((string) $validated['external_transaction_uuid']),
        );
    }
}
