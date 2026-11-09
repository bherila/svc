<?php

namespace Tests\Unit\Packages;

use App\Support\Billing\ChargeCadence;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\ServicePackageRules;
use PHPUnit\Framework\TestCase;
use Tests\Support\Requirement;

final class PackageTermsTest extends TestCase
{
    #[Requirement('PKG-3')]
    public function test_terms_round_trip_through_their_array_form_in_minor_units(): void
    {
        $input = [
            ...PackageTerms::defaults('USD'),
            'name' => 'Hosting + Care plan',
            'summary' => 'Hosting plus hours',
            'currency' => 'usd',
            'hourly_rate_amount' => '14000',
            'retainer_amount' => 60000,
            'retainer_minutes' => 300,
            'rollover_months' => 2,
            'first_cycle_proration' => 'full_period',
            'minimum_term_months' => 6,
            'components' => [
                ['kind' => 'recurring', 'description' => ' Hosting ', 'cadence' => 'monthly', 'amount' => 4500, 'quantity' => 2],
                ['kind' => 'setup_fee', 'description' => 'Setup', 'cadence' => 'monthly', 'amount' => 25000],
            ],
            'intake_questions' => [['key' => 'domain', 'label' => 'Domain', 'kind' => 'short_text', 'required' => true]],
        ];

        $terms = PackageTerms::fromArray($input);

        $this->assertSame('USD', $terms->currency);
        $this->assertSame(14000, $terms->hourlyRateAmount);
        $this->assertSame(300, $terms->retainerMinutes);
        $this->assertSame('Hosting', $terms->components[0]->description);
        $this->assertSame('2.000', $terms->components[0]->quantity);
        $this->assertSame(9000, $terms->components[0]->total());
        $this->assertEquals($terms, PackageTerms::fromArray($terms->toArray()));
    }

    public function test_a_setup_fee_is_always_charged_once(): void
    {
        $terms = PackageTerms::fromArray([...PackageTerms::defaults('USD'), 'name' => 'X', 'components' => [['kind' => 'setup_fee', 'description' => 'Setup', 'cadence' => 'monthly', 'amount' => 100]]]);

        $this->assertSame(PackageComponentKind::SetupFee, $terms->components[0]->kind);
        $this->assertSame(ChargeCadence::OneTime, $terms->components[0]->cadence);
        $this->assertSame(100, $terms->setupFeeTotal());
    }

    public function test_blank_text_is_stored_as_nothing(): void
    {
        $terms = PackageTerms::fromArray([...PackageTerms::defaults('USD'), 'name' => ' X ', 'summary' => '   ', 'agreement_text' => '']);

        $this->assertSame('X', $terms->name);
        $this->assertNull($terms->summary);
        $this->assertNull($terms->agreementText);
    }

    public function test_question_keys_are_derived_from_labels_and_kept_unique(): void
    {
        $terms = ServicePackageRules::withQuestionKeys(['intake_questions' => [
            ['key' => 'website_address', 'label' => 'Anything'],
            ['label' => 'Website address'],
            ['label' => 'Website address'],
            ['label' => '???'],
        ]]);

        $this->assertSame(['website_address', 'website_address_2', 'website_address_3', 'question'], array_column($terms['intake_questions'], 'key'));
    }
}
