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
 *
 * ## A report is clean, halting, or inconclusive - and they are three things
 *
 * `wouldHalt === 0` is not by itself a pass. The preflight examines a bounded
 * number of periods per schedule, and a schedule it stopped short of examining
 * is not evidence of anything: its unexamined periods may be the ones that
 * halt. So {@see self::isConclusive()} is a separate question from
 * {@see self::wouldHalt}, and anything gating a deployment has to ask both.
 * Reporting truncation only as a count, and letting the pass hinge on
 * `wouldHalt` alone, is exactly how a gate certifies what it declined to look
 * at.
 */
final readonly class ScheduleGenerationPreflightReport
{
    /**
     * @param  array<string, int>  $refusalsByReason  keyed by {@see PeriodRefusalReason} value, every case present
     * @param  array<string, int>  $defectsByKind  keyed by {@see ScheduleDefect} value, every case present
     */
    public function __construct(
        public int $schedules,
        public int $schedulesDue,
        public int $periodsClassified,
        public int $wouldHalt,
        public int $haltedByARefusal,
        public int $haltedByAPendingDraft,
        public int $haltedByAScheduleDefect,
        public int $schedulesTruncated,
        public array $refusalsByReason,
        public array $defectsByKind,
    ) {}

    /**
     * Whether every due period of every active schedule was actually examined.
     *
     * False means the answer is "I do not know", not "clean" and not "halting".
     * A schedule whose backlog exceeds the per-schedule cap stops being
     * examined partway through, so its remaining periods are unclassified;
     * re-run it with a higher cap - see the preflight's `$periodCap` - before
     * treating the result as a pass.
     */
    public function isConclusive(): bool
    {
        return $this->schedulesTruncated === 0;
    }

    /**
     * Every reason and defect present with a zero, so a consumer can diff two
     * runs without having to decide whether a missing key means zero or means
     * the vocabulary changed.
     *
     * `complete` is stated as its own boolean rather than left for a reader to
     * derive from `schedules_truncated`, because a pipeline that only checks
     * `would_halt` would otherwise read an inconclusive run as a pass.
     *
     * @return array{
     *     schedules: int,
     *     schedules_due: int,
     *     periods_classified: int,
     *     complete: bool,
     *     would_halt: int,
     *     halted_by_a_refusal: int,
     *     halted_by_a_pending_draft: int,
     *     halted_by_a_schedule_defect: int,
     *     schedules_truncated: int,
     *     refusals_by_reason: array<string, int>,
     *     defects_by_kind: array<string, int>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schedules' => $this->schedules,
            'schedules_due' => $this->schedulesDue,
            'periods_classified' => $this->periodsClassified,
            'complete' => $this->isConclusive(),
            'would_halt' => $this->wouldHalt,
            'halted_by_a_refusal' => $this->haltedByARefusal,
            'halted_by_a_pending_draft' => $this->haltedByAPendingDraft,
            'halted_by_a_schedule_defect' => $this->haltedByAScheduleDefect,
            'schedules_truncated' => $this->schedulesTruncated,
            'refusals_by_reason' => $this->refusalsByReason,
            'defects_by_kind' => $this->defectsByKind,
        ];
    }
}
