<?php

namespace App\Services\Payments;

use LogicException;
use SensitiveParameter;
use Stripe\StripeClient;

/**
 * What it takes to call Stripe for one workspace: that workspace's own key.
 *
 * The key is held privately and never printed: dumps show it redacted and the
 * object refuses to serialize, so it cannot reach a log or a queue.
 */
final readonly class StripeAccess
{
    public function __construct(
        #[SensitiveParameter] private string $secret,
        /** `live`, `test`, or `unknown`. */
        public string $mode,
    ) {}

    public static function forKey(#[SensitiveParameter] string $secret): self
    {
        return new self($secret, self::modeOf($secret));
    }

    public function client(): StripeClient
    {
        return new StripeClient($this->secret);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]', 'mode' => $this->mode];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Stripe credentials are never serialized.');
    }

    public static function modeOf(#[SensitiveParameter] string $secret): string
    {
        return match (true) {
            str_starts_with($secret, 'sk_test_'), str_starts_with($secret, 'rk_test_') => 'test',
            str_starts_with($secret, 'sk_live_'), str_starts_with($secret, 'rk_live_') => 'live',
            default => 'unknown',
        };
    }
}
