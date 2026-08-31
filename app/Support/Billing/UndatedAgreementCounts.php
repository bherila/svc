<?php

namespace App\Support\Billing;

use App\Services\Billing\UndatedAgreementAuditor;

/**
 * How many agreements carry no start date, and how much work they price.
 *
 * Counts only - never a row, a title, a company or a workspace. That is a
 * property of this type rather than of the code that renders it, so the console
 * command and any later operator screen are both safe against a database of
 * real client records without each having to be careful in its own way.
 *
 * The status and cadence breakdowns are keyed by enum-shaped column values,
 * which name states rather than records, so they carry nothing identifying
 * either.
 *
 * See {@see UndatedAgreementAuditor} for why the entry counts are a bracket
 * rather than a single number.
 */
final readonly class UndatedAgreementCounts
{
    /**
     * @param  array<string, int>  $byStatus
     * @param  array<string, int>  $byCadence
     */
    public function __construct(
        public int $agreements,
        public int $undated,
        public array $byStatus,
        public array $byCadence,
        public int $hourlyOnly,
        public int $withRetainerTerms,
        public int $entriesWithAnUndatedCandidate,
        public int $entriesWithNoOtherCandidate,
        public int $billedLinesOnAnUndatedAgreement,
    ) {}

    /**
     * Whether work is certainly being priced by an agreement the rest of the
     * system treats as not in force.
     *
     * The lower bound, named rather than left for callers to pick a field, so
     * that everything deciding whether #147 is live asks the same question
     * instead of each choosing a different one. False does not mean nothing is
     * at risk - it means nothing is provably affected, which is what makes the
     * upper bound worth reading alongside it.
     */
    public function isLive(): bool
    {
        return $this->entriesWithNoOtherCandidate > 0;
    }

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     agreements: int,
     *     undated: int,
     *     by_status: array<string, int>,
     *     by_cadence: array<string, int>,
     *     hourly_only: int,
     *     with_retainer_terms: int,
     *     entries_with_an_undated_candidate: int,
     *     entries_with_no_other_candidate: int,
     *     billed_lines_on_an_undated_agreement: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'agreements' => $this->agreements,
            'undated' => $this->undated,
            'by_status' => $this->byStatus,
            'by_cadence' => $this->byCadence,
            'hourly_only' => $this->hourlyOnly,
            'with_retainer_terms' => $this->withRetainerTerms,
            'entries_with_an_undated_candidate' => $this->entriesWithAnUndatedCandidate,
            'entries_with_no_other_candidate' => $this->entriesWithNoOtherCandidate,
            'billed_lines_on_an_undated_agreement' => $this->billedLinesOnAnUndatedAgreement,
        ];
    }
}
