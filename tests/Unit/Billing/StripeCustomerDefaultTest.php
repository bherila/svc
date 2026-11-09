<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\StripeCustomerDefault;
use App\Support\Billing\StripeEventMustBeRetried;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a clear answer from Stripe moves SVC's default. "No default" is a
 * positive statement (`default_payment_method: null`), never the absence of
 * one: an unreadable customer is retried, because reading it as "none" would
 * switch autopay off.
 */
#[CoversClass(StripeCustomerDefault::class)]
final class StripeCustomerDefaultTest extends TestCase
{
    public function test_a_default_method_id_is_read(): void
    {
        $this->assertSame('pm_synthetic_a', StripeCustomerDefault::of($this->customer('pm_synthetic_a'), 'cus_synthetic'));
    }

    public function test_an_expanded_default_method_is_read_by_its_id(): void
    {
        $this->assertSame('pm_synthetic_a', StripeCustomerDefault::of($this->customer(['id' => 'pm_synthetic_a', 'object' => 'payment_method']), 'cus_synthetic'));
    }

    public function test_a_customer_stripe_says_has_no_default_has_none(): void
    {
        $this->assertNull(StripeCustomerDefault::of($this->customer(null), 'cus_synthetic'));
    }

    public function test_a_deleted_customer_has_none(): void
    {
        $this->assertNull(StripeCustomerDefault::of(['id' => 'cus_synthetic', 'object' => 'customer', 'deleted' => true], 'cus_synthetic'));
    }

    public function test_a_customer_not_marked_deleted_is_read_normally(): void
    {
        $this->assertSame('pm_synthetic_a', StripeCustomerDefault::of([...$this->customer('pm_synthetic_a'), 'deleted' => false], 'cus_synthetic'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unreadable(): array
    {
        $customer = static fn (mixed $default): array => ['id' => 'cus_synthetic', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $default]];

        return [
            'another customer' => [['id' => 'cus_other', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]],
            'no id' => [['object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]],
            'not a customer' => [['id' => 'cus_synthetic', 'object' => 'payment_method', 'invoice_settings' => ['default_payment_method' => null]]],
            'no object' => [['id' => 'cus_synthetic', 'invoice_settings' => ['default_payment_method' => null]]],
            'no invoice settings' => [['id' => 'cus_synthetic', 'object' => 'customer']],
            'invoice settings not a map' => [['id' => 'cus_synthetic', 'object' => 'customer', 'invoice_settings' => 'x']],
            'default key missing' => [['id' => 'cus_synthetic', 'object' => 'customer', 'invoice_settings' => []]],
            'empty default' => [$customer('')],
            'number default' => [$customer(7)],
            'object default without id' => [$customer(['object' => 'payment_method'])],
            'object default with empty id' => [$customer(['id' => ''])],
            'object default with non-string id' => [$customer(['id' => 7])],
        ];
    }

    /** @param array<string, mixed> $customer */
    #[DataProvider('unreadable')]
    public function test_an_answer_that_cannot_be_read_is_retried_never_taken_for_no_default(array $customer): void
    {
        $this->expectException(StripeEventMustBeRetried::class);

        StripeCustomerDefault::of($customer, 'cus_synthetic');
    }

    /** @return array<string, mixed> */
    private function customer(mixed $default): array
    {
        return ['id' => 'cus_synthetic', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $default]];
    }
}
