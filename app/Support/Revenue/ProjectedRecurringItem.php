<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\RecurringItemBiller;
use App\Support\Billing\ChargeCadence;
use Carbon\CarbonImmutable;

/**
 * One agreement recurring item as the projection reads it: its own cadence,
 * anchor, effective and expiry dates and pre-tax price, and nothing else.
 */
final readonly class ProjectedRecurringItem
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $description,
        public ?string $cadence,
        public ?int $anchorMonth,
        public ?int $anchorDay,
        public ?CarbonImmutable $effectiveOn,
        public ?CarbonImmutable $expiresOn,
        public int $amount,
        public ?string $quantity,
        public bool $isTaxable,
        public bool $isActive,
        public ?string $currency = null,
    ) {}

    public static function fromModel(ClientAgreementRecurringItem $item): self
    {
        return new self(
            id: $item->id,
            publicId: $item->public_id,
            description: $item->description,
            cadence: $item->cadence,
            anchorMonth: $item->anchor_month,
            anchorDay: $item->anchor_day,
            effectiveOn: $item->effective_on,
            expiresOn: $item->expires_on,
            amount: (int) ($item->amount ?? 0),
            quantity: $item->quantity,
            isTaxable: $item->is_taxable,
            isActive: $item->is_active,
            currency: $item->currency,
        );
    }

    /** The cadence the biller matches on; null for a value no case names. */
    private function chargeCadence(): ?ChargeCadence
    {
        return ChargeCadence::tryFrom($this->cadence ?? '');
    }

    /** Minor units for one incidence, quantity included and tax excluded, as the biller prices it. */
    public function incidenceAmount(): int
    {
        return (int) round($this->amount * (float) ($this->quantity ?? 1));
    }

    /**
     * The incidence amount spread over a month: quarterly ÷ 3, semi-annual
     * ÷ 6, annual ÷ 12, and nothing for a one-time charge, which recurs in no
     * month (docs/ux-overhaul.md section 3).
     */
    public function monthlyEquivalent(): int
    {
        $cadence = $this->chargeCadence();
        if ($cadence === null) {
            return 0;
        }

        return match ($cadence) {
            ChargeCadence::Monthly => $this->incidenceAmount(),
            ChargeCadence::Quarterly => (int) round($this->incidenceAmount() / 3),
            ChargeCadence::SemiAnnual => (int) round($this->incidenceAmount() / 6),
            ChargeCadence::Annual => (int) round($this->incidenceAmount() / 12),
            ChargeCadence::OneTime => 0,
        };
    }

    /**
     * Is the item charging on this date? A deactivated item stops; an item
     * with no effective date starts with its agreement, as the biller reads it.
     */
    public function isInForceOn(CarbonImmutable $date, CarbonImmutable $agreementStartsOn): bool
    {
        $start = $this->effectiveOn ?? $agreementStartsOn;

        return $this->isActive
            && $start->startOfDay()->lte($date)
            && ($this->expiresOn === null || $this->expiresOn->startOfDay()->gte($date));
    }

    /**
     * An unsaved model the existing {@see RecurringItemBiller}
     * can place, so the projection uses its incidence rules rather than a copy.
     */
    public function toBillerModel(int $workspaceId, int $agreementId): ClientAgreementRecurringItem
    {
        $item = new ClientAgreementRecurringItem;
        $item->setRawAttributes([
            'id' => $this->id,
            'public_id' => $this->publicId,
            'workspace_id' => $workspaceId,
            'client_agreement_id' => $agreementId,
            'description' => $this->description,
            'cadence' => $this->cadence,
            'anchor_month' => $this->anchorMonth,
            'anchor_day' => $this->anchorDay,
            'effective_on' => $this->effectiveOn?->toDateString(),
            'expires_on' => $this->expiresOn?->toDateString(),
            'quantity' => $this->quantity,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'is_taxable' => $this->isTaxable,
            'is_active' => $this->isActive,
        ]);
        $item->syncOriginal();

        return $item;
    }
}
