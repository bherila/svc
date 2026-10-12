<?php

namespace App\Support\Square;

/**
 * Square's hosted view-and-pay page for an invoice (#438), as svc is willing
 * to send a person to it: an absolute HTTPS address on a Square host, with no
 * credentials or port in it, from Square's own data. Anything else is not a
 * Square page and is dropped.
 */
final class SquareHostedPage
{
    private const array SQUARE_HOSTS = ['squareup.com', 'squareupsandbox.com'];

    public static function url(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (self::SQUARE_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $url;
            }
        }

        return null;
    }
}
