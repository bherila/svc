<?php

namespace Tests\Unit\StripeLive;

use PHPUnit\Framework\TestCase;
use Tests\StripeLive\Support\RecordedEvent;
use Tests\StripeLive\Support\StripeEventMatcher;
use Tests\StripeLive\Support\StripeExpectation;

final class StripeExpectationTest extends TestCase
{
    /** @param array<string, mixed> $object */
    private function event(string $type, array $object): RecordedEvent
    {
        return RecordedEvent::fromJson(json_encode(['id' => 'evt_'.$type, 'type' => $type, 'created' => 1, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR));
    }

    public function test_a_plain_string_is_any_event_of_that_type(): void
    {
        $expectation = StripeExpectation::of('payment_method.attached');

        $this->assertTrue($expectation->isMetBy($this->event('payment_method.attached', ['id' => 'pm_1'])));
        $this->assertFalse($expectation->isMetBy($this->event('payment_method.detached', ['id' => 'pm_1'])));
        $this->assertSame('payment_method.attached', $expectation->describe());
        $this->assertSame($expectation, StripeExpectation::of($expectation));
    }

    public function test_an_object_expectation_needs_that_object(): void
    {
        $expectation = StripeExpectation::forObject('payment_method.attached', 'pm_2');

        $this->assertTrue($expectation->isMetBy($this->event('payment_method.attached', ['id' => 'pm_2'])));
        $this->assertFalse($expectation->isMetBy($this->event('payment_method.attached', ['id' => 'pm_1'])));
        $this->assertFalse($expectation->isMetBy($this->event('payment_method.attached', [])));
        $this->assertSame('payment_method.attached for pm_2', $expectation->describe());
    }

    public function test_a_default_expectation_needs_the_customer_and_exactly_that_default(): void
    {
        $expectation = StripeExpectation::customerDefault('cus_1', 'pm_1');

        $this->assertTrue($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_1']])));
        $this->assertTrue($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => ['id' => 'pm_1']]])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => null]])), 'the metadata update');
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_2']])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_2', 'invoice_settings' => ['default_payment_method' => 'pm_1']])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => []])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1'])));
        $this->assertFalse($expectation->isMetBy($this->event('payment_method.attached', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_1']])));
        $this->assertSame('customer.updated for cus_1 with default payment method pm_1', $expectation->describe());
    }

    public function test_a_no_default_expectation_needs_an_explicit_null(): void
    {
        $expectation = StripeExpectation::customerDefault('cus_1', null);

        $this->assertTrue($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => null]])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_1']])));
        $this->assertFalse($expectation->isMetBy($this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => []])));
        $this->assertSame('customer.updated for cus_1 with default payment method none', $expectation->describe());
    }

    public function test_missing_reports_each_unmet_expectation_once(): void
    {
        $matched = [$this->event('customer.updated', ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => null]])];

        $this->assertSame(
            ['customer.updated for cus_1 with default payment method pm_1'],
            StripeEventMatcher::missing($matched, ['customer.updated', StripeExpectation::customerDefault('cus_1', 'pm_1'), StripeExpectation::customerDefault('cus_1', 'pm_1')]),
        );
    }
}
