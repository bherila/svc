<?php

namespace App\Services\Credentials;

use Carbon\CarbonImmutable;

/**
 * A freshly issued agent token. The bearer value exists only here, for the
 * caller to hand over once; it is never logged, and a dump of this object
 * redacts it.
 */
final readonly class IssuedAgentToken
{
    /** @param  non-empty-list<string>  $scopes */
    public function __construct(
        public string $id,
        #[\SensitiveParameter] public string $token,
        public CarbonImmutable $expiresAt,
        public string $label,
        public array $scopes,
        public string $resource,
    ) {}

    /** What an audit entry names the credential by: never its id or value. */
    public function fingerprint(): string
    {
        return CredentialFingerprint::of($this->id);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'token' => '[redacted]',
            'expiresAt' => $this->expiresAt->toIso8601String(),
            'label' => $this->label,
            'scopes' => $this->scopes,
            'resource' => $this->resource,
        ];
    }
}
