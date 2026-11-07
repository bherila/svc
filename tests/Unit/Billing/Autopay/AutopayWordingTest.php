<?php

namespace Tests\Unit\Billing\Autopay;

use App\Support\Billing\Autopay\AutopayWording;
use PHPUnit\Framework\TestCase;

final class AutopayWordingTest extends TestCase
{
    /** The same sentence, character for character, as resources/js/components/billing/autopay.test.tsx expects. */
    public function test_the_authorization_wording_is_fixed(): void
    {
        $this->assertSame(
            'I authorize Example Studio to charge Visa ending 4242 for Northwind Example Co. invoices on their due dates, up to $5,000.00 each, until I turn autopay off.',
            AutopayWording::authorization('Example Studio', 'Northwind Example Co.', 'Visa ending 4242', 500_000, 'USD'),
        );
    }

    public function test_money_is_formatted_for_the_currency(): void
    {
        $this->assertSame('$0.01', AutopayWording::money(1, 'usd'));
        $this->assertSame('$1,234,567.89', AutopayWording::money(123_456_789, 'USD'));
        $this->assertSame('EUR 5,000.00', AutopayWording::money(500_000, 'eur'));
    }

    public function test_the_invoice_notice_names_the_method_and_date(): void
    {
        $this->assertSame('Will be charged to Visa ending 4242 on 2030-12-01.', AutopayWording::invoiceNotice('Visa ending 4242', '2030-12-01'));
    }

    public function test_a_method_is_named_by_brand_or_type(): void
    {
        $this->assertSame('Visa ending 4242', AutopayWording::methodLabel('visa', 'card', '4242'));
        $this->assertSame('Bank account ending 6789', AutopayWording::methodLabel(null, 'us_bank_account', '6789'));
        $this->assertSame('Card', AutopayWording::methodLabel('', 'card', null));
        $this->assertSame('Saved payment method', AutopayWording::methodLabel(null, null, ''));
        $this->assertSame('Stripe ending 1', AutopayWording::methodLabel(' stripe ', 'card', '1'));
    }
}
