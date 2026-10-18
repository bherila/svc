<?php

namespace App\Services\Credentials;

use App\Models\AgentPrincipal;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use Psr\Log\LoggerInterface;

/**
 * Closes the race a revocation cannot close with row locks alone (see
 * {@see AgentCredentialFence}): runs synchronously as Passport persists each
 * credential, so what a racing grant or consent returns is already dead.
 *
 * - an OAuth-grant access token persisted inside a fence goes through
 *   {@see CredentialRevoker}, which audits it;
 * - an authorization code persisted inside a fence (a consent approved just
 *   before the revocation) is revoked, so it cannot be exchanged once the
 *   fence lapses;
 * - a refresh token persisted for an access token that is already revoked is
 *   never legitimate, fence or not, and is revoked.
 *
 * The last two are audited as `agent_token.revoked_in_fence`.
 */
final readonly class RevokeCredentialsIssuedDuringRevocation
{
    public const string ACTOR = 'revocation-fence';

    public function __construct(
        private AgentCredentialFence $fence,
        private CredentialRevoker $revoker,
        private LoggerInterface $log,
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

    public function refreshTokenCreated(RefreshTokenCreated $event): void
    {
        $parent = Passport::token()->newQuery()->whereKey($event->accessTokenId)->first(['user_id', 'client_id', 'revoked']);
        if ($parent === null || ! (bool) $parent->getAttribute('revoked')) {
            return;
        }
        if (Passport::refreshToken()->newQuery()->whereKey($event->refreshTokenId)->where('revoked', false)->update(['revoked' => true]) > 0) {
            $this->audit('refresh_token', $parent->getAttribute('user_id'), (string) $parent->getAttribute('client_id'), $event->refreshTokenId);
        }
    }

    public function authCodeCreated(AuthCode $code): void
    {
        $userId = filter_var($code->getAttribute('user_id'), FILTER_VALIDATE_INT);
        $clientId = (string) $code->getAttribute('client_id');
        if ($userId === false || ! $this->fence->covers($userId, $clientId)) {
            return;
        }
        if (Passport::authCode()->newQuery()->whereKey($code->getKey())->where('revoked', false)->update(['revoked' => true]) > 0) {
            $this->audit('authorization_code', $userId, $clientId, (string) $code->getKey());
        }
    }

    private function audit(string $credential, mixed $userId, string $clientId, string $id): void
    {
        $this->log->info('agent_token.revoked_in_fence', [
            'actor' => self::ACTOR,
            'subject_id' => AgentPrincipal::query()->whereKey($userId)->value('public_id'),
            'credential' => $credential,
            'client_id' => $clientId,
            'credential_fingerprint' => CredentialFingerprint::of($id),
        ]);
    }
}
