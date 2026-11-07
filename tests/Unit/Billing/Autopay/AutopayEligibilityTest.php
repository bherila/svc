<?php

namespace Tests\Unit\Billing\Autopay;

use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayEligibility;
use App\Support\Billing\Autopay\AutopayFacts;
use App\Support\Billing\Autopay\AutopayVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every refusal and every boundary of the pure autopay decision (#636); no database. */
final class AutopayEligibilityTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function facts(array $overrides = []): AutopayFacts
    {
        return new AutopayFacts(...[
            'invoiceStatus' => 'issued',
            'balance' => 375_000,
            'invoiceCurrency' => 'USD',
            'limitCurrency' => 'USD',
            'visibleToClient' => true,
            'dueOn' => '2030-12-01',
            'today' => '2030-12-01',
            'autopayEnabledOn' => '2030-10-03',
            'autopayOn' => true,
            'limit' => 500_000,
            'hasDefaultMethod' => true,
            'priorAttempt' => null,
            'paymentInFlight' => false,
            'squareCollects' => false,
            'stripeCanCharge' => true,
            ...$overrides,
        ]);
    }

    public function test_an_issued_invoice_due_today_within_the_limit_is_charged(): void
    {
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts()));
        $this->assertTrue(AutopayVerdict::Charge->charges());
    }

    public function test_only_charge_charges(): void
    {
        foreach (AutopayVerdict::cases() as $verdict) {
            $this->assertSame($verdict === AutopayVerdict::Charge, $verdict->charges(), $verdict->value);
        }
    }

    public function test_a_partially_paid_invoice_is_charged_its_remaining_balance(): void
    {
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['invoiceStatus' => 'partially_paid', 'balance' => 100])));
    }

    #[DataProvider('statuses')]
    public function test_only_a_collectible_status_is_charged(string $status, AutopayVerdict $expected): void
    {
        $this->assertSame($expected, AutopayEligibility::decide(self::facts(['invoiceStatus' => $status])));
    }

    /** @return iterable<string, array{string, AutopayVerdict}> */
    public static function statuses(): iterable
    {
        yield 'draft' => ['draft', AutopayVerdict::NotCollectible];
        yield 'paid' => ['paid', AutopayVerdict::NotCollectible];
        yield 'void' => ['void', AutopayVerdict::NotCollectible];
        yield 'disputed' => ['disputed', AutopayVerdict::NotCollectible];
        yield 'unknown' => ['mystery', AutopayVerdict::NotCollectible];
        yield 'issued' => ['issued', AutopayVerdict::Charge];
        yield 'partially paid' => ['partially_paid', AutopayVerdict::Charge];
    }

    public function test_a_settled_balance_is_not_charged(): void
    {
        $this->assertSame(AutopayVerdict::NoBalance, AutopayEligibility::decide(self::facts(['balance' => 0])));
        $this->assertSame(AutopayVerdict::NoBalance, AutopayEligibility::decide(self::facts(['balance' => -1])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['balance' => 1])));
    }

    public function test_an_invoice_hidden_from_the_client_is_not_charged(): void
    {
        $this->assertSame(AutopayVerdict::HiddenFromClient, AutopayEligibility::decide(self::facts(['visibleToClient' => false])));
    }

    public function test_the_limit_is_inclusive_at_the_cent(): void
    {
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['balance' => 499_999])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['balance' => 500_000])));
        $this->assertSame(AutopayVerdict::OverLimit, AutopayEligibility::decide(self::facts(['balance' => 500_001])));
    }

    public function test_the_limit_replaces_any_other_cap(): void
    {
        // Far above the $1,000 online-payment cap, and charged.
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['balance' => 375_000, 'limit' => 400_000])));
    }

    public function test_autopay_off_or_without_a_limit_charges_nothing(): void
    {
        $this->assertSame(AutopayVerdict::AutopayOff, AutopayEligibility::decide(self::facts(['autopayOn' => false])));
        $this->assertSame(AutopayVerdict::AutopayOff, AutopayEligibility::decide(self::facts(['limit' => null])));
    }

    public function test_due_dates_are_compared_on_the_workspace_calendar(): void
    {
        $this->assertSame(AutopayVerdict::NotYetDue, AutopayEligibility::decide(self::facts(['dueOn' => '2030-12-02', 'today' => '2030-12-01'])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['dueOn' => '2030-12-01', 'today' => '2030-12-01'])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['dueOn' => '2030-11-30', 'today' => '2030-12-01', 'autopayEnabledOn' => '2030-11-30'])));
        $this->assertSame(AutopayVerdict::NoDueDate, AutopayEligibility::decide(self::facts(['dueOn' => null])));
    }

    public function test_autopay_never_reaches_back_to_an_invoice_that_fell_due_before_it_was_turned_on(): void
    {
        $this->assertSame(AutopayVerdict::DueBeforeAutopay, AutopayEligibility::decide(self::facts(['dueOn' => '2030-10-02', 'autopayEnabledOn' => '2030-10-03'])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['dueOn' => '2030-10-03', 'autopayEnabledOn' => '2030-10-03'])));
        $this->assertSame(AutopayVerdict::DueBeforeAutopay, AutopayEligibility::decide(self::facts(['autopayEnabledOn' => null])));
    }

    public function test_any_prior_attempt_blocks_a_second(): void
    {
        $this->assertSame(AutopayVerdict::Skipped, AutopayEligibility::decide(self::facts(['priorAttempt' => AutopayAttemptState::Skipped])));
        foreach ([AutopayAttemptState::Claimed, AutopayAttemptState::Submitted, AutopayAttemptState::Failed, AutopayAttemptState::NotCharged] as $state) {
            $this->assertSame(AutopayVerdict::AlreadyAttempted, AutopayEligibility::decide(self::facts(['priorAttempt' => $state])), $state->value);
        }
    }

    public function test_a_payment_already_moving_is_waited_for(): void
    {
        $this->assertSame(AutopayVerdict::PaymentInFlight, AutopayEligibility::decide(self::facts(['paymentInFlight' => true])));
    }

    public function test_square_and_the_connection_decide_whether_stripe_may_charge(): void
    {
        $this->assertSame(AutopayVerdict::SquareCollects, AutopayEligibility::decide(self::facts(['squareCollects' => true])));
        $this->assertSame(AutopayVerdict::ConnectionUnavailable, AutopayEligibility::decide(self::facts(['stripeCanCharge' => false])));
    }

    public function test_a_limit_in_another_currency_is_not_applied_to_the_invoice(): void
    {
        $this->assertSame(AutopayVerdict::CurrencyMismatch, AutopayEligibility::decide(self::facts(['limitCurrency' => 'EUR'])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['invoiceCurrency' => 'usd'])));
        $this->assertSame(AutopayVerdict::Charge, AutopayEligibility::decide(self::facts(['limitCurrency' => 'usd'])));
    }

    public function test_no_default_method_is_not_charged_and_outranks_the_limit(): void
    {
        $this->assertSame(AutopayVerdict::NoDefaultMethod, AutopayEligibility::decide(self::facts(['hasDefaultMethod' => false])));
        $this->assertSame(AutopayVerdict::NoDefaultMethod, AutopayEligibility::decide(self::facts(['hasDefaultMethod' => false, 'balance' => 900_000])));
    }

    public function test_the_due_date_view_answers_the_question_before_the_day(): void
    {
        $early = self::facts(['dueOn' => '2030-12-15', 'today' => '2030-12-01', 'balance' => 720_000]);
        $this->assertSame(AutopayVerdict::NotYetDue, AutopayEligibility::decide($early));
        $this->assertSame(AutopayVerdict::OverLimit, AutopayEligibility::decide($early->onDueDate()));
    }
}
