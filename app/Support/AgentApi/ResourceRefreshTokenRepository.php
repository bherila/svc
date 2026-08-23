<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

final class ResourceRefreshTokenRepository extends RefreshTokenRepository
{
    public function __construct(Dispatcher $events)
    {
        parent::__construct($events);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $refresh = Passport::refreshToken()->newQuery()->whereKey($tokenId)->where('revoked', false)->first();
        if ($refresh === null) {
            return true;
        }
        $access = Passport::token()->newQuery()->whereKey($refresh->access_token_id)->where('revoked', false)->first();
        if ($access === null) {
            $refresh->revoke();

            return true;
        }
        $storedValue = $access->getAttribute('resource_uri');
        $stored = is_string($storedValue) ? $storedValue : null;
        $requested = request()->exists('resource') ? OAuthResourceIndicator::canonicalize(request()->input('resource')) : $stored;
        if ($requested !== $stored) {
            $refresh->revoke();
            $access->revoke();

            return true;
        }
        if ($stored !== null) {
            request()->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $stored);
        }

        return false;
    }
}
