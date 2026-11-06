<?php

namespace App\Support\Revenue;

use App\Contracts\RetainerAgreementTerms;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RecurringItemBiller;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Carbon\CarbonImmutable;

/**
 * One agreement's terms as the revenue projection reads them, detached from
 * persistence so the projector and its tests need no database.
 *
 * It answers the retainer arithmetic's questions exactly as
 * {@see ClientAgreement} does, so {@see RetainerCalculator}
 * and {@see BillingCycleResolver} price it unchanged.
 */
final readonly class ProjectedAgreement implements RetainerAgreementTerms
{
    /** Statuses whose agreement has stopped governing anything later. */
    private const array ENDED_STATUSES = ['terminated', 'expired'];

    /**
     * @param  list<ProjectedRecurringItem>  $recurringItems
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public int $clientId,
        public string $clientPublicId,
        public string $clientName,
        public string $title,
        public string $status,
        public ?string $currency,
        public ?string $billingCadence,
        public ?string $firstCycleProration,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public ?CarbonImmutable $terminatedAt,
        public ?CarbonImmutable $signedAt,
        public ?int $retainerMinutes,
        public ?int $retainerAmount,
        public ?int $periodRetainerMinutes,
        public ?int $periodRetainerAmount,
        public array $recurringItems = [],
    ) {}

    /** Built from a loaded agreement whose company and items were read through its workspace. */
    public static function fromModel(ClientAgreement $agreement, string $clientPublicId, string $clientName): self
    {
        $items = $agreement->relationLoaded('recurringItems') ? $agreement->recurringItems : collect();

        return new self(
            id: (int) $agreement->getKey(),
            publicId: $agreement->public_id,
            workspaceId: (int) $agreement->getAttribute('workspace_id'),
            clientId: (int) $agreement->getAttribute('client_company_id'),
            clientPublicId: $clientPublicId,
            clientName: $clientName,
            title: (string) $agreement->getAttribute('title'),
            status: $agreement->status,
            currency: $agreement->getAttribute('currency'),
            billingCadence: $agreement->billing_cadence,
            firstCycleProration: $agreement->getAttribute('first_cycle_proration'),
            startsOn: $agreement->starts_on,
            endsOn: $agreement->ends_on,
            terminatedAt: $agreement->terminated_at,
            signedAt: $agreement->signed_at,
            retainerMinutes: $agreement->getAttribute('retainer_minutes'),
            retainerAmount: $agreement->getAttribute('retainer_amount'),
            periodRetainerMinutes: $agreement->getAttribute('period_retainer_minutes'),
            periodRetainerAmount: $agreement->getAttribute('period_retainer_amount'),
            recurringItems: array_values($items->map(static fn (ClientAgreementRecurringItem $item): ProjectedRecurringItem => ProjectedRecurringItem::fromModel($item))->all()),
        );
    }

    /**
     * The day the agreement stops. `ends_on` when recorded; an agreement
     * already terminated or expired without one ended when it was terminated,
     * or - with no record of when - at its start, so nothing is projected for
     * an ended agreement nobody dated. No renewal is ever assumed.
     */
    public function effectiveEnd(): ?CarbonImmutable
    {
        if ($this->endsOn !== null) {
            return $this->endsOn->startOfDay();
        }
        if (! in_array($this->status, self::ENDED_STATUSES, true)) {
            return null;
        }

        return ($this->terminatedAt ?? $this->startsOn)->startOfDay();
    }

    public function isInForceOn(CarbonImmutable $date): bool
    {
        $end = $this->effectiveEnd();

        return $this->startsOn->startOfDay()->lte($date) && ($end === null || $end->gte($date->startOfDay()));
    }

    /** Does the agreement cover any day of [$start, $end]? */
    public function coversAnyOf(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $agreementEnd = $this->effectiveEnd();

        return $this->startsOn->startOfDay()->lte($end) && ($agreementEnd === null || $agreementEnd->gte($start));
    }

    /**
     * As {@see ClientAgreement::billsOnARecurringCadence()}: an unset cadence
     * still bills monthly, a `one_time` arrangement never recurs.
     */
    public function billsOnARecurringCadence(): bool
    {
        $cadence = (string) ($this->billingCadence ?? '');

        return $cadence === '' || BillingCadence::tryFrom($cadence) !== null;
    }

    public function hasRetainerFee(): bool
    {
        return $this->periodRetainerFee() > 0;
    }

    /** The retainer fee spread over a month, in minor units: the period fee over the cycle's months. */
    public function monthlyRetainerEquivalent(): int
    {
        return (int) round($this->periodRetainerFee() * 100 / $this->effectiveBillingCadence()->monthsInCycle());
    }

    /** Retainer plus every recurring item in force on the date, each spread over a month. */
    public function monthlyRecurringOn(CarbonImmutable $date): int
    {
        if (! $this->billsOnARecurringCadence() || ! $this->isInForceOn($date)) {
            return 0;
        }

        $total = $this->monthlyRetainerEquivalent();
        foreach ($this->recurringItems as $item) {
            if ($item->isInForceOn($date, $this->startsOn)) {
                $total += $item->monthlyEquivalent();
            }
        }

        return $total;
    }

    /**
     * An unsaved agreement carrying unsaved items, for
     * {@see RecurringItemBiller}, which reads models.
     */
    public function toBillerModel(): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes([
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'client_company_id' => $this->clientId,
            'starts_on' => $this->startsOn->toDateString(),
            'ends_on' => $this->effectiveEnd()?->toDateString(),
            'billing_cadence' => $this->billingCadence,
        ]);
        $agreement->syncOriginal();
        $agreement->setRelation('recurringItems', collect(array_map(
            fn (ProjectedRecurringItem $item): ClientAgreementRecurringItem => $item->toBillerModel($this->workspaceId, $this->id),
            $this->recurringItems,
        )));

        return $agreement;
    }

    public function effectiveBillingCadence(): BillingCadence
    {
        return BillingCadence::tryFrom((string) $this->billingCadence) ?? BillingCadence::Monthly;
    }

    public function effectiveFirstCycleProration(): FirstCycleProration
    {
        return FirstCycleProration::tryFrom((string) $this->firstCycleProration) ?? FirstCycleProration::ProrateHours;
    }

    public function retainerStartsOn(): CarbonImmutable
    {
        return $this->startsOn;
    }

    public function retainerEndsOn(): ?CarbonImmutable
    {
        return $this->effectiveEnd();
    }

    public function retainerMonthlyHours(): float
    {
        return ((int) ($this->retainerMinutes ?? 0)) / 60;
    }

    public function retainerMonthlyFee(): float
    {
        return ((int) ($this->retainerAmount ?? 0)) / 100;
    }

    public function periodRetainerHoursOverride(): ?float
    {
        return $this->periodRetainerMinutes === null ? null : $this->periodRetainerMinutes / 60;
    }

    public function periodRetainerFeeOverride(): ?float
    {
        return $this->periodRetainerAmount === null ? null : $this->periodRetainerAmount / 100;
    }

    public function periodRetainerHours(): float
    {
        return $this->periodRetainerHoursOverride() ?? $this->retainerMonthlyHours() * $this->effectiveBillingCadence()->monthsInCycle();
    }

    public function retainerHoursPerMonth(): float
    {
        $override = $this->periodRetainerHoursOverride();

        return $override === null
            ? $this->retainerMonthlyHours()
            : round($override / max(1, $this->effectiveBillingCadence()->monthsInCycle()), 4);
    }

    public function periodRetainerFee(): float
    {
        return $this->periodRetainerFeeOverride() ?? $this->retainerMonthlyFee() * $this->effectiveBillingCadence()->monthsInCycle();
    }
}
