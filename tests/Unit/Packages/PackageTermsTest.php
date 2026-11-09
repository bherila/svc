<?php

namespace Tests\Unit\Packages;

use App\Support\Billing\ChargeCadence;
use App\Support\Packages\IntakeQuestion;
use App\Support\Packages\IntakeQuestionKind;
use App\Support\Packages\PackageComponentKind;
use App\Support\Packages\PackageComponentTerms;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\ServicePackageRules;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // Two of a label first: a suffix that is not counted shows here, before three of them could loop on it.
        $this->assertSame(['website_address', 'website_address_2'], array_column(ServicePackageRules::withQuestionKeys(['intake_questions' => [
            ['label' => 'Website address'],
            ['label' => 'Website address'],
        ]])['intake_questions'], 'key'));

        $terms = ServicePackageRules::withQuestionKeys(['intake_questions' => [
            ['key' => 'website_address', 'label' => 'Anything'],
            ['label' => 'Website address'],
            ['label' => 'Website address'],
            ['label' => '???'],
        ]]);

        $this->assertSame(['website_address', 'website_address_2', 'website_address_3', 'question'], array_column($terms['intake_questions'], 'key'));
    }

    public function test_omitted_terms_take_their_fallbacks_without_a_warning(): void
    {
        $terms = self::withoutWarnings(static fn (): array => PackageTerms::fromArray(['name' => 'Synthetic plan', 'currency' => 'usd', 'billing_cadence' => 'one_time'])->toArray());

        $this->assertSame([
            'name' => 'Synthetic plan',
            'summary' => null,
            'body_markdown' => null,
            'agreement_text' => null,
            'currency' => 'USD',
            'billing_cadence' => 'one_time',
            'hourly_rate_amount' => null,
            'retainer_amount' => null,
            'retainer_minutes' => null,
            'rollover_months' => null,
            'first_cycle_proration' => null,
            'charge_setup_fee_at_signup' => false,
            'charge_first_period' => false,
            'require_autopay' => false,
            'autopay_limit_amount' => null,
            'pay_before_activation' => false,
            'payment_lapse_days' => 14,
            'activation_mode' => 'review',
            'invoice_issuing' => 'draft',
            'notice_days' => 0,
            'minimum_term_months' => null,
            'cancellation_mode' => 'request',
            'intake_questions' => [],
            'components' => [],
        ], $terms);
    }

    /** Validated input keeps a form's strings ('1', '45'); every term reads as its type, and lists as lists. */
    #[Requirement('PKG-3')]
    public function test_every_sent_term_is_read_as_its_type(): void
    {
        $terms = PackageTerms::fromArray([
            'name' => ' Synthetic care ',
            'summary' => ' Care plus hours ',
            'body_markdown' => '**Synthetic**',
            'agreement_text' => ' Synthetic agreement ',
            'currency' => 'eur',
            'billing_cadence' => 'quarterly',
            'hourly_rate_amount' => '14000',
            'retainer_amount' => '60000',
            'retainer_minutes' => '300',
            'rollover_months' => '',
            'first_cycle_proration' => 'align_next_cycle',
            'charge_setup_fee_at_signup' => '1',
            'charge_first_period' => '1',
            'require_autopay' => '1',
            'autopay_limit_amount' => '90000',
            'pay_before_activation' => '1',
            'payment_lapse_days' => '7',
            'activation_mode' => 'automatic',
            'invoice_issuing' => 'automatic',
            'notice_days' => '45',
            'minimum_term_months' => '6',
            'cancellation_mode' => 'self_serve',
            'components' => [
                'first' => ['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'quarterly', 'amount' => '4500'],
                'second' => ['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 25000],
            ],
            'intake_questions' => ['only' => ['key' => 'domain', 'label' => 'Domain', 'kind' => 'paragraph', 'required' => '1']],
        ]);

        $version = [
            'name' => 'Synthetic care',
            'summary' => 'Care plus hours',
            'body_markdown' => '**Synthetic**',
            'agreement_text' => 'Synthetic agreement',
            'currency' => 'EUR',
            'billing_cadence' => 'quarterly',
            'hourly_rate_amount' => 14000,
            'retainer_amount' => 60000,
            'retainer_minutes' => 300,
            'rollover_months' => null,
            'first_cycle_proration' => 'align_next_cycle',
            'charge_setup_fee_at_signup' => true,
            'charge_first_period' => true,
            'require_autopay' => true,
            'autopay_limit_amount' => 90000,
            'pay_before_activation' => true,
            'payment_lapse_days' => 7,
            'activation_mode' => 'automatic',
            'invoice_issuing' => 'automatic',
            'notice_days' => 45,
            'minimum_term_months' => 6,
            'cancellation_mode' => 'self_serve',
            'intake_questions' => [['key' => 'domain', 'label' => 'Domain', 'kind' => 'paragraph', 'required' => true]],
        ];
        $this->assertSame($version, $terms->versionAttributes());
        $this->assertSame([...$version, 'components' => [
            ['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'quarterly', 'amount' => 4500, 'quantity' => '1.000', 'is_taxable' => false],
            ['kind' => 'setup_fee', 'description' => 'Setup', 'cadence' => 'one_time', 'amount' => 25000, 'quantity' => '1.000', 'is_taxable' => false],
        ]], $terms->toArray());
        $this->assertTrue(array_is_list($terms->components));
        $this->assertTrue(array_is_list($terms->intakeQuestions));
    }

    public function test_a_new_package_starts_from_these_defaults(): void
    {
        $this->assertSame([
            'currency' => 'GBP',
            'billing_cadence' => 'monthly',
            'charge_setup_fee_at_signup' => false,
            'charge_first_period' => false,
            'require_autopay' => false,
            'pay_before_activation' => false,
            'payment_lapse_days' => 14,
            'activation_mode' => 'review',
            'invoice_issuing' => 'draft',
            'notice_days' => 30,
            'cancellation_mode' => 'request',
            'components' => [],
            'intake_questions' => [],
        ], PackageTerms::defaults('GBP'));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function usage(): iterable
    {
        yield 'nothing by usage' => [[], false];
        yield 'an hourly rate, even of nothing' => [['hourly_rate_amount' => 0], true];
        yield 'an empty retainer' => [['retainer_amount' => 0, 'retainer_minutes' => 0], false];
        yield 'a retainer amount' => [['retainer_amount' => 1], true];
        yield 'retainer minutes' => [['retainer_minutes' => 1], true];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('usage')]
    public function test_only_an_hourly_rate_or_a_positive_retainer_bills_by_usage(array $overrides, bool $expected): void
    {
        $this->assertSame($expected, PackageTerms::fromArray([...PackageTerms::defaults('USD'), 'name' => 'X', ...$overrides])->billsByUsage());
    }

    public function test_the_setup_fee_total_counts_setup_fees_alone(): void
    {
        $terms = PackageTerms::fromArray([...PackageTerms::defaults('USD'), 'name' => 'X', 'components' => [
            ['kind' => 'recurring', 'description' => 'Hosting', 'amount' => 4500],
            ['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 15000, 'quantity' => 2],
        ]]);

        $this->assertSame(30000, $terms->setupFeeTotal());
        $this->assertTrue($terms->hasSetupFee());
    }

    public function test_a_component_reads_form_strings_and_defaults_what_was_not_sent(): void
    {
        $sent = PackageComponentTerms::fromArray(['kind' => 'recurring', 'description' => ' Care ', 'cadence' => 'annual', 'amount' => '333', 'quantity' => '1.5', 'is_taxable' => true]);
        $this->assertSame(['kind' => 'recurring', 'description' => 'Care', 'cadence' => 'annual', 'amount' => 333, 'quantity' => '1.500', 'is_taxable' => true], $sent->toArray());

        $unsent = PackageComponentTerms::fromArray(['kind' => 'recurring', 'description' => 'Care', 'amount' => 333]);
        $this->assertSame(['kind' => 'recurring', 'description' => 'Care', 'cadence' => 'monthly', 'amount' => 333, 'quantity' => '1.000', 'is_taxable' => false], $unsent->toArray());

        $this->assertTrue(PackageTerms::fromArray([...PackageTerms::defaults('USD'), 'name' => 'X', 'components' => [
            ['kind' => 'recurring', 'description' => 'Care', 'amount' => 333, 'is_taxable' => '1'],
        ]])->components[0]->isTaxable);
    }

    /** @return iterable<string, array{string, int}> */
    public static function totals(): iterable
    {
        yield 'a half rounds up' => ['1.5', 500];
        yield 'a quarter rounds down' => ['1.25', 416];
        yield 'a whole quantity is exact' => ['3', 999];
    }

    #[DataProvider('totals')]
    public function test_a_component_total_is_amount_times_quantity_rounded_half_up(string $quantity, int $total): void
    {
        $this->assertSame($total, PackageComponentTerms::fromArray(['kind' => 'recurring', 'description' => 'Care', 'amount' => 333, 'quantity' => $quantity])->total());
    }

    public function test_an_intake_question_trims_its_label_and_is_optional_unless_marked(): void
    {
        $this->assertEquals(new IntakeQuestion('domain', 'Domain', IntakeQuestionKind::ShortText, false), IntakeQuestion::fromArray(['key' => 'domain', 'label' => ' Domain ', 'kind' => 'short_text']));
        $this->assertTrue(IntakeQuestion::fromArray(['key' => 'domain', 'label' => 'Domain', 'kind' => 'short_text', 'required' => true])->required);
        $this->assertSame(['key' => 'domain', 'label' => 'Domain', 'kind' => 'paragraph', 'required' => false], IntakeQuestion::fromArray(['key' => 'domain', 'label' => 'Domain', 'kind' => 'paragraph', 'required' => false])->toArray());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private static function withoutWarnings(callable $read): mixed
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }
}
