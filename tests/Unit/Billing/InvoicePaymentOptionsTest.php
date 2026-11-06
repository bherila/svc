<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoicePaymentOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvoicePaymentOptions::class)]
final class InvoicePaymentOptionsTest extends TestCase
{
    private const KEY = 'pk_test_synthetic';

    private const SQUARE = 'https://square.example.test/p';

    private const NONE = ['stripe' => null, 'square' => null];

    /** @return array{intent_href: string, publishable_key: string} */
    private function stripe(): array
    {
        return ['intent_href' => '/pay', 'publishable_key' => self::KEY];
    }

    public function test_both_are_offered_when_the_workspace_has_both(): void
    {
        $this->assertSame(['stripe' => $this->stripe(), 'square' => ['href' => self::SQUARE]], InvoicePaymentOptions::compose(true, self::SQUARE, false, true, true, self::KEY, '/pay'));
        $this->assertSame(['stripe' => $this->stripe(), 'square' => null], InvoicePaymentOptions::compose(true, null, false, true, true, self::KEY, '/pay'));
    }

    public function test_square_collecting_the_invoice_withdraws_stripe_and_keeps_square(): void
    {
        $this->assertSame(['stripe' => null, 'square' => ['href' => self::SQUARE]], InvoicePaymentOptions::compose(true, self::SQUARE, true, true, true, self::KEY, '/pay'));
    }

    public function test_a_publishable_key_that_may_belong_to_another_account_is_never_offered(): void
    {
        // Connected, key configured, but the intent would be created on an
        // account the key is not known to belong to: fail closed, keep Square.
        $this->assertSame(self::NONE, InvoicePaymentOptions::compose(true, null, false, true, false, self::KEY, '/pay'));
        $this->assertSame(['stripe' => null, 'square' => ['href' => self::SQUARE]], InvoicePaymentOptions::compose(true, self::SQUARE, false, true, false, self::KEY, '/pay'));
    }

    public function test_each_missing_ingredient_withdraws_stripe(): void
    {
        $this->assertSame(self::NONE, InvoicePaymentOptions::compose(true, null, false, false, true, self::KEY, '/pay'));
        $this->assertSame(self::NONE, InvoicePaymentOptions::compose(true, null, false, true, true, null, '/pay'));
        $this->assertSame(self::NONE, InvoicePaymentOptions::compose(true, null, false, true, true, '', '/pay'));
    }

    public function test_nothing_is_offered_for_an_invoice_that_is_not_payable(): void
    {
        $this->assertSame(self::NONE, InvoicePaymentOptions::compose(false, self::SQUARE, false, true, true, self::KEY, '/pay'));
    }
}
