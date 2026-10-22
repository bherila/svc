<?php

namespace App\Support\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The opaque position in a change-feed listing (see {@see ChangeFeedPage}):
 * the last row's change time and id, the snapshot watermark the pass started
 * with, and the workspace and query it belongs to.
 *
 * Encrypted, so a client can neither read nor forge a position, and bound to
 * its workspace and query, so a cursor minted for one listing is refused by
 * any other rather than silently skipping rows there.
 */
final readonly class ChangeFeedCursor
{
    private const int VERSION = 1;

    private const string TIMESTAMP = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    private const string MICROSECOND_TIMESTAMP = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D';

    public function __construct(
        /** The last returned row's UTC change time, optionally with six fractional digits; null if never stamped. */
        public ?string $changedAt,
        public int $id,
        /** The pass's snapshot watermark, `Y-m-d H:i:s` in UTC. */
        public string $watermark,
        public bool $microseconds = false,
    ) {}

    /** @throws InvalidAgentApiCursor */
    public static function decode(?string $cursor, string $workspaceId, string $query, bool $microseconds = false): ?self
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidAgentApiCursor('cursor must be an opaque pagination cursor.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::VERSION
            || ($payload['feed'] ?? null) !== 'changes'
            || ($payload['precision'] ?? 0) !== ($microseconds ? 6 : 0)
            || ! array_key_exists('at', $payload)
            || ($payload['at'] !== null && (! is_string($payload['at']) || preg_match($microseconds ? self::MICROSECOND_TIMESTAMP : self::TIMESTAMP, $payload['at']) !== 1))
            || ! is_int($payload['id'] ?? null) || $payload['id'] < 1
            || ! is_string($payload['watermark'] ?? null) || preg_match(self::TIMESTAMP, $payload['watermark']) !== 1
            || ! is_string($payload['workspace'] ?? null) || ! hash_equals($workspaceId, $payload['workspace'])
            || ! is_string($payload['query'] ?? null) || ! hash_equals(self::queryHash($query), $payload['query'])) {
            throw new InvalidAgentApiCursor('cursor is not valid for this workspace and query.');
        }

        return new self($payload['at'], $payload['id'], $payload['watermark'], $microseconds);
    }

    public function encode(string $workspaceId, string $query): string
    {
        if ($this->id < 1
            || ($this->changedAt !== null && preg_match($this->microseconds ? self::MICROSECOND_TIMESTAMP : self::TIMESTAMP, $this->changedAt) !== 1)
            || preg_match(self::TIMESTAMP, $this->watermark) !== 1) {
            throw new InvalidAgentApiCursor('cursor positions must be a change time and a positive id.');
        }

        return Crypt::encryptString(json_encode([
            ...($this->microseconds ? ['precision' => 6] : []),
            'version' => self::VERSION,
            'feed' => 'changes',
            'at' => $this->changedAt,
            'id' => $this->id,
            'watermark' => $this->watermark,
            'workspace' => $workspaceId,
            'query' => self::queryHash($query),
        ], JSON_THROW_ON_ERROR));
    }

    private static function queryHash(string $query): string
    {
        return hash('sha256', $query);
    }
}
