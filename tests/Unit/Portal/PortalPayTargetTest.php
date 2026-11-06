<?php

namespace Tests\Unit\Portal;

use App\Support\Portal\PortalPayTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PortalPayTarget::class)]
final class PortalPayTargetTest extends TestCase
{
    private const INVOICE = '/portal/co/invoices/inv';

    private const SQUARE = 'https://square.example.test/p';

    /** @var array{intent_href: string, publishable_key: string} */
    private const STRIPE = ['intent_href' => '/pay', 'publishable_key' => 'pk_test_synthetic'];

    public function test_card_payment_goes_to_the_invoice_pages_pay_section(): void
    {
        $this->assertSame(self::INVOICE.'#pay', PortalPayTarget::href(['stripe' => self::STRIPE, 'square' => null], self::INVOICE));
    }

    public function test_card_payment_wins_when_square_is_offered_too(): void
    {
        $this->assertSame(self::INVOICE.'#pay', PortalPayTarget::href(['stripe' => self::STRIPE, 'square' => ['href' => self::SQUARE]], self::INVOICE));
    }

    public function test_square_alone_goes_straight_to_its_hosted_page(): void
    {
        $this->assertSame(self::SQUARE, PortalPayTarget::href(['stripe' => null, 'square' => ['href' => self::SQUARE]], self::INVOICE));
    }

    public function test_nothing_offered_is_no_pay_button(): void
    {
        $this->assertNull(PortalPayTarget::href(['stripe' => null, 'square' => null], self::INVOICE));
    }
}
