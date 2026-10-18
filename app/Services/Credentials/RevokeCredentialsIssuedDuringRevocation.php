<?php

namespace App\Services\Credentials;

use App\Models\AgentPrincipal;
use Laravel\Passport\Client;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;

/**
 * Closes the race a revocation cannot close with row locks alone (see
 * {@see AgentCredentialFence}): runs synchronously as Passport persists each
 * credential, so the token response a racing grant returns is already dead.
 */
final readonly class RevokeCredentialsIssuedDuringRevocation
{
    public const string ACTOR = 'revocation-fence';

    public function __construct(
        private AgentCredentialFence $fence,
        private CredentialRevoker $revoker,
    ) {}

    public function accessTokenCreated(AccessTokenCreated $event): void
    {
        $userId = filter_var($event->userId, FILTER_VALIDATE_INT);
        if ($userId === false || ! $this->fence->covers($userId, $event->clientId)) {
            return;
        }
        $client = Passport::client()->newQuery()->find($event->clientId);
        if (! $client instanceof Client || $client->hasGrantType('personal_access')) {
            return;
        }
        $owner = AgentPrincipal::query()->find($userId);
        if ($owner instanceof AgentPrincipal) {
            $this->revoker->revoke(AgentCredentialSelection::token($owner, $event->tokenId), self::ACTOR);
        }
    }

    /** A new refresh token for an access token that is already revoked is never legitimate. */
    public function refreshTokenCreated(RefreshTokenCreated $event): void
    {
        if ((bool) Passport::token()->newQuery()->whereKey($event->accessTokenId)->value('revoked')) {
            Passport::refreshToken()->newQuery()->whereKey($event->refreshTokenId)->update(['revoked' => true]);
        }
    }
}
