<?php

namespace App\Services\Credentials;

use App\Support\WorkspaceClock;
use Illuminate\Contracts\Cache\Repository;

/**
 * A short window, after a revocation, in which no new OAuth credential for the
 * same person (and client) is allowed to survive.
 *
 * A refresh grant checks its refresh token before it issues the replacement,
 * so a refresh that passed that check just before a revocation - and then
 * waited on the revocation's row locks - would otherwise persist a live
 * access and refresh token just after it. Passport announces every access
 * token it persists, so {@see RevokeCredentialsIssuedDuringRevocation} revokes
 * any access token (an operator's personal token included) or authorization
 * code that lands inside a fence, and any refresh token whose access token is
 * already revoked. A caller that revokes and then deliberately issues - the
 * deployment smoke ending its own principal's tokens - selects without a fence.
 *
 * One cache entry per person and client (or every client), so raising two
 * fences never overwrites one with the other.
 */
final readonly class AgentCredentialFence
{
    /** Long enough for an in-flight token request to finish after the revocation commits. */
    public const int SECONDS = 60;

    private const string EVERY_CLIENT = '*';

    public function __construct(
        private Repository $cache,
        private WorkspaceClock $clock,
    ) {}

    /** Fence one client of this person, or every client when null. */
    public function raise(int $userId, ?string $clientId): void
    {
        $this->cache->put(self::key($userId, $clientId ?? self::EVERY_CLIENT), $this->clock->now()->getTimestamp() + self::SECONDS, self::SECONDS);
    }

    public function covers(int $userId, string $clientId): bool
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ([$clientId, self::EVERY_CLIENT] as $client) {
            // A store that keeps numbers unserialized (Redis without value
            // serialization) hands the expiry back as a numeric string.
            $until = filter_var($this->cache->get(self::key($userId, $client)), FILTER_VALIDATE_INT);
            if ($until !== false && $until > $now) {
                return true;
            }
        }

        return false;
    }

    private static function key(int $userId, string $clientId): string
    {
        return 'agent-credential-fence:'.$userId.':'.strtolower($clientId);
    }
}
