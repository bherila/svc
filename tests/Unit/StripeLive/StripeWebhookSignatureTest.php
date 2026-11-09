<?php

namespace Tests\Unit\StripeLive;

use PHPUnit\Framework\TestCase;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Tests\StripeLive\Support\StripeWebhookSignature;

final class StripeWebhookSignatureTest extends TestCase
{
    private const string PAYLOAD = '{"id":"evt_synthetic","object":"event","type":"payment_intent.succeeded","data":{"object":{"id":"pi_synthetic"}}}';

    public function test_the_header_has_stripes_documented_shape(): void
    {
        $header = StripeWebhookSignature::header(self::PAYLOAD, 'whsec_synthetic', 1_700_000_000);

        $this->assertSame('t=1700000000,v1='.hash_hmac('sha256', '1700000000.'.self::PAYLOAD, 'whsec_synthetic'), $header);
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
    }

    public function test_stripes_own_verifier_accepts_the_signature(): void
    {
        $header = StripeWebhookSignature::header(self::PAYLOAD, 'whsec_synthetic', time());

        $event = Webhook::constructEvent(self::PAYLOAD, $header, 'whsec_synthetic');

        $this->assertSame('evt_synthetic', $event->id);
    }

    public function test_a_changed_body_is_refused(): void
    {
        $header = StripeWebhookSignature::header(self::PAYLOAD, 'whsec_synthetic', time());

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent(self::PAYLOAD.' ', $header, 'whsec_synthetic');
    }

    public function test_a_signature_made_with_another_secret_is_refused(): void
    {
        $header = StripeWebhookSignature::header(self::PAYLOAD, 'whsec_other', time());

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent(self::PAYLOAD, $header, 'whsec_synthetic');
    }
}
