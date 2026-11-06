<?php

namespace App\Services\Payments;

use LogicException;
use SensitiveParameter;

/** A credential in transit: never printed, never serialized. */
final readonly class PaymentCredentials
{
    public function __construct(
        #[SensitiveParameter] private string $secret,
        #[SensitiveParameter] private ?string $webhookSecret = null,
        public ?string $locationId = null,
        public ?string $environment = null,
        /** Stripe: the publishable key, which is not a secret. */
        public ?string $publishableKey = null,
    ) {}

    public function secret(): string
    {
        return $this->secret;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]', 'webhookSecret' => '[redacted]', 'locationId' => $this->locationId, 'environment' => $this->environment];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Payment credentials are never serialized.');
    }
}
