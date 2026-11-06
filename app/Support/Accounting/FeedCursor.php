<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Exceptions\InvalidAgentApiCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The opaque position in an accounting feed: the last entry's timestamp and
 * ids ({@see FeedPosition}), the snapshot watermark the pass started with,
 * and the workspace and query it belongs to.
 *
 * Encrypted, like the change-feed cursor, so a client can neither read nor
 * forge a position, and bound to its workspace and query, so a cursor minted
 * for one listing is refused by any other instead of silently skipping rows.
 */
final readonly class FeedCursor
{
    private const int VERSION = 1;

    private const string AT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D';

    private const string WATERMARK = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D';

    public function __construct(
        public FeedPosition $position,
        /** The pass's snapshot watermark, `Y-m-d H:i:s` in UTC. */
        public string $watermark,
    ) {}

    /** @throws InvalidAgentApiCursor */
    public static function decode(?string $cursor, string $workspaceId, string $query): ?self
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
            || ($payload['feed'] ?? null) !== 'accounting'
            || ! is_string($payload['at'] ?? null) || preg_match(self::AT, $payload['at']) !== 1
            || ! is_int($payload['rank'] ?? null) || $payload['rank'] < 1
            || ! is_int($payload['row'] ?? null) || $payload['row'] < 1
            || ! is_int($payload['sub'] ?? null) || $payload['sub'] < 0
            || ! is_string($payload['watermark'] ?? null) || preg_match(self::WATERMARK, $payload['watermark']) !== 1
            || ! is_string($payload['workspace'] ?? null) || ! hash_equals($workspaceId, $payload['workspace'])
            || ! is_string($payload['query'] ?? null) || ! hash_equals(self::queryHash($query), $payload['query'])) {
            throw new InvalidAgentApiCursor('cursor is not valid for this workspace and query.');
        }

        return new self(new FeedPosition($payload['at'], $payload['rank'], $payload['row'], $payload['sub']), $payload['watermark']);
    }

    /** @throws InvalidAgentApiCursor */
    public function encode(string $workspaceId, string $query): string
    {
        if (preg_match(self::AT, $this->position->at) !== 1
            || $this->position->rank < 1
            || $this->position->row < 1
            || $this->position->sub < 0
            || preg_match(self::WATERMARK, $this->watermark) !== 1) {
            throw new InvalidAgentApiCursor('cursor positions must be a timestamp, a source and positive ids.');
        }

        return Crypt::encryptString(json_encode([
            'version' => self::VERSION,
            'feed' => 'accounting',
            'at' => $this->position->at,
            'rank' => $this->position->rank,
            'row' => $this->position->row,
            'sub' => $this->position->sub,
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
