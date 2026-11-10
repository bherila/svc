<?php

namespace Tests\Unit\StripeLive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\StripeLive\Support\StripeKeyVerdict;
use Tests\StripeLive\Support\StripeTestKeyGate;

final class StripeTestKeyGateTest extends TestCase
{
    /** @return iterable<string, array{0: string|null, 1: StripeKeyVerdict}> */
    public static function keys(): iterable
    {
        yield 'unset' => [null, StripeKeyVerdict::Absent];
        yield 'empty' => ['', StripeKeyVerdict::Absent];
        yield 'blank' => ["  \n", StripeKeyVerdict::Absent];
        yield 'secret test key' => ['sk_test_abc123', StripeKeyVerdict::TestMode];
        yield 'restricted test key' => ['rk_test_abc123', StripeKeyVerdict::TestMode];
        yield 'test key with trailing newline' => ["rk_test_abc123\n", StripeKeyVerdict::TestMode];
        yield 'live secret key' => ['sk_live_abc123', StripeKeyVerdict::Refused];
        yield 'live restricted key' => ['rk_live_abc123', StripeKeyVerdict::Refused];
        yield 'publishable test key' => ['pk_test_abc123', StripeKeyVerdict::Refused];
        yield 'webhook secret' => ['whsec_abc123', StripeKeyVerdict::Refused];
        yield 'prefix not at the start' => ['x sk_test_abc', StripeKeyVerdict::Refused];
        yield 'bare prefix text' => ['test', StripeKeyVerdict::Refused];
        yield 'uppercase prefix' => ['SK_TEST_abc', StripeKeyVerdict::Refused];
    }

    #[DataProvider('keys')]
    public function test_the_verdict_follows_the_key_prefix(?string $key, StripeKeyVerdict $expected): void
    {
        $this->assertSame($expected, StripeTestKeyGate::verdict($key));
    }

    public function test_an_absent_key_means_skip_and_a_test_key_is_returned_trimmed(): void
    {
        $this->assertNull(StripeTestKeyGate::acceptedKey(null));
        $this->assertNull(StripeTestKeyGate::acceptedKey(' '));
        $this->assertSame('rk_test_abc', StripeTestKeyGate::acceptedKey(" rk_test_abc\n"));
    }

    public function test_a_live_key_is_refused_loudly_without_echoing_it(): void
    {
        try {
            StripeTestKeyGate::acceptedKey('sk_live_SECRETVALUE');
            $this->fail('A live key must be refused.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('STRIPE_TEST_SECRET_KEY', $refusal->getMessage());
            $this->assertStringContainsString('sk_test_', $refusal->getMessage());
            $this->assertStringNotContainsString('SECRETVALUE', $refusal->getMessage());
        }
    }

    public function test_the_key_is_read_from_the_process_environment(): void
    {
        $name = StripeTestKeyGate::ENVIRONMENT_VARIABLE;
        $previous = getenv($name);
        try {
            putenv($name.'=rk_test_from_env');
            $this->assertSame('rk_test_from_env', StripeTestKeyGate::fromEnvironment());
            putenv($name.'=');
            $this->assertNull(StripeTestKeyGate::fromEnvironment());
        } finally {
            putenv($previous === false ? $name : $name.'='.$previous);
        }
    }
}
