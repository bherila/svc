<?php

namespace Tests\Unit\Billing\Autopay;

use App\Support\Billing\Autopay\AutopayIntentCheck;
use App\Support\Billing\Autopay\AutopayVerifiedIntent;
use App\Support\Billing\InvoicePaymentStatus;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Whether a PaymentIntent is the charge a held attempt would have made (#641): pure, no database. */
final class AutopayIntentCheckTest extends TestCase
{
    private const string WORKSPACE = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const string INVOICE = 'bbbbbbbb-0000-4000-8000-000000000002';

    private const string ATTEMPT = 'cccccccc-0000-4000-8000-000000000003';

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function intent(array $override = []): array
    {
        return $override + [
            'id' => 'pi_synthetic_held',
            'amount' => 375_000,
            'currency' => 'usd',
            'status' => 'succeeded',
            'metadata' => ['workspace_public_id' => self::WORKSPACE, 'invoice_public_id' => self::INVOICE, 'autopay_attempt_public_id' => self::ATTEMPT],
        ];
    }

    /** @param array<string, mixed> $intent */
    private static function verify(array $intent, string $requested = 'pi_synthetic_held'): AutopayVerifiedIntent
    {
        return AutopayIntentCheck::verify($intent, $requested, self::WORKSPACE, self::INVOICE, self::ATTEMPT, 375_000, 'USD');
    }

    public function test_the_attempts_own_succeeded_intent_is_recorded_as_succeeded(): void
    {
        $verified = self::verify(self::intent());

        $this->assertSame(['pi_synthetic_held', 375_000, 'USD', InvoicePaymentStatus::Succeeded], [$verified->id, $verified->amount, $verified->currency, $verified->status]);
    }

    public function test_currency_is_compared_and_kept_in_upper_case_whatever_the_case_either_side_uses(): void
    {
        $verified = AutopayIntentCheck::verify(self::intent(['currency' => 'USD']), 'pi_synthetic_held', self::WORKSPACE, self::INVOICE, self::ATTEMPT, 375_000, 'usd');

        $this->assertSame('USD', $verified->currency);
    }

    public function test_a_processing_intent_is_recorded_as_pending(): void
    {
        $this->assertSame(InvoicePaymentStatus::Pending, self::verify(self::intent(['status' => 'processing']))->status);
    }

    public function test_an_intent_with_no_attempt_in_its_metadata_still_matches_on_workspace_invoice_amount_and_currency(): void
    {
        $metadata = ['workspace_public_id' => self::WORKSPACE, 'invoice_public_id' => self::INVOICE];

        $this->assertSame('pi_synthetic_held', self::verify(self::intent(['metadata' => $metadata]))->id);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mismatches(): iterable
    {
        $metadata = ['workspace_public_id' => self::WORKSPACE, 'invoice_public_id' => self::INVOICE, 'autopay_attempt_public_id' => self::ATTEMPT];
        yield 'another intent id' => [['id' => 'pi_synthetic_other'], 'different PaymentIntent'];
        yield 'another workspace' => [['metadata' => ['workspace_public_id' => 'dddddddd-0000-4000-8000-000000000004'] + $metadata], 'does not belong to this workspace'];
        yield 'no workspace' => [['metadata' => ['invoice_public_id' => self::INVOICE]], 'does not belong to this workspace'];
        yield 'another invoice' => [['metadata' => ['invoice_public_id' => 'dddddddd-0000-4000-8000-000000000004'] + $metadata], 'not created for this invoice'];
        yield 'no metadata' => [['metadata' => []], 'does not belong to this workspace'];
        yield 'another attempt' => [['metadata' => ['autopay_attempt_public_id' => 'dddddddd-0000-4000-8000-000000000004'] + $metadata], 'different autopay attempt'];
        yield 'another amount' => [['amount' => 375_001], 'not for the amount'];
        yield 'an amount that is not a number' => [['amount' => '375000'], 'not for the amount'];
        yield 'another currency' => [['currency' => 'eur'], 'not in the currency'];
        yield 'no currency' => [['currency' => null], 'not in the currency'];
        yield 'needing a payment method' => [['status' => 'requires_payment_method'], 'has not charged the client (Stripe says it is requires_payment_method)'];
        yield 'needing the client' => [['status' => 'requires_action'], 'requires_action'];
        yield 'canceled' => [['status' => 'canceled'], 'canceled'];
        yield 'authorised but not captured' => [['status' => 'requires_capture'], 'requires_capture'];
        yield 'a status that is not readable' => [['status' => null], 'unreadable'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('mismatches')]
    public function test_an_intent_that_is_not_the_attempts_charge_is_refused_with_the_reason(array $override, string $reason): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');

        self::verify(array_replace(self::intent(), $override));
    }

    public function test_the_id_pattern_accepts_a_payment_intent_id_and_nothing_else(): void
    {
        foreach (['pi_3Abc123', 'pi_synthetic_held', 'pi_'.str_repeat('a', 252)] as $good) {
            $this->assertSame(1, preg_match(AutopayIntentCheck::ID_PATTERN, $good), $good);
        }
        foreach (['pi_', 'pi', 'ch_3Abc123', 'PI_3Abc', 'pi_3Abc 123', "pi_3Abc\n", 'pi_'.str_repeat('a', 253), 'pi_3Abc-123'] as $bad) {
            $this->assertSame(0, preg_match(AutopayIntentCheck::ID_PATTERN, $bad), $bad);
        }
    }
}
