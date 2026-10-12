<?php

namespace App\Support\AgentApi\BearerRejection;

/** The identifiers of a bearer whose signature this server's key verifies. */
final readonly class VerifiedBearerClaims
{
    public function __construct(
        public string $tokenId,
        public string $clientId,
    ) {}
}
