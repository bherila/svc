<?php

namespace App\Support\AgentApi\BearerRejection;

/**
 * What may be logged about a presented bearer: enough to tell an access token
 * from a refresh token or a paste error, never enough to replay it.
 *
 * The prefix is at most eight characters and always less than half of the
 * token, so even a very short bearer is never written out whole. Characters
 * outside the token alphabets are shown as `?`, keeping the line one line.
 */
final readonly class BearerFingerprint
{
    public const int PREFIX_LENGTH = 8;

    private function __construct(
        public int $length,
        public string $prefix,
        public bool $jwtShaped,
    ) {}

    public static function of(#[\SensitiveParameter] string $token): self
    {
        $length = strlen($token);
        $shown = min(self::PREFIX_LENGTH, intdiv(max($length - 1, 0), 2));
        $prefix = (string) preg_replace('/[^A-Za-z0-9._~+\/=|-]/', '?', substr($token, 0, $shown));
        $segments = explode('.', $token);

        return new self(
            $length,
            $prefix,
            count($segments) === 3 && ! in_array('', $segments, true),
        );
    }
}
