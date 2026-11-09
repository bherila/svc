<?php

declare(strict_types=1);

namespace App\Support\Packages;

use App\Models\ServicePackageComponent;
use App\Models\ServicePackageVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\ChargeCadence;
use App\Support\Billing\FirstCycleProration;

/**
 * Everything one package version says, as one immutable value.
 *
 * The single reading of a version: the workflow writes a version from it, the
 * API and pages present it, the publish rules judge it, and acceptance (a later
 * slice) copies its agreement terms onto an agreement. Amounts are integer
 * minor units in `currency`; durations are whole minutes, days or months.
 */
final readonly class PackageTerms
{
    /**
     * @param  list<PackageComponentTerms>  $components
     * @param  list<IntakeQuestion>  $intakeQuestions
     */
    public function __construct(
        public string $name,
        public ?string $summary,
        public ?string $bodyMarkdown,
        public ?string $agreementText,
        public string $currency,
        public ChargeCadence $billingCadence,
        public ?int $hourlyRateAmount,
        public ?int $retainerAmount,
        public ?int $retainerMinutes,
        public ?int $rolloverMonths,
        public ?FirstCycleProration $firstCycleProration,
        public bool $chargeSetupFeeAtSignup,
        public bool $chargeFirstPeriod,
        public bool $requireAutopay,
        public ?int $autopayLimitAmount,
        public bool $payBeforeActivation,
        public int $paymentLapseDays,
        public PackageActivationMode $activationMode,
        public PackageInvoiceIssuing $invoiceIssuing,
        public int $noticeDays,
        public ?int $minimumTermMonths,
        public CancellationMode $cancellationMode,
        public array $components,
        public array $intakeQuestions,
    ) {}

    /**
     * From validated input (StoreServicePackageRequest's rules).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        /** @var list<array{kind: string, description: string, cadence?: string|null, amount: int|string, quantity?: int|float|string|null, is_taxable?: bool|null}> $components */
        $components = array_values($input['components'] ?? []);
        /** @var list<array{key: string, label: string, kind: string, required?: bool|null}> $questions */
        $questions = array_values($input['intake_questions'] ?? []);

        return new self(
            name: trim(self::string($input, 'name')),
            summary: self::text($input['summary'] ?? null),
            bodyMarkdown: self::text($input['body_markdown'] ?? null),
            agreementText: self::text($input['agreement_text'] ?? null),
            currency: strtoupper(self::string($input, 'currency')),
            billingCadence: ChargeCadence::from(self::string($input, 'billing_cadence')),
            hourlyRateAmount: self::int($input['hourly_rate_amount'] ?? null),
            retainerAmount: self::int($input['retainer_amount'] ?? null),
            retainerMinutes: self::int($input['retainer_minutes'] ?? null),
            rolloverMonths: self::int($input['rollover_months'] ?? null),
            firstCycleProration: isset($input['first_cycle_proration']) ? FirstCycleProration::from(self::string($input, 'first_cycle_proration')) : null,
            chargeSetupFeeAtSignup: (bool) ($input['charge_setup_fee_at_signup'] ?? false),
            chargeFirstPeriod: (bool) ($input['charge_first_period'] ?? false),
            requireAutopay: (bool) ($input['require_autopay'] ?? false),
            autopayLimitAmount: self::int($input['autopay_limit_amount'] ?? null),
            payBeforeActivation: (bool) ($input['pay_before_activation'] ?? false),
            paymentLapseDays: (int) ($input['payment_lapse_days'] ?? 14),
            activationMode: PackageActivationMode::from(self::string($input, 'activation_mode', PackageActivationMode::Review->value)),
            invoiceIssuing: PackageInvoiceIssuing::from(self::string($input, 'invoice_issuing', PackageInvoiceIssuing::Draft->value)),
            noticeDays: (int) ($input['notice_days'] ?? 0),
            minimumTermMonths: self::int($input['minimum_term_months'] ?? null),
            cancellationMode: CancellationMode::from(self::string($input, 'cancellation_mode', CancellationMode::Request->value)),
            components: array_map(PackageComponentTerms::fromArray(...), $components),
            intakeQuestions: array_map(IntakeQuestion::fromArray(...), $questions),
        );
    }

    public static function fromVersion(ServicePackageVersion $version): self
    {
        return self::fromArray([
            ...$version->only([
                'name', 'summary', 'body_markdown', 'agreement_text', 'currency', 'billing_cadence',
                'hourly_rate_amount', 'retainer_amount', 'retainer_minutes', 'rollover_months', 'first_cycle_proration',
                'charge_setup_fee_at_signup', 'charge_first_period', 'require_autopay', 'autopay_limit_amount',
                'pay_before_activation', 'payment_lapse_days', 'activation_mode', 'invoice_issuing',
                'notice_days', 'minimum_term_months', 'cancellation_mode',
            ]),
            'intake_questions' => $version->intake_questions ?? [],
            'components' => $version->components->map(static fn (ServicePackageComponent $component): array => [
                'kind' => $component->kind,
                'description' => $component->description,
                'cadence' => $component->cadence,
                'amount' => $component->amount,
                'quantity' => $component->quantity,
                'is_taxable' => $component->is_taxable,
            ])->all(),
        ]);
    }

    /**
     * The version columns this value writes, components and questions aside.
     *
     * @return array<string, mixed>
     */
    public function versionAttributes(): array
    {
        return [
            'name' => $this->name,
            'summary' => $this->summary,
            'body_markdown' => $this->bodyMarkdown,
            'agreement_text' => $this->agreementText,
            'currency' => $this->currency,
            'billing_cadence' => $this->billingCadence->value,
            'hourly_rate_amount' => $this->hourlyRateAmount,
            'retainer_amount' => $this->retainerAmount,
            'retainer_minutes' => $this->retainerMinutes,
            'rollover_months' => $this->rolloverMonths,
            'first_cycle_proration' => $this->firstCycleProration?->value,
            'charge_setup_fee_at_signup' => $this->chargeSetupFeeAtSignup,
            'charge_first_period' => $this->chargeFirstPeriod,
            'require_autopay' => $this->requireAutopay,
            'autopay_limit_amount' => $this->autopayLimitAmount,
            'pay_before_activation' => $this->payBeforeActivation,
            'payment_lapse_days' => $this->paymentLapseDays,
            'activation_mode' => $this->activationMode->value,
            'invoice_issuing' => $this->invoiceIssuing->value,
            'notice_days' => $this->noticeDays,
            'minimum_term_months' => $this->minimumTermMonths,
            'cancellation_mode' => $this->cancellationMode->value,
            'intake_questions' => array_map(static fn (IntakeQuestion $question): array => $question->toArray(), $this->intakeQuestions),
        ];
    }

    /**
     * Every term, components included, in the shape fromArray() reads.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->versionAttributes(),
            'components' => array_map(static fn (PackageComponentTerms $component): array => $component->toArray(), $this->components),
        ];
    }

    /**
     * What a new package starts with before the caller's own terms.
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $currency): array
    {
        return [
            'currency' => $currency,
            'billing_cadence' => ChargeCadence::Monthly->value,
            'charge_setup_fee_at_signup' => false,
            'charge_first_period' => false,
            'require_autopay' => false,
            'pay_before_activation' => false,
            'payment_lapse_days' => 14,
            'activation_mode' => PackageActivationMode::Review->value,
            'invoice_issuing' => PackageInvoiceIssuing::Draft->value,
            'notice_days' => 30,
            'cancellation_mode' => CancellationMode::Request->value,
            'components' => [],
            'intake_questions' => [],
        ];
    }

    public function isRecurring(): bool
    {
        return BillingCadence::tryFrom($this->billingCadence->value) !== null;
    }

    /** Whether any term bills by usage: an hourly rate or a retainer (whose overage does). */
    public function billsByUsage(): bool
    {
        return $this->hourlyRateAmount !== null || self::positive($this->retainerAmount) || self::positive($this->retainerMinutes);
    }

    public function setupFeeTotal(): int
    {
        return array_sum(array_map(
            static fn (PackageComponentTerms $component): int => $component->total(),
            array_filter($this->components, static fn (PackageComponentTerms $component): bool => $component->kind === PackageComponentKind::SetupFee),
        ));
    }

    public function hasSetupFee(): bool
    {
        foreach ($this->components as $component) {
            if ($component->kind === PackageComponentKind::SetupFee) {
                return true;
            }
        }

        return false;
    }

    /** Whether sign-up charges anything now: the setup fee or the first period. */
    public function chargesAtSignup(): bool
    {
        return ($this->chargeSetupFeeAtSignup && $this->setupFeeTotal() > 0) || $this->chargeFirstPeriod;
    }

    /** Whether sign-up involves the payment processor at all (PKG-4). */
    public function involvesPayment(): bool
    {
        return $this->chargesAtSignup() || $this->requireAutopay || $this->payBeforeActivation;
    }

    private static function positive(?int $value): bool
    {
        return $value !== null && $value > 0;
    }

    /**
     * A field the rules hold to a string (an enum value, a currency code, the
     * name), from validated input or a version's columns.
     *
     * @param  array<string, mixed>  $input
     */
    private static function string(array $input, string $key, ?string $default = null): string
    {
        $value = $input[$key] ?? $default;

        // @infection-ignore-all Equivalent: the validator and the version's columns only ever hold a string (or nothing, read as empty) here; the cast tells static analysis what the rules guarantee about mixed input.
        return (string) $value;
    }

    private static function text(mixed $value): ?string
    {
        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
