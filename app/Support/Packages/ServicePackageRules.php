<?php

namespace App\Support\Packages;

use App\Support\Engagement\AgreementTermRules;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation for package input, shared by the website and the agent API.
 *
 * Term rules are always applied to a *complete* set of terms: an update is
 * merged onto the stored draft first ({@see ServicePackageWorkflow::updateDraft}),
 * and the merged whole is validated, so a partial update cannot leave a
 * version that a full one would have refused. Agreement terms take their
 * constraints from {@see AgreementTermRules}, the same list agreements use.
 */
final class ServicePackageRules
{
    public const LINK_NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const MAX_COMPONENTS = 30;

    public const MAX_QUESTIONS = 12;

    /**
     * Package-level fields: those that belong to the package, not a version.
     *
     * @return array<string, list<mixed>>
     */
    public static function package(bool $creating): array
    {
        $presence = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'link_name' => [...$presence, 'string', 'min:2', 'max:64', 'regex:'.self::LINK_NAME_PATTERN],
            'is_listed' => ['sometimes', 'boolean'],
            'portal_addable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Every term of a version, applied to a complete set.
     *
     * @return array<string, list<mixed>>
     */
    public static function terms(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body_markdown' => ['nullable', 'string', 'max:20000'],
            'agreement_text' => AgreementTermRules::rule('agreement_text', 'nullable'),
            'currency' => AgreementTermRules::rule('currency', 'required'),
            'billing_cadence' => AgreementTermRules::rule('billing_cadence', 'required'),
            'hourly_rate_amount' => AgreementTermRules::rule('hourly_rate_amount', 'nullable'),
            'retainer_amount' => AgreementTermRules::rule('retainer_amount', 'nullable'),
            'retainer_minutes' => AgreementTermRules::rule('retainer_minutes', 'nullable'),
            'rollover_months' => AgreementTermRules::rule('rollover_months', 'nullable'),
            'first_cycle_proration' => AgreementTermRules::rule('first_cycle_proration', 'nullable'),
            'charge_setup_fee_at_signup' => ['boolean'],
            'charge_first_period' => ['boolean'],
            'require_autopay' => ['boolean'],
            'autopay_limit_amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'pay_before_activation' => ['boolean'],
            'payment_lapse_days' => ['integer', 'min:1', 'max:90'],
            'activation_mode' => ['required', Rule::enum(PackageActivationMode::class)],
            'invoice_issuing' => ['required', Rule::enum(PackageInvoiceIssuing::class)],
            'notice_days' => AgreementTermRules::rule('notice_days'),
            'minimum_term_months' => AgreementTermRules::rule('minimum_term_months', 'nullable'),
            'cancellation_mode' => AgreementTermRules::rule('cancellation_mode', 'required'),
            'components' => ['array', 'max:'.self::MAX_COMPONENTS],
            'components.*' => ['array:kind,description,cadence,amount,quantity,is_taxable'],
            'components.*.kind' => ['required', Rule::enum(PackageComponentKind::class)],
            'components.*.description' => ['required', 'string', 'max:500'],
            'components.*.cadence' => ['nullable', Rule::in(AgreementTermRules::cadences())],
            'components.*.amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'components.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'components.*.is_taxable' => ['nullable', 'boolean'],
            'intake_questions' => ['array', 'max:'.self::MAX_QUESTIONS],
            'intake_questions.*' => ['array:key,label,kind,required'],
            'intake_questions.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'intake_questions.*.label' => ['required', 'string', 'max:200'],
            'intake_questions.*.kind' => ['required', Rule::enum(IntakeQuestionKind::class)],
            'intake_questions.*.required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The term keys a caller may send.
     *
     * @return list<string>
     */
    public static function termKeys(): array
    {
        return array_values(array_filter(array_keys(self::terms()), static fn (string $key): bool => ! str_contains($key, '.')));
    }

    /**
     * Give every intake question a stable key before validation.
     *
     * A caller may omit a question's key; it is derived from the label, and
     * made unique within the list, so an answer can be stored against it.
     *
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    public static function withQuestionKeys(array $terms): array
    {
        if (! isset($terms['intake_questions']) || ! is_array($terms['intake_questions'])) {
            return $terms;
        }

        $taken = [];
        foreach ($terms['intake_questions'] as $question) {
            if (is_array($question) && is_string($question['key'] ?? null)) {
                $taken[] = $question['key'];
            }
        }

        foreach ($terms['intake_questions'] as $index => $question) {
            if (! is_array($question) || is_string($question['key'] ?? null)) {
                continue;
            }
            $base = Str::limit(Str::slug(is_string($question['label'] ?? null) ? $question['label'] : '', '_'), 36, '') ?: 'question';
            $key = $base;
            for ($suffix = 2; in_array($key, $taken, true); $suffix++) {
                $key = $base.'_'.$suffix;
            }
            $taken[] = $key;
            $terms['intake_questions'][$index]['key'] = $key;
        }

        return $terms;
    }
}
