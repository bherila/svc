<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\PaymentMethods\PaymentMethodManagement;
use App\Support\Billing\PaymentMethods\SetupIntentStatus;
use App\Support\Billing\PaymentMethods\SetupOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The portal's saved-method decisions (#642): pure, so no database. */
final class PortalPaymentMethodDecisionsTest extends TestCase
{
    /** @return array<string, array{string, SetupOutcome}> */
    public static function statuses(): array
    {
        return [
            'succeeded' => ['succeeded', SetupOutcome::Saved],
            'processing' => ['processing', SetupOutcome::Pending],
            'needs action' => ['requires_action', SetupOutcome::Pending],
            'needs confirmation' => ['requires_confirmation', SetupOutcome::Pending],
            'needs a method' => ['requires_payment_method', SetupOutcome::Failed],
            'canceled' => ['canceled', SetupOutcome::Failed],
            'unknown to us' => ['a_status_stripe_added', SetupOutcome::Pending],
            'empty' => ['', SetupOutcome::Pending],
        ];
    }

    #[DataProvider('statuses')]
    public function test_every_stripe_status_has_one_outcome(string $status, SetupOutcome $outcome): void
    {
        $this->assertSame($outcome, SetupIntentStatus::outcomeOf($status));
    }

    public function test_every_status_case_is_decided(): void
    {
        foreach (SetupIntentStatus::cases() as $case) {
            $this->assertSame($case->outcome(), SetupIntentStatus::outcomeOf($case->value));
        }
        $this->assertCount(6, SetupIntentStatus::cases());
    }

    public function test_each_outcome_says_what_happened(): void
    {
        $this->assertSame('Payment method saved.', SetupOutcome::Saved->message());
        $this->assertStringContainsString('still verifying', SetupOutcome::Pending->message());
        $this->assertStringContainsString('nothing was saved', SetupOutcome::Failed->message());
    }

    /** @return array<string, array{bool, bool, string|null, PaymentMethodManagement, bool, bool}> */
    public static function viewers(): array
    {
        return [
            'contact with Stripe and a key' => [true, true, 'pk_test_x', PaymentMethodManagement::Full, true, true],
            'contact with Stripe, no key' => [true, true, null, PaymentMethodManagement::ChangeOnly, false, true],
            'contact with Stripe, empty key' => [true, true, '', PaymentMethodManagement::ChangeOnly, false, true],
            'contact, Stripe not connected' => [true, false, 'pk_test_x', PaymentMethodManagement::Unavailable, false, false],
            'staff or scoped contact' => [false, true, 'pk_test_x', PaymentMethodManagement::NotPermitted, false, false],
            'not permitted wins over unavailable' => [false, false, null, PaymentMethodManagement::NotPermitted, false, false],
        ];
    }

    #[DataProvider('viewers')]
    public function test_what_a_viewer_may_do(bool $may, bool $connected, ?string $key, PaymentMethodManagement $expected, bool $add, bool $change): void
    {
        $decided = PaymentMethodManagement::decide($may, $connected, $key);

        $this->assertSame($expected, $decided);
        $this->assertSame($add, $decided->mayAdd());
        $this->assertSame($change, $decided->mayChange());
        $this->assertSame($decided === PaymentMethodManagement::Full, $decided->notice('Example Studio') === null);
    }

    public function test_the_notice_is_the_exact_sentence_the_reader_sees(): void
    {
        $this->assertNull(PaymentMethodManagement::Full->notice('Example Studio'));
        $this->assertSame(
            'Example Studio cannot take new payment methods right now. You can still remove one or choose another default.',
            PaymentMethodManagement::ChangeOnly->notice('Example Studio'),
        );
        $this->assertSame(
            'Only a contact with access to the whole company can add, remove or change payment methods.',
            PaymentMethodManagement::NotPermitted->notice('Example Studio'),
        );
        $this->assertSame(
            'Example Studio is not set up to take payments right now, so payment methods cannot be added or changed.',
            PaymentMethodManagement::Unavailable->notice('Example Studio'),
        );
    }
}
