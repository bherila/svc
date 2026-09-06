<?php

namespace App\Support\Billing;

use App\Services\Billing\ScheduleRefusalAuditor;

/**
 * How many invoices would stop a billing schedule, and for which reason.
 *
 * Counts only - never a row, an id, an invoice number, a company or a
 * workspace, for the same reason as {@see UnplaceableInvoiceCounts}: a caller
 * physically cannot leak an identifier through this type, so the console
 * command and anything else that renders it are safe against a database of real
 * client billing records without each having to be careful in its own way.
 *
 * The reason counts partition `wouldRefuse` - each row is attributed to the
 * first reason that would fire, in the order
 * {@see ScheduleRefusalAuditor} evaluates them - so they sum to it rather than
 * overlapping. `schedulesHalted` does not belong to that sum; it is the blast
 * radius, and it is the number an operator actually needs before a deployment.
 */
final readonly class ScheduleRefusalCounts
{
    public function __construct(
        public int $invoices,
        public int $candidates,
        public int $wouldRefuse,
        public int $danglingScheduleLink,
        public int $danglingAgreementLink,
        public int $contradictoryLineage,
        public int $unknownStatus,
        public int $incompletePeriodOnAnOwnedRow,
        public int $unattributedAndContested,
        public int $schedulesHalted,
        public int $schedules,
    ) {}

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     invoices: int,
     *     candidates: int,
     *     would_refuse_schedule_generation: int,
     *     dangling_schedule_link: int,
     *     dangling_agreement_link: int,
     *     contradictory_lineage: int,
     *     unknown_status: int,
     *     incomplete_period_on_an_owned_row: int,
     *     unattributed_and_contested: int,
     *     schedules_halted: int,
     *     schedules: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'invoices' => $this->invoices,
            'candidates' => $this->candidates,
            'would_refuse_schedule_generation' => $this->wouldRefuse,
            'dangling_schedule_link' => $this->danglingScheduleLink,
            'dangling_agreement_link' => $this->danglingAgreementLink,
            'contradictory_lineage' => $this->contradictoryLineage,
            'unknown_status' => $this->unknownStatus,
            'incomplete_period_on_an_owned_row' => $this->incompletePeriodOnAnOwnedRow,
            'unattributed_and_contested' => $this->unattributedAndContested,
            'schedules_halted' => $this->schedulesHalted,
            'schedules' => $this->schedules,
        ];
    }
}
