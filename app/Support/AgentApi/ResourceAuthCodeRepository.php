<?php

namespace App\Support\AgentApi;

use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

final class ResourceAuthCodeRepository extends AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $code): void
    {
        Passport::authCode()->forceFill(['id' => $code->getIdentifier(), 'user_id' => $code->getUserIdentifier(), 'client_id' => $code->getClient()->getIdentifier(), 'scopes' => json_encode($code->getScopes()), 'revoked' => false, 'resource_uri' => OAuthResourceIndicator::validatedFor(request()), 'expires_at' => $code->getExpiryDateTime()])->save();
    }

    public function isAuthCodeRevoked(string $id): bool
    {
        $code = Passport::authCode()->newQuery()->whereKey($id)->where('revoked', false)->first();
        if ($code === null) {
            return true;
        }
        $storedValue = $code->getAttribute('resource_uri');
        $stored = is_string($storedValue) ? $storedValue : null;
        $requested = request()->exists('resource') ? OAuthResourceIndicator::canonicalize(request()->input('resource')) : $stored;
        if ($requested !== $stored) {
            $code->forceFill(['revoked' => true])->save();

            return true;
        }
        if ($stored !== null) {
            request()->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $stored);
        }

        return false;
    }
}
