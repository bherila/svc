<?php

namespace App\Services\Square;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A Square read that did not succeed, as a bounded, safe error (#438).
 *
 * The message is written here from fixed text plus Square's error category
 * and code, each checked against Square's upper-snake-case shape. Nothing
 * from the outgoing request is kept - not the credential, not a header, not
 * the URL - and no lower exception is chained, so neither a rendered response
 * nor a logged report can carry them. As an HTTP exception it renders as the
 * API's ordinary `{message}` error and is not reported to the log.
 */
final class SquareRequestFailed extends HttpException
{
    /** @param array<string, string> $headers */
    private function __construct(
        int $status,
        string $message,
        public readonly ?string $squareCategory = null,
        public readonly ?string $squareCode = null,
        array $headers = [],
        public readonly ?SquareReadFailureReason $reason = null,
    ) {
        parent::__construct($status, $message, null, $headers);
    }

    public static function notFound(): self
    {
        return new self(404, 'Square has no record with that id.', 'INVALID_REQUEST_ERROR', 'NOT_FOUND');
    }

    public static function notConnected(): self
    {
        return new self(404, 'Square is not connected to this workspace.');
    }

    /** Square refused the request itself: a bad filter, cursor or id. */
    public static function rejected(?string $category, ?string $code): self
    {
        [$category, $code] = [self::token($category), self::token($code)];

        return new self(422, sprintf('Square rejected the request (%s: %s).', $category, $code), $category, $code);
    }

    /** Square refused svc's credential: an operator has to fix the connection. */
    public static function credentialRefused(?string $category, ?string $code): self
    {
        [$category, $code] = [self::token($category), self::token($code)];

        return new self(502, sprintf('Square refused the configured connection (%s: %s). An operator must check it.', $category, $code), $category, $code);
    }

    public static function rateLimitedBySquare(int $retryAfterSeconds): self
    {
        $seconds = max(1, min(300, $retryAfterSeconds));

        return new self(429, 'Square is limiting requests. Retry later.', 'RATE_LIMIT_ERROR', 'RATE_LIMITED', ['Retry-After' => (string) $seconds]);
    }

    /** svc's own per-workspace budget for pass-through reads. */
    public static function rateLimited(int $retryAfterSeconds): self
    {
        $seconds = max(1, min(300, $retryAfterSeconds));

        return new self(429, sprintf('Too many Square reads for this workspace. Retry in %d seconds.', $seconds), null, null, ['Retry-After' => (string) $seconds]);
    }

    public static function unavailable(?SquareReadFailureReason $reason = null): self
    {
        return new self(503, 'Square is unavailable. Retry later.', 'API_ERROR', 'SERVICE_UNAVAILABLE', ['Retry-After' => '30'], $reason);
    }

    public static function unreadable(?SquareReadFailureReason $reason = null): self
    {
        return new self(502, 'Square returned a response svc cannot read.', reason: $reason);
    }

    /** Only record-specific failures let a reconciliation sweep keep reading. */
    public function isRecordSpecific(): bool
    {
        return match ($this->getStatusCode()) {
            404 => $this->squareCode === 'NOT_FOUND',
            422 => true,
            // Credential refusals always carry a sanitized category; malformed
            // record responses carry none, even though both render as 502.
            502 => $this->squareCategory === null,
            default => false,
        };
    }

    /** Square's category and code are upper snake case; anything else is not repeated. */
    private static function token(?string $value): string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $value) === 1 ? $value : 'UNKNOWN';
    }
}
