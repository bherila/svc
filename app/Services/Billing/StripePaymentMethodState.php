<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Typed snapshot of the adapter-owned routing/tombstone row.
 */
final readonly class StripePaymentMethodState
{
    public function __construct(
        public int $id,
        public ?int $workspaceId,
        public ?int $companyId,
        public ?int $customerId,
        public string $state,
        public int $providerCreatedAt,
    ) {}

    public static function fromDatabaseRow(?object $row): self
    {
        if ($row === null) {
            throw new RuntimeException('The Stripe payment-method state could not be locked.');
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;
        if (! is_numeric($values['id'] ?? null)
            || ! is_string($values['state'] ?? null)
            || ! is_numeric($values['provider_created_at'] ?? null)) {
            throw new RuntimeException('The Stripe payment-method state is malformed.');
        }

        return new self(
            id: (int) $values['id'],
            workspaceId: self::nullableInteger($values['workspace_id'] ?? null),
            companyId: self::nullableInteger($values['client_company_id'] ?? null),
            customerId: self::nullableInteger($values['client_stripe_customer_id'] ?? null),
            state: $values['state'],
            providerCreatedAt: (int) $values['provider_created_at'],
        );
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
