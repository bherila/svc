<?php

namespace App\Services\Credentials;

use App\Models\AgentPrincipal;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use Psr\Log\LoggerInterface;

/**
 * Closes the race a revocation cannot close with row locks alone (see
 * {@see AgentCredentialFence}): runs synchronously as Passport persists each
 * credential, so what a racing grant or consent returns is already dead.
 *
 * - an access token (OAuth grant or personal) persisted inside a fence goes through
 *   {@see CredentialRevoker}, which audits it;
 * - an authorization code persisted inside a fence (a consent approved just
 *   before the revocation) is revoked, so it cannot be exchanged once the
 *   fence lapses;
 * - a refresh token persisted for an access token that is already revoked is
 *   never legitimate, fence or not, and is revoked.
 *
 * The last two are audited as `agent_token.revoked_in_fence`.
 */
final class RevokeCredentialsIssuedDuringRevocation
{
    public const string ACTOR = 'revocation-fence';

    /**
     * Whose access tokens this process just fenced, by token id, so the refresh
     * token Passport persists for one next is audited with its owner and client
     * even when a purge has removed the parent in between. Each entry is read
     * once, by that refresh token.
     *
     * @var array<string, array{user: int, client: string}>
     */
    private static array $fenced = [];

    public function __construct(
        private readonly AgentCredentialFence $fence,
        private readonly CredentialRevoker $revoker,
        private readonly LoggerInterface $log,
    ) {}

    public function accessTokenCreated(AccessTokenCreated $event): void
    {
        $userId = filter_var($event->userId, FILTER_VALIDATE_INT);
        if ($userId === false || ! $this->fence->covers($userId, $event->clientId)) {
            return;
        }
        // Personal access tokens too: an operator token issued for the person
        // while their connections are being cut off is no exception.
        $owner = AgentPrincipal::query()->find($userId);
        if ($owner instanceof AgentPrincipal) {
            // Bounded: a personal token is never followed by a refresh token.
            if (count(self::$fenced) >= 100) {
                self::$fenced = [];
            }
            self::$fenced[$event->tokenId] = ['user' => $userId, 'client' => $event->clientId];
            $this->revoker->revoke(AgentCredentialSelection::token($owner, $event->tokenId), self::ACTOR);
        }
    }

    public function refreshTokenCreated(RefreshTokenCreated $event): void
    {
        $fenced = self::$fenced[$event->accessTokenId] ?? null;
        unset(self::$fenced[$event->accessTokenId]);
        $parent = Passport::token()->newQuery()->whereKey($event->accessTokenId)->first(['user_id', 'client_id', 'revoked']);
        // A parent that is already gone was revoked and purged in between (a
        // purge takes revoked rows): the refresh token is no more legitimate.
        if ($parent !== null && ! (bool) $parent->getAttribute('revoked')) {
            return;
        }
        $refresh = Passport::refreshToken()->newQuery()->whereKey($event->refreshTokenId)->first();
        if ($refresh === null || Passport::refreshToken()->newQuery()->whereKey($event->refreshTokenId)->where('revoked', false)->update(['revoked' => true]) === 0) {
            return;
        }
        $this->audit(
            'refresh_token',
            $parent?->getAttribute('user_id') ?? $refresh->getAttribute(ProviderIdentityTokens::OWNER_COLUMN) ?? $fenced['user'] ?? null,
            (string) ($parent?->getAttribute('client_id') ?? $refresh->getAttribute('client_id') ?? $fenced['client'] ?? ''),
            $event->refreshTokenId,
        );
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
