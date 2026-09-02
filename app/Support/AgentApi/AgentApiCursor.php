<?php

namespace App\Support\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class AgentApiCursor
{
    public static function decode(?string $cursor, string $workspaceId, string $query): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $value = Crypt::decryptString($cursor);
            $payload = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return self::legacy($cursor);
        }

        if (! is_array($payload)
            || ! isset($payload['version'], $payload['id'], $payload['workspace'], $payload['query'])
            || $payload['version'] !== 1
            || ! is_int($payload['id'])
            || $payload['id'] < 1
            || ! is_string($payload['workspace'])
            || ! is_string($payload['query'])
            || ! hash_equals($workspaceId, $payload['workspace'])
            || ! hash_equals(self::queryHash($query), $payload['query'])) {
            throw new InvalidAgentApiCursor('cursor is not valid for this workspace and query.');
        }

        return $payload['id'];
    }

    public static function encode(int $id, string $workspaceId, string $query): string
    {
        if ($id < 1) {
            throw new InvalidAgentApiCursor('cursor ids must be positive.');
        }

        return Crypt::encryptString(json_encode([
            'version' => 1,
            'id' => $id,
            'workspace' => $workspaceId,
            'query' => self::queryHash($query),
        ], JSON_THROW_ON_ERROR));
    }

    private static function queryHash(string $query): string
    {
        return hash('sha256', $query);
    }

    private static function legacy(string $cursor): int
    {
        if (! (bool) config('agent_api.accept_legacy_cursors', true)) {
            throw new InvalidAgentApiCursor('cursor must be an opaque pagination cursor.');
        }

        $value = base64_decode($cursor, true);
        if ($value === false || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidAgentApiCursor('cursor must be an opaque pagination cursor.');
        }

        return (int) $value;
    }
}
