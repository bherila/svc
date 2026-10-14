<?php

namespace Tests\Unit\Square;

use App\Support\Billing\ReceivedPaymentProvider;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ReceivedPaymentProviderTest extends TestCase
{
    public function test_the_provider_identity_has_explicit_storage_bounds(): void
    {
        $provider = new ReceivedPaymentProvider('square', 'pay_fixture_1');
        $this->assertSame('square', $provider->name);
        $this->assertSame('pay_fixture_1', $provider->paymentIdentifier);
        $this->assertSame(255, strlen((new ReceivedPaymentProvider(str_repeat('a', 50), str_repeat('p', 255)))->paymentIdentifier));
        foreach ([['', 'p'], ['Square', 'p'], ["square\n", 'p'], [str_repeat('a', 51), 'p'], ['square', ''], ['square', str_repeat('p', 256)]] as [$name, $identifier]) {
            try {
                new ReceivedPaymentProvider($name, $identifier);
                $this->fail('Invalid provider identity was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('Invalid received-payment provider identity.', $exception->getMessage());
            }
        }
    }
}
