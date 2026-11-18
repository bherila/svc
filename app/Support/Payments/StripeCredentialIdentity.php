<?php

namespace App\Support\Payments;

use SensitiveParameter;

/** Nonsecret identity of the effective key used for a request, never its account label or timestamp. */
final readonly class StripeCredentialIdentity
{
    public function __construct(
        public int $workspaceId,
        public ?int $connectionId,
        public ?int $sourceConnectionId,
        public ?string $environmentVersion = null,
    ) {}

    /** Null denotes the legacy environment, which has no stored connection row. */
    public function connectionVersion(): ?string
    {
        return $this->connectionId === null ? null : 'connection:'.$this->connectionId.':source:'.$this->sourceConnectionId;
    }

    public static function environment(int $workspaceId, #[SensitiveParameter] string $secret): self
    {
        return new self($workspaceId, null, null, hash_hmac('sha256', $secret, (string) config('app.key')));
    }
}
