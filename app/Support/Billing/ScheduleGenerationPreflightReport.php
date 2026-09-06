<?php

namespace App\Support\Billing;

use App\Services\Billing\ScheduleGenerationPreflight;

/**
 * What would happen if every due billing schedule ran right now.
 *
 * Counts only - never a row, an id, an invoice number, a company or a
 * workspace. That is a property of this type rather than of the code that
 * renders it: a caller physically cannot leak an identifier through it, so the
 * console command and anything else that consumes it are safe against a
 * database of real client billing records without each having to be careful in
 * its own way.
 *
 * The unit is a **schedule**, not a row, because that is the unit of damage.
 * `generateDue()` stops at its first undecidable period, so a schedule halts
 * once however many bad rows its client holds, and ten broken invoices in one
 * company stop one schedule. See {@see ScheduleGenerationPreflight} for how the
 * classification is obtained.
 */
final readonly class ScheduleGenerationPreflightReport
{
    /**
     * @param  array<string, int>  $refusalsByReason  keyed by {@see PeriodRefusalReason} value, every case present
     */
    public function __construct(
        public int $schedules,
        public int $schedulesDue,
        public int $periodsClassified,
        public int $wouldHalt,
        public int $haltedByARefusal,
        public int $haltedByAPendingDraft,
        public int $schedulesTruncated,
        public array $refusalsByReason,
    ) {}

    /**
     * Every reason present with a zero, so a consumer can diff two runs without
     * having to decide whether a missing key means zero or means the vocabulary
     * changed.
     *
     * @return array{
     *     schedules: int,
     *     schedules_due: int,
     *     periods_classified: int,
     *     would_halt: int,
     *     halted_by_a_refusal: int,
     *     halted_by_a_pending_draft: int,
     *     schedules_truncated: int,
     *     refusals_by_reason: array<string, int>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schedules' => $this->schedules,
            'schedules_due' => $this->schedulesDue,
            'periods_classified' => $this->periodsClassified,
            'would_halt' => $this->wouldHalt,
            'halted_by_a_refusal' => $this->haltedByARefusal,
            'halted_by_a_pending_draft' => $this->haltedByAPendingDraft,
            'schedules_truncated' => $this->schedulesTruncated,
            'refusals_by_reason' => $this->refusalsByReason,
        ];
    }
}
