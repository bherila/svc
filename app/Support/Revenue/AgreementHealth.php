<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Carbon\CarbonImmutable;

/**
 * One agreement's retainer use this period, from the capacity ledger, and
 * the two badges the client roster shows (docs/ux-overhaul.md section 3).
 */
final readonly class AgreementHealth
{
    /** The ledger was read for this period. */
    public const string OK = 'ok';

    /** The agreement grants no retainer hours, so there is nothing to meter. */
    public const string NO_RETAINER = 'no_retainer';

    /** The ledger refused to build (an unplaceable charged invoice, for one); the figures are withheld, not zero. */
    public const string LEDGER_UNREADABLE = 'ledger_unreadable';

    public function __construct(
        public ProjectedAgreement $agreement,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public ?float $hoursUsed,
        public ?float $hoursIncluded,
        public ?bool $overRetainer,
        public bool $endsWithin60Days,
        public string $ledgerStatus,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'agreement_id' => $this->agreement->publicId,
            'title' => $this->agreement->title,
            'starts_on' => $this->agreement->startsOn->toDateString(),
            'ends_on' => $this->agreement->effectiveEnd()?->toDateString(),
            'period_start' => $this->periodStart->toDateString(),
            'period_end' => $this->periodEnd->toDateString(),
            'hours_used' => $this->hoursUsed,
            'hours_included' => $this->hoursIncluded,
            'over_retainer' => $this->overRetainer,
            'ends_within_60_days' => $this->endsWithin60Days,
            'ledger_status' => $this->ledgerStatus,
        ];
    }
}
