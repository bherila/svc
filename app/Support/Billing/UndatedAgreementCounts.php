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
 * See {@see UndatedAgreementAuditor} for how the exact selected count is
 * checked against its inexpensive candidate bounds.
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
        public int $entriesSelectedByAnUndatedAgreement,
        public int $billedLinesOnAnUndatedAgreement,
    ) {}

    /**
     * Whether work is certainly being priced by an agreement the rest of the
     * system treats as not in force.
     *
     * The exact resolver-selected count, named rather than leaving callers to
     * interpret the cheaper candidate bounds independently.
     */
    public function isLive(): bool
    {
        return $this->entriesSelectedByAnUndatedAgreement > 0;
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
     *     entries_selected_by_an_undated_agreement: int,
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
            'entries_selected_by_an_undated_agreement' => $this->entriesSelectedByAnUndatedAgreement,
            'billed_lines_on_an_undated_agreement' => $this->billedLinesOnAnUndatedAgreement,
        ];
    }
}
