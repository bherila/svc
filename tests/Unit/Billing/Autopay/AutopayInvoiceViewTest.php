<?php

namespace Tests\Unit\Billing\Autopay;

use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use App\Support\Billing\Autopay\AutopayInvoiceView;
use App\Support\Billing\Autopay\AutopayVerdict;
use App\Support\Billing\InvoicePaymentStatus;
use PHPUnit\Framework\TestCase;

final class AutopayInvoiceViewTest extends TestCase
{
    public function test_an_attempt_decides_what_a_reader_is_told(): void
    {
        $this->assertSame(AutopayInvoiceState::Skipped, AutopayInvoiceView::classify(AutopayVerdict::Charge, AutopayAttemptState::Skipped, null));
        $this->assertSame(AutopayInvoiceState::Failed, AutopayInvoiceView::classify(AutopayVerdict::Charge, AutopayAttemptState::Failed, null));
        $this->assertSame(AutopayInvoiceState::NeedsReview, AutopayInvoiceView::classify(AutopayVerdict::Charge, AutopayAttemptState::Claimed, null));
        $this->assertSame(AutopayInvoiceState::Processing, AutopayInvoiceView::classify(AutopayVerdict::AlreadyAttempted, AutopayAttemptState::Submitted, null));
    }

    public function test_a_submitted_charge_is_read_from_its_payment(): void
    {
        $expected = [
            InvoicePaymentStatus::Pending->value => AutopayInvoiceState::Processing,
            InvoicePaymentStatus::Succeeded->value => AutopayInvoiceState::Charged,
            InvoicePaymentStatus::Refunded->value => AutopayInvoiceState::Charged,
            InvoicePaymentStatus::Disputed->value => AutopayInvoiceState::Charged,
            InvoicePaymentStatus::Failed->value => AutopayInvoiceState::Failed,
            InvoicePaymentStatus::Canceled->value => AutopayInvoiceState::Failed,
        ];
        foreach (InvoicePaymentStatus::cases() as $status) {
            $this->assertSame($expected[$status->value], AutopayInvoiceView::classify(AutopayVerdict::AlreadyAttempted, AutopayAttemptState::Submitted, $status), $status->value);
        }
    }

    public function test_before_any_attempt_the_due_date_verdict_is_shown(): void
    {
        $expected = [
            AutopayVerdict::Charge->value => AutopayInvoiceState::WillCharge,
            AutopayVerdict::NotYetDue->value => AutopayInvoiceState::WillCharge,
            AutopayVerdict::PaymentInFlight->value => AutopayInvoiceState::WillCharge,
            AutopayVerdict::OverLimit->value => AutopayInvoiceState::OverLimit,
            AutopayVerdict::NoDefaultMethod->value => AutopayInvoiceState::NoDefaultMethod,
        ];
        foreach (AutopayVerdict::cases() as $verdict) {
            $this->assertSame($expected[$verdict->value] ?? AutopayInvoiceState::NotApplicable, AutopayInvoiceView::classify($verdict, null, null), $verdict->value);
        }
    }

    public function test_every_state_has_words(): void
    {
        foreach (AutopayInvoiceState::cases() as $state) {
            $this->assertNotSame('', $state->label());
        }
        $this->assertSame('Not autopaid: over limit', AutopayInvoiceState::OverLimit->label());
    }
}
