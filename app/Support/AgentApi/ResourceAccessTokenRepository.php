<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

final class ResourceAccessTokenRepository extends AccessTokenRepository
{
    public function __construct(Dispatcher $events)
    {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $token): void
    {
        Passport::token()->forceFill(['id' => $token->getIdentifier(), 'user_id' => $token->getUserIdentifier(), 'client_id' => $clientId = $token->getClient()->getIdentifier(), 'scopes' => $token->getScopes(), 'revoked' => false, 'resource_uri' => OAuthResourceIndicator::validatedFor(request()), 'expires_at' => $token->getExpiryDateTime()])->save();
        $this->events->dispatch(new AccessTokenCreated($token->getIdentifier(), $token->getUserIdentifier(), $clientId));
    }
}
