<?php

namespace App\Services\Mcp\Toolsets;

use Mcp\Exception\InvalidCursorException;

/**
 * One page of tool names, cut with the SDK's own cursor: the base64 of the
 * offset of the page's first item. A filtered list pages exactly as the
 * registry's unfiltered one did, so a client's cursor handling is unchanged.
 */
final class McpToolPage
{
    /**
     * @param  list<string>  $names
     * @return array{names: list<string>, next: ?string}
     *
     * @throws InvalidCursorException when the cursor is not one of ours or points past the end
     */
    public static function slice(array $names, int $limit, ?string $cursor): array
    {
        $offset = 0;
        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false || ! is_numeric($decoded)) {
                throw new InvalidCursorException($cursor);
            }
            $offset = (int) $decoded;
            if ($offset < 0 || $offset > count($names)) {
                throw new InvalidCursorException($cursor);
            }
        }
        $next = $offset + $limit;

        return [
            'names' => array_slice($names, $offset, $limit),
            'next' => $next < count($names) ? base64_encode((string) $next) : null,
        ];
    }
}
