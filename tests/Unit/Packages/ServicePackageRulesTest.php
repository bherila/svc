<?php

namespace Tests\Unit\Packages;

use App\Support\Engagement\AgreementTermRules;
use App\Support\Packages\ServicePackageRules;
use ErrorException;
use PHPUnit\Framework\TestCase;

/**
 * The package rules as lists, read without a validator: what the website and
 * the agent API validate with, and what the contract's request schemas are
 * generated from.
 */
final class ServicePackageRulesTest extends TestCase
{
    public function test_a_link_name_is_required_to_create_and_only_when_sent_to_update(): void
    {
        $linkName = ['string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];

        $this->assertSame([
            'link_name' => ['required', ...$linkName],
            'is_listed' => ['sometimes', 'boolean'],
            'portal_addable' => ['sometimes', 'boolean'],
        ], ServicePackageRules::package(creating: true));
        $this->assertSame([
            'link_name' => ['sometimes', 'required', ...$linkName],
            'is_listed' => ['sometimes', 'boolean'],
            'portal_addable' => ['sometimes', 'boolean'],
        ], ServicePackageRules::package(creating: false));
    }

    public function test_every_term_rule_is_exactly_this_list(): void
    {
        $cadences = 'in:"monthly","quarterly","semi_annual","annual","one_time"';

        $this->assertSame([
            'name' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body_markdown' => ['nullable', 'string', 'max:20000'],
            'agreement_text' => ['nullable', 'string', 'max:30000'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'billing_cadence' => ['required', $cadences],
            'hourly_rate_amount' => ['nullable', 'integer', 'min:0'],
            'retainer_amount' => ['nullable', 'integer', 'min:0'],
            'retainer_minutes' => ['nullable', 'integer', 'min:0'],
            'rollover_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'first_cycle_proration' => ['nullable', 'in:"prorate_hours","full_period","align_next_cycle"'],
            'charge_setup_fee_at_signup' => ['boolean'],
            'charge_first_period' => ['boolean'],
            'require_autopay' => ['boolean'],
            'autopay_limit_amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'pay_before_activation' => ['boolean'],
            'payment_lapse_days' => ['integer', 'min:1', 'max:90'],
            'activation_mode' => ['required', 'in:"automatic","review"'],
            'invoice_issuing' => ['required', 'in:"automatic","draft"'],
            'notice_days' => ['integer', 'min:0', 'max:365'],
            'minimum_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'cancellation_mode' => ['required', 'in:"none","request","self_serve"'],
            'components' => ['array', 'max:30'],
            'components.*' => ['array:kind,description,cadence,amount,quantity,is_taxable'],
            'components.*.kind' => ['required', 'in:"recurring","setup_fee"'],
            'components.*.description' => ['required', 'string', 'max:500'],
            'components.*.cadence' => ['nullable', $cadences],
            'components.*.amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'components.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'components.*.is_taxable' => ['nullable', 'boolean'],
            'intake_questions' => ['array', 'max:12'],
            'intake_questions.*' => ['array:key,label,kind,required'],
            'intake_questions.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'intake_questions.*.label' => ['required', 'string', 'max:200'],
            'intake_questions.*.kind' => ['required', 'in:"short_text","paragraph"'],
            'intake_questions.*.required' => ['nullable', 'boolean'],
        ], array_map(self::strings(...), ServicePackageRules::terms()));
    }

    public function test_the_term_keys_a_caller_sends_are_the_top_level_terms_as_a_list(): void
    {
        $this->assertSame([
            'name', 'summary', 'body_markdown', 'agreement_text', 'currency', 'billing_cadence',
            'hourly_rate_amount', 'retainer_amount', 'retainer_minutes', 'rollover_months', 'first_cycle_proration',
            'charge_setup_fee_at_signup', 'charge_first_period', 'require_autopay', 'autopay_limit_amount',
            'pay_before_activation', 'payment_lapse_days', 'activation_mode', 'invoice_issuing',
            'notice_days', 'minimum_term_months', 'cancellation_mode', 'components', 'intake_questions',
        ], ServicePackageRules::termKeys());
    }

    public function test_agreement_term_constraints_are_shared_as_plain_lists(): void
    {
        $this->assertSame(['monthly', 'quarterly', 'semi_annual', 'annual', 'one_time'], AgreementTermRules::cadences());
        $this->assertSame([
            'agreement_text' => ['string', 'max:30000'],
            'billing_cadence' => ['in:"monthly","quarterly","semi_annual","annual","one_time"'],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'hourly_rate_amount' => ['integer', 'min:0'],
            'retainer_amount' => ['integer', 'min:0'],
            'retainer_minutes' => ['integer', 'min:0'],
            'rollover_months' => ['integer', 'min:0', 'max:120'],
            'first_cycle_proration' => ['in:"prorate_hours","full_period","align_next_cycle"'],
        ], array_map(self::strings(...), AgreementTermRules::constraints()));
        $this->assertSame(['sometimes', 'required', 'integer', 'min:0'], AgreementTermRules::rule('retainer_minutes', 'sometimes', 'required'));
        // Presence passed by name is still a list.
        $this->assertSame(['nullable', 'integer', 'min:0'], AgreementTermRules::rule('retainer_minutes', presence: 'nullable'));
    }

    public function test_question_keys_leave_every_other_term_alone(): void
    {
        $this->assertSame([
            'name' => 'Synthetic plan',
            'intake_questions' => [['label' => 'Domain', 'key' => 'domain']],
            'currency' => 'USD',
        ], ServicePackageRules::withQuestionKeys([
            'name' => 'Synthetic plan',
            'intake_questions' => [['label' => 'Domain']],
            'currency' => 'USD',
        ]));
    }

    public function test_a_derived_key_is_cut_to_thirty_six_characters_and_never_empty(): void
    {
        $this->assertSame(['an_extraordinarily_long_question_abo', 'question'], array_column(ServicePackageRules::withQuestionKeys(['intake_questions' => [
            ['label' => 'An extraordinarily long question about the website address'],
            ['label' => '???'],
        ]])['intake_questions'], 'key'));
    }

    /** Keys are derived before validation, so malformed input must reach the validator untouched and quietly. */
    public function test_input_without_a_question_list_passes_through_untouched(): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });
        try {
            $this->assertSame(['name' => 'X', 'currency' => 'USD'], ServicePackageRules::withQuestionKeys(['name' => 'X', 'currency' => 'USD']));
            $this->assertSame(['name' => 'X', 'intake_questions' => 'not a list'], ServicePackageRules::withQuestionKeys(['name' => 'X', 'intake_questions' => 'not a list']));
            $this->assertSame(['intake_questions' => ['not a question']], ServicePackageRules::withQuestionKeys(['intake_questions' => ['not a question']]));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<string>
     */
    private static function strings(array $rules): array
    {
        return array_map(static fn (mixed $rule): string => is_string($rule) ? $rule : (string) $rule, $rules);
    }
}
