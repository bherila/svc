<?php

namespace App\Support\Revenue;

use App\Services\Revenue\ProjectionInputsRepository;
use App\Services\Revenue\RecurringRevenueProjector;
use Carbon\CarbonImmutable;

/**
 * Everything {@see RecurringRevenueProjector} needs,
 * read once by {@see ProjectionInputsRepository} and
 * detached from persistence.
 *
 * - the agreements that can still bill (their terms and recurring items);
 * - each agreement's billed-overage amount by service month over the
 *   trailing window, from one grouped aggregate over charged invoices;
 * - the scheduled charges in the projection window that a charged invoice
 *   already carries, so a charge sold in advance is counted once, as actual.
 */
final readonly class ProjectionInputs
{
    /**
     * @param  list<ProjectedAgreement>  $agreements
     * @param  array<int, array<string, int>>  $overageHistory  minor units by agreement id, then `Y-m`
     * @param  array<string, true>  $billedCharges  keys from {@see retainerKey()} and {@see itemKey()}
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public array $agreements,
        public array $overageHistory = [],
        public array $billedCharges = [],
    ) {}

    public static function retainerKey(int $agreementId, string $date): string
    {
        return 'retainer:'.$agreementId.':'.$date;
    }

    public static function itemKey(int $itemId, string $date): string
    {
        return 'item:'.$itemId.':'.$date;
    }

    public function isBilled(string $key): bool
    {
        return isset($this->billedCharges[$key]);
    }

    /** The same inputs narrowed to one client. */
    public function forClient(int $clientId): self
    {
        $agreements = array_values(array_filter($this->agreements, static fn (ProjectedAgreement $agreement): bool => $agreement->clientId === $clientId));
        $ids = array_flip(array_map(static fn (ProjectedAgreement $agreement): int => $agreement->id, $agreements));

        return new self($this->asOf, $agreements, array_intersect_key($this->overageHistory, $ids), $this->billedCharges);
    }

    /** First day of the current month: the first month the projection covers. */
    public function windowStart(): CarbonImmutable
    {
        return $this->asOf->startOfMonth()->startOfDay();
    }

    /** Last day of the twelfth full month after the current one. */
    public function windowEnd(): CarbonImmutable
    {
        return $this->asOf->startOfMonth()->addMonthsNoOverflow(RevenueAssumptions::PROJECTION_MONTHS)->endOfMonth()->startOfDay();
    }
}
