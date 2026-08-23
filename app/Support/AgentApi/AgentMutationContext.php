<?php

namespace App\Support\AgentApi;

use App\Models\User;

final readonly class AgentMutationContext
{
    public function __construct(
        public User $user,
        public string $oauthClientId,
        public string $idempotencyKey,
    ) {}
}
