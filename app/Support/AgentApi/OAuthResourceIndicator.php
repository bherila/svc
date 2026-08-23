<?php

namespace App\Support\AgentApi;

use Illuminate\Http\Request;

final class OAuthResourceIndicator
{
    public const string REQUEST_ATTRIBUTE = 'oauth_resource_indicator';

    public static function agentApi(): string
    {
        return rtrim(url('/api/v1'), '/');
    }

    public static function canonicalize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        if (($scheme === 'https' && $port === ':443') || ($scheme === 'http' && $port === ':80')) {
            $port = '';
        }

        return "{$scheme}://{$host}{$port}".rtrim((string) ($parts['path'] ?? ''), '/');
    }

    public static function validatedFor(Request $request): ?string
    {
        $v = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($v) ? $v : null;
    }
}
