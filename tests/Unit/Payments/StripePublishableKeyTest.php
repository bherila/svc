<?php

namespace Tests\Unit\Payments;

use App\Support\Payments\StripePublishableKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StripePublishableKey::class)]
final class StripePublishableKeyTest extends TestCase
{
    public function test_the_mode_is_read_from_the_prefix(): void
    {
        $this->assertSame('live', StripePublishableKey::modeOf('pk_live_synthetic0001'));
        $this->assertSame('test', StripePublishableKey::modeOf('pk_test_synthetic0001'));
    }

    public function test_anything_else_has_no_mode(): void
    {
        foreach (['', 'pk_live_', 'pk_live_short', 'sk_live_synthetic0001', 'rk_test_synthetic0001', 'pk_prod_synthetic0001', 'xpk_live_synthetic0001', 'pk_live_synthetic0001 ', "pk_live_synthetic0001\n", 'pk_live_synth etic0001'] as $key) {
            $this->assertNull(StripePublishableKey::modeOf($key), $key);
        }
    }

    public function test_the_length_boundaries_of_the_opaque_part(): void
    {
        $this->assertNull(StripePublishableKey::modeOf('pk_test_'.str_repeat('a', 7)));
        $this->assertSame('test', StripePublishableKey::modeOf('pk_test_'.str_repeat('a', 8)));
        $this->assertSame('test', StripePublishableKey::modeOf('pk_test_'.str_repeat('a', 250)));
        $this->assertNull(StripePublishableKey::modeOf('pk_test_'.str_repeat('a', 251)));
    }

    public function test_a_key_in_the_secrets_mode_is_accepted(): void
    {
        $this->assertNull(StripePublishableKey::rejection('pk_live_synthetic0001', 'live'));
        $this->assertNull(StripePublishableKey::rejection('pk_test_synthetic0001', 'test'));
    }

    public function test_a_key_in_the_other_mode_is_refused_naming_both_modes(): void
    {
        $this->assertSame(
            'This is a test-mode publishable key but the restricted key is live-mode. Use keys from the same mode.',
            StripePublishableKey::rejection('pk_test_synthetic0001', 'live'),
        );
        $this->assertSame(
            'This is a live-mode publishable key but the restricted key is test-mode. Use keys from the same mode.',
            StripePublishableKey::rejection('pk_live_synthetic0001', 'test'),
        );
    }

    public function test_a_value_that_is_not_a_publishable_key_is_refused(): void
    {
        $this->assertSame(
            'Enter a Stripe publishable key (it starts with pk_live_ or pk_test_).',
            StripePublishableKey::rejection('sk_live_synthetic0001', 'live'),
        );
    }
}
