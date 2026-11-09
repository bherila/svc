<?php

namespace Tests\Unit\Packages;

use App\Support\Packages\PackagePublishRules;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

final class PackagePublishRulesTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function terms(array $overrides = []): PackageTerms
    {
        return PackageTerms::fromArray([
            ...PackageTerms::defaults('USD'),
            'name' => 'Synthetic hosting',
            'components' => [['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'monthly', 'amount' => 4500]],
            ...$overrides,
        ]);
    }

    public function test_a_plain_fixed_price_package_publishes_without_stripe(): void
    {
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['invoice_issuing' => 'automatic']), stripeConnected: false));
    }

    public function test_a_package_with_nothing_priced_is_refused(): void
    {
        $this->assertSame([PackageRefusal::NothingPriced], PackagePublishRules::refusals($this->terms(['components' => []]), true));
    }

    public function test_a_retainer_or_an_hourly_rate_alone_is_something_priced(): void
    {
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['components' => [], 'retainer_amount' => 60000, 'retainer_minutes' => 300]), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['components' => [], 'hourly_rate_amount' => 14000]), true));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function paymentTerms(): iterable
    {
        yield 'setup fee at sign-up' => [['charge_setup_fee_at_signup' => true, 'components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 15000], ['kind' => 'recurring', 'description' => 'Hosting', 'amount' => 4500]]]];
        yield 'first period in advance' => [['charge_first_period' => true]];
        yield 'autopay required' => [['require_autopay' => true, 'autopay_limit_amount' => 10000]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('paymentTerms')]
    #[Requirement('PKG-4')]
    public function test_a_package_that_takes_payment_needs_stripe(array $overrides): void
    {
        $this->assertSame([PackageRefusal::PaymentNeedsStripe], PackagePublishRules::refusals($this->terms($overrides), stripeConnected: false));
        $this->assertSame([], PackagePublishRules::refusals($this->terms($overrides), stripeConnected: true));
    }

    #[Requirement('PKG-4')]
    public function test_a_setup_fee_billed_with_the_first_cycle_takes_no_payment_at_sign_up(): void
    {
        $terms = $this->terms(['components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 15000], ['kind' => 'recurring', 'description' => 'Hosting', 'amount' => 4500]]]);

        $this->assertFalse($terms->involvesPayment());
        $this->assertSame([], PackagePublishRules::refusals($terms, stripeConnected: false));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function usageTerms(): iterable
    {
        yield 'hourly rate' => [['hourly_rate_amount' => 14000]];
        yield 'retainer amount' => [['retainer_amount' => 60000]];
        yield 'retainer minutes' => [['retainer_minutes' => 300]];
        yield 'one-time cadence' => [['billing_cadence' => 'one_time']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('usageTerms')]
    #[Requirement('PKG-5')]
    public function test_automatic_issuing_is_refused_unless_the_package_is_fixed_price_and_recurring(array $overrides): void
    {
        $this->assertSame([PackageRefusal::AutoIssueNeedsFixedPrice], PackagePublishRules::refusals($this->terms(['invoice_issuing' => 'automatic', ...$overrides]), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['invoice_issuing' => 'draft', ...$overrides]), true));
    }

    #[Requirement('PKG-5')]
    public function test_a_zero_retainer_does_not_count_as_usage(): void
    {
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['invoice_issuing' => 'automatic', 'retainer_amount' => 0, 'retainer_minutes' => 0]), true));
    }

    public function test_autopay_needs_a_positive_limit(): void
    {
        $this->assertSame([PackageRefusal::AutopayNeedsLimit], PackagePublishRules::refusals($this->terms(['require_autopay' => true]), true));
        $this->assertSame([PackageRefusal::AutopayNeedsLimit], PackagePublishRules::refusals($this->terms(['require_autopay' => true, 'autopay_limit_amount' => 0]), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['require_autopay' => true, 'autopay_limit_amount' => 1]), true));
    }

    public function test_paying_before_activation_needs_something_charged_at_sign_up(): void
    {
        $this->assertSame([PackageRefusal::PaymentNeedsStripe, PackageRefusal::PayBeforeActivationNeedsCharge], PackagePublishRules::refusals($this->terms(['pay_before_activation' => true]), false));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['pay_before_activation' => true, 'charge_first_period' => true]), true));
    }

    public function test_charging_a_setup_fee_at_sign_up_needs_a_setup_fee_component(): void
    {
        $this->assertSame([PackageRefusal::SetupFeeAtSignupNeedsSetupFee], PackagePublishRules::refusals($this->terms(['charge_setup_fee_at_signup' => true]), true));
    }

    public function test_a_minimum_term_needs_a_recurring_cadence(): void
    {
        $this->assertSame([PackageRefusal::MinimumTermNeedsRecurringCadence], PackagePublishRules::refusals($this->terms(['billing_cadence' => 'one_time', 'minimum_term_months' => 6]), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['minimum_term_months' => 6]), true));
    }

    /** EDGE-5: the account's currencies are asked only of a package that takes money, and only when publishing. */
    #[Requirement('EDGE-5')]
    public function test_a_package_that_takes_money_needs_a_currency_the_stripe_account_can_charge(): void
    {
        $paid = $this->terms(['currency' => 'eur', 'charge_first_period' => true]);

        $this->assertSame([PackageRefusal::CurrencyNotChargeable], PackagePublishRules::refusals($paid, true, ['GBP', 'USD']));
        $this->assertSame([], PackagePublishRules::refusals($paid, true, ['EUR', 'USD']));
        $this->assertSame([], PackagePublishRules::refusals($paid, true), 'not asked: a page showing readiness');
        $this->assertSame([PackageRefusal::PaymentNeedsStripe], PackagePublishRules::refusals($paid, false, ['USD']), 'without Stripe, only that');
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['currency' => 'EUR']), true, ['USD']), 'a free package charges nothing');
    }

    public function test_charging_the_first_period_needs_a_recurring_cadence(): void
    {
        $this->assertSame([PackageRefusal::FirstPeriodNeedsRecurringCadence], PackagePublishRules::refusals($this->terms(['billing_cadence' => 'one_time', 'charge_first_period' => true]), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['billing_cadence' => 'quarterly', 'charge_first_period' => true]), true));
    }

    public function test_a_one_time_package_without_a_minimum_term_publishes(): void
    {
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['billing_cadence' => 'one_time']), true));
        $this->assertSame([], PackagePublishRules::refusals($this->terms(['billing_cadence' => 'one_time', 'minimum_term_months' => 0]), true));
    }

    public function test_every_refusal_that_applies_is_reported_in_a_stable_order(): void
    {
        $refusals = PackagePublishRules::refusals($this->terms([
            'components' => [], 'invoice_issuing' => 'automatic', 'billing_cadence' => 'one_time',
            'require_autopay' => true, 'pay_before_activation' => true, 'charge_setup_fee_at_signup' => true, 'minimum_term_months' => 3,
            'charge_first_period' => true,
        ]), false);

        $this->assertSame([
            PackageRefusal::NothingPriced, PackageRefusal::PaymentNeedsStripe, PackageRefusal::AutoIssueNeedsFixedPrice, PackageRefusal::AutopayNeedsLimit,
            PackageRefusal::SetupFeeAtSignupNeedsSetupFee, PackageRefusal::FirstPeriodNeedsRecurringCadence, PackageRefusal::MinimumTermNeedsRecurringCadence,
        ], $refusals);
    }
}
