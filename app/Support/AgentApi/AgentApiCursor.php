<?php

namespace App\Support\AgentApi;

use InvalidArgumentException;

final class AgentApiCursor
{
    public static function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $value = base64_decode($cursor, true);
        if ($value === false || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidArgumentException('cursor must be an opaque pagination cursor.');
        }

        return (int) $value;
    }

    public static function encode(int $id): string
    {
        return base64_encode((string) $id);
    }
}
