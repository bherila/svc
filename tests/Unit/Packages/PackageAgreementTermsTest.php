<?php

namespace Tests\Unit\Packages;

use App\Services\Packages\PackageAgreementTerms;
use App\Support\Packages\PackageTerms;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
