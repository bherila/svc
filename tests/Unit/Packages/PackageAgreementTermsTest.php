<?php

namespace Tests\Unit\Packages;

use App\Services\Packages\PackageAgreementTerms;
use App\Support\Packages\PackageTerms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

/**
 * The agreement fields a package version sets (PKG-7), read without a
 * database: the version's own terms, under the agreement's names.
 */
#[CoversClass(PackageAgreementTerms::class)]
final class PackageAgreementTermsTest extends TestCase
{
    #[Requirement('PKG-7', 'CAN-1')]
    public function test_a_version_sets_every_agreement_term_including_its_cancellation_terms(): void
    {
        $terms = PackageTerms::fromArray([
            ...PackageTerms::defaults('USD'),
            'name' => 'Care plan',
            'agreement_text' => 'Terms text',
            'billing_cadence' => 'quarterly',
            'hourly_rate_amount' => 14000,
            'retainer_amount' => 90000,
            'retainer_minutes' => 600,
            'rollover_months' => 2,
            'first_cycle_proration' => 'full_period',
            // Not the defaults, so each is seen to come from the version.
            'notice_days' => 45,
            'minimum_term_months' => 6,
            'cancellation_mode' => 'self_serve',
        ]);

        $this->assertSame([
            'title' => 'Care plan',
            'agreement_text' => 'Terms text',
            'billing_cadence' => 'quarterly',
            'currency' => 'USD',
            'hourly_rate_amount' => 14000,
            'retainer_amount' => 30000,
            'retainer_minutes' => 200,
            'period_retainer_amount' => 90000,
            'period_retainer_minutes' => 600,
            'rollover_months' => 2,
            'first_cycle_proration' => 'full_period',
            'notice_days' => 45,
            'minimum_term_months' => 6,
            'cancellation_mode' => 'self_serve',
        ], PackageAgreementTerms::agreementTerms($terms));
    }

    /**
     * A setup fee is skipped on a continuing service, and on a sign-up that
     * already charged it on the sign-up invoice; otherwise it is billed once
     * with the first cycle (ACC-3). Recurring components are always kept.
     *
     * @return array<string, array{bool, bool, bool}> chargeSetupFeeAtSignup, continuing, setup fee kept
     */
    public static function setupFeeCases(): array
    {
        return [
            'sign-up, not charged at sign-up: kept as a one-time item' => [false, false, true],
            'sign-up, charged at sign-up: skipped' => [true, false, false],
            'continuing, not charged at sign-up: skipped' => [false, true, false],
            'continuing, charged at sign-up: skipped' => [true, true, false],
        ];
    }

    #[DataProvider('setupFeeCases')]
    #[Requirement('ACC-3')]
    public function test_a_setup_fee_is_a_recurring_item_only_when_neither_sign_up_nor_a_move_already_covers_it(bool $atSignup, bool $continuing, bool $kept): void
    {
        $terms = $this->termsWithSetupFee($atSignup);
        $expected = $kept ? ['Onboarding', 'Care'] : ['Care'];

        $this->assertSame($expected, array_column(PackageAgreementTerms::recurringItems($terms, $continuing), 'description'));
    }

    #[Requirement('ACC-3')]
    public function test_the_continuing_argument_defaults_to_a_new_sign_up(): void
    {
        $items = PackageAgreementTerms::recurringItems($this->termsWithSetupFee(false));

        $this->assertSame(['Onboarding', 'Care'], array_column($items, 'description'));
        $this->assertSame([
            'description' => 'Onboarding',
            'cadence' => 'one_time',
            'amount' => 5000,
            'quantity' => '1.000',
            'is_taxable' => true,
        ], $items[0]);
    }

    private function termsWithSetupFee(bool $chargeAtSignup): PackageTerms
    {
        return PackageTerms::fromArray([
            ...PackageTerms::defaults('USD'),
            'name' => 'Care plan',
            'charge_setup_fee_at_signup' => $chargeAtSignup,
            'components' => [
                // The fee first: skipping it must not stop the walk.
                ['kind' => 'setup_fee', 'description' => 'Onboarding', 'amount' => 5000, 'is_taxable' => true],
                ['kind' => 'recurring', 'description' => 'Care', 'cadence' => 'monthly', 'amount' => 10000],
            ],
        ]);
    }
}
