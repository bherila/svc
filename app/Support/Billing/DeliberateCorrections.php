<?php

namespace App\Support\Billing;

/**
 * Where this engine knowingly bills differently from the one it was ported from.
 *
 * Four behaviours were corrected rather than reproduced, because the original
 * had them wrong. Each moves money relative to what clients were actually
 * billed, which makes them invisible to ordinary tests and fatal to a naive
 * replay: comparing regenerated invoices against history and demanding an exact
 * match asks the engine to reproduce bugs it was fixed not to have.
 *
 * So the replay classifies instead of just failing. A divergence confined to
 * what a known correction can reach is reported as explained; anything else is
 * unexplained and fails the run. The value of the exercise is entirely in that
 * second number.
 *
 * ## Why these predicates are narrower than they look like they should be
 *
 * The first version of this class asked two questions: is every changed line
 * type within the correction's reach, and is the correction switched on for
 * this agreement. That is not a test of causation, it is a test of opportunity,
 * and it classified ten whole-invoice disappearances as deliberate rollover
 * corrections on the strength of `rollover_months > 0`. Every one of them had
 * lost its fixed retainer fee and every generated line, which no capacity
 * correction can do.
 *
 * Two things follow, and both are enforced here. A correction may only explain
 * line types it can actually move - the retainer fee is contracted, not
 * computed from capacity, so no capacity correction reaches it. And a
 * correction may only explain a divergence when the specific condition that
 * triggers it is shown to hold, not merely when the feature is enabled.
 *
 * This is still attribution by opportunity, only a much narrower opportunity.
 * The honest test is counterfactual - regenerate with one correction disabled
 * and see whether the divergence goes away - which is tracked separately. Until
 * that exists, treat "explained" as "not yet shown to be a regression", never as
 * "shown to be intended".
 *
 * These are the same four documented in docs/client-management/README.md under
 * "Where SVC deliberately differs"; this is the machine-readable copy.
 */
final class DeliberateCorrections
{
    /**
     * Line types whose value depends on how much retainer capacity was
     * available. Three of the four corrections change that, so they all reach
     * the same set.
     *
     * `retainer` is deliberately absent. The fixed fee is what the agreement
     * sold, not what the pool could absorb; changing how hours age, when
     * deferred work draws, or which project's work counts cannot make a
     * contracted retainer charge smaller or larger. A divergence that moves the
     * retainer line is a divergence in what the client was charged for the
     * agreement itself, and must be read, not waived.
     *
     * @var list<string>
     */
    private const CAPACITY_DEPENDENT = [
        'prior_month_retainer',
        'prior_month_billable',
        'additional_hours',
    ];

    /**
     * Invoice fields a capacity correction moves as a consequence.
     *
     * Kept as their own argument rather than mixed in with line types. An
     * imported line carries whatever type string its source used, so no
     * spelling is safe to reserve - a line typed "subtotal", or "#subtotal",
     * would otherwise be read as this marker and waived by a correction that
     * says nothing about it.
     *
     * @var list<string>
     */
    private const CAPACITY_DEPENDENT_FIELDS = ['subtotal'];

    /**
     * @param  list<string>  $changedLineTypes  the types of the lines that differ
     * @param  list<string>  $changedFields  invoice fields that differ, named separately so no line type can impersonate one
     * @return list<array{key: string, summary: string}>
     */
    public static function explaining(array $changedLineTypes, array $changedFields, CorrectionFacts $facts): array
    {
        if ($changedLineTypes === [] && $changedFields === []) {
            return [];
        }

        $explanations = [];

        $withinCapacity = self::confinedTo($changedLineTypes, self::CAPACITY_DEPENDENT)
            && self::confinedTo($changedFields, self::CAPACITY_DEPENDENT_FIELDS);

        // The original counted stored non-zero balances when ageing rollover, so
        // a month that used its whole retainer was invisible to the ageing and
        // older hours stayed spendable past their window.
        //
        // Enabling rollover is not enough to reach that. The divergence needs a
        // month inside the window that consumed its entire retainer, because a
        // month with anything left over aged correctly in the original too.
        if ($withinCapacity && $facts->rolloverMonths > 0 && $facts->fullyUsedMonthInRolloverWindow) {
            $explanations[] = [
                'key' => 'rollover_expiry_ages_by_calendar',
                'summary' => 'Rollover ages by elapsed calendar months; the original could not see a fully-used month.',
            ];
        }

        // The original counted deferred work against the pool before the
        // allocator had billed it, consuming capacity nothing took.
        if ($withinCapacity && $facts->deferredWork) {
            $explanations[] = [
                'key' => 'deferred_work_not_drawn_early',
                'summary' => 'Deferred time draws on the retainer only once allocated.',
            ];
        }

        // The original pooled the whole company's work against a project-scoped
        // agreement.
        if ($withinCapacity && $facts->projectScoped && $facts->otherProjectWork) {
            $explanations[] = [
                'key' => 'project_scoped_agreement_counts_its_own_project',
                'summary' => "A project-scoped agreement counts only its own project's work.",
            ];
        }

        // The original applied a recurring item's start-date fallback whenever a
        // cycle opened mid-month, re-billing an anchor the previous cycle had
        // already charged.
        //
        // A mid-month cycle alone does not reach that either. It needs an item
        // whose anchor falls before the cycle opens - otherwise the previous
        // cycle never covered it - and which started in an earlier month, since
        // an item in its own first month still bills from its start date.
        if (self::confinedTo($changedLineTypes, ['recurring_item'])
            && self::confinedTo($changedFields, self::CAPACITY_DEPENDENT_FIELDS)
            && $facts->cycleOpensMidMonth
            && $facts->recurringItemAnchoredBeforeCycleOpens) {
            $explanations[] = [
                'key' => 'recurring_item_start_fallback_first_month_only',
                'summary' => "A recurring item's start-date fallback applies only in its own first month.",
            ];
        }

        return $explanations;
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>  $allowed
     */
    private static function confinedTo(array $changed, array $allowed): bool
    {
        foreach ($changed as $type) {
            if (! in_array($type, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
