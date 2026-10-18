<?php

namespace App\Services\Credentials;

/** What one revocation ended. Carries fingerprints, never a credential id. */
final readonly class AgentRevocation
{
    /** @param  list<string>  $fingerprints  one per access token revoked */
    public function __construct(
        public array $fingerprints,
        public int $refreshTokens,
        public int $authorizationCodes,
    ) {}

    public function accessTokens(): int
    {
        return count($this->fingerprints);
    }

    /** @return array{access_tokens: int, refresh_tokens: int, authorization_codes: int} */
    public function counts(): array
    {
        return [
            'access_tokens' => $this->accessTokens(),
            'refresh_tokens' => $this->refreshTokens,
            'authorization_codes' => $this->authorizationCodes,
        ];
    }
}
