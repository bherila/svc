<?php

namespace App\Services\Credentials;

use App\Support\WorkspaceClock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

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
    /**
     * Counted from the revocation's commit (raised again then), and far longer
     * than a token request takes to persist once its checks have passed. A
     * request stalled beyond it is outside what a fence closes; turning OAuth
     * issuance off first, as the runbook says, closes that too.
     */
    public const int SECONDS = 120;

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
        return $this->active(self::key($userId, $clientId)) || $this->active(self::key($userId, self::EVERY_CLIENT));
    }

    /**
     * Fence one connection's lineage rather than its client: whatever a refresh
     * redeeming a refresh token of this access token persists. A connection
     * disconnecting itself fences only itself, never another connection the
     * same person holds with the same client.
     */
    public function raiseLineage(string $accessTokenId): void
    {
        $this->cache->put(self::lineageKey($accessTokenId), $this->clock->now()->getTimestamp() + self::SECONDS, self::SECONDS);
    }

    public function coversLineage(string $accessTokenId): bool
    {
        return $this->active(self::lineageKey($accessTokenId));
    }

    /**
     * Remember which access token a refresh rotated this one into, for the
     * fence's window: a connection disconnecting itself with a token a refresh
     * had just replaced follows the family to the replacement. Two refreshes
     * redeeming one refresh token concurrently can each mint a successor, so
     * every successor is kept, appended under a lock. The list is stored
     * encrypted under a digest key, so the cache holds no token id.
     */
    public function recordSuccessor(string $accessTokenId, string $successorId): void
    {
        $key = self::successorKey($accessTokenId);
        $append = function () use ($key, $successorId): void {
            $successors = $this->successorsAt($key);
            if (! in_array($successorId, $successors, true)) {
                $successors[] = $successorId;
            }
            $this->cache->put($key, Crypt::encryptString((string) json_encode($successors)), self::SECONDS);
        };
        $store = $this->cache->getStore();
        $store instanceof LockProvider
            ? $store->lock($key.':lock', 10)->block(5, $append)
            : $append();
    }

    /** @return list<string> */
    public function successorsOf(string $accessTokenId): array
    {
        return $this->successorsAt(self::successorKey($accessTokenId));
    }

    /** @return list<string> */
    private function successorsAt(string $key): array
    {
        $sealed = $this->cache->get($key);
        /** @infection-ignore-all Equivalent: without it a missing entry reaches decryptString, which refuses it with the DecryptException caught below; it is here so a miss never passes null to the decrypter. */
        if ($sealed === null) {
            return [];
        }
        try {
            // Anything this application did not seal - a string or otherwise - fails to decrypt.
            $successors = json_decode(Crypt::decryptString($sealed), true);
        } catch (DecryptException) {
            return [];
        }

        return is_array($successors) ? array_values(array_filter($successors, is_string(...))) : [];
    }

    private static function successorKey(string $accessTokenId): string
    {
        return 'agent-credential-fence:successor:'.hash('sha256', $accessTokenId);
    }

    private function active(string $key): bool
    {
        // A store that keeps numbers unserialized (Redis without value
        // serialization) hands the expiry back as a numeric string.
        $until = filter_var($this->cache->get($key), FILTER_VALIDATE_INT);

        return $until !== false && $until > $this->clock->now()->getTimestamp();
    }

    private static function key(int $userId, string $clientId): string
    {
        return 'agent-credential-fence:'.$userId.':'.strtolower($clientId);
    }

    /** Hashed: the cache never holds a token id. */
    private static function lineageKey(string $accessTokenId): string
    {
        return 'agent-credential-fence:lineage:'.hash('sha256', $accessTokenId);
    }
}
