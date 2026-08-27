<?php

namespace App\Support\Billing;

/**
 * What is true of one agreement and period, for {@see DeliberateCorrections}.
 *
 * These were an associative array, which is how they drifted: the replay built
 * them from a company-wide time-entry query while the billing engine built its
 * own capacity from a workspace-scoped, approved, retainer-billable,
 * project-filtered one. Draft time, non-billable time, flat-hourly
 * subcontractor time and another project's time could all set `deferredWork` or
 * `otherProjectWork` and waive a divergence the corrections never touched.
 *
 * Naming them here does not stop that on its own, but it puts the definitions
 * in one place next to the rules that read them, and makes a missing fact a
 * type error rather than a null.
 */
final class CorrectionFacts
{
    public function __construct(
        /** How many months the agreement carries unused hours forward. */
        public readonly int $rolloverMonths,

        /**
         * Did some month inside the rollover window consume its whole retainer?
         *
         * This is the condition the calendar-ageing correction needs. A month
         * with hours left over aged correctly in the original too, so without
         * one that was fully used there is nothing for the correction to have
         * changed.
         */
        public readonly bool $fullyUsedMonthInRolloverWindow,

        /** Is the agreement scoped to a single project? */
        public readonly bool $projectScoped,

        /**
         * Did the company do retainer-eligible work outside that project?
         *
         * Measured with the same scopes the billing engine uses, so time that
         * could never have drawn on this retainer does not count.
         */
        public readonly bool $otherProjectWork,

        /** Is there deferred retainer-eligible work in the period? */
        public readonly bool $deferredWork,

        /** Does the billing cycle open on a day other than the first? */
        public readonly bool $cycleOpensMidMonth,

        /**
         * Is there an active recurring item the original would have re-billed?
         *
         * Needs an anchor falling before the cycle opens - otherwise the
         * previous cycle never covered it - on an item that started in an
         * earlier month, since an item in its own first month still bills from
         * its start date under both engines.
         */
        public readonly bool $recurringItemAnchoredBeforeCycleOpens,
    ) {}
}
