<?php

namespace App\Services\Payments;

use LogicException;
use SensitiveParameter;

/** A webhook endpoint the processor created, with the secret that signs its deliveries. */
final readonly class RegisteredWebhook
{
    public function __construct(public string $endpointId, #[SensitiveParameter] private string $secret) {}

    public function secret(): string
    {
        return $this->secret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['endpointId' => $this->endpointId, 'secret' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('A webhook secret is never serialized.');
    }
}
