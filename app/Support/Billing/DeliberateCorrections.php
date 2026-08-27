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
 * second number, so the predicates here are deliberately narrow - a correction
 * only explains a divergence when every changed line type is within its reach
 * *and* the conditions that trigger it actually hold for that agreement and
 * period. An over-eager rule here would quietly absorb a real regression, which
 * is the one outcome worse than a noisy failure.
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
     * @var list<string>
     */
    private const CAPACITY_DEPENDENT = [
        'retainer',
        'prior_month_retainer',
        'prior_month_billable',
        'additional_hours',
        'subtotal',
    ];

    /**
     * @param  list<string>  $changedLineTypes  line types (and 'subtotal') that differ
     * @param  array{rollover_months:int, project_scoped:bool, other_project_work:bool, deferred_work:bool, recurring_items:bool, cycle_opens_mid_month:bool}  $facts
     * @return list<array{key: string, summary: string}>
     */
    public static function explaining(array $changedLineTypes, array $facts): array
    {
        if ($changedLineTypes === []) {
            return [];
        }

        $explanations = [];

        $withinCapacity = self::confinedTo($changedLineTypes, self::CAPACITY_DEPENDENT);

        // The original counted stored non-zero balances when ageing rollover, so
        // a month that used its whole retainer was invisible to the ageing and
        // older hours stayed spendable past their window. Only reaches an
        // agreement that actually carries hours forward.
        if ($withinCapacity && $facts['rollover_months'] > 0) {
            $explanations[] = [
                'key' => 'rollover_expiry_ages_by_calendar',
                'summary' => 'Rollover ages by elapsed calendar months; the original could not see a fully-used month.',
            ];
        }

        // The original counted deferred work against the pool before the
        // allocator had billed it, consuming capacity nothing took. Only
        // reaches a period that has deferred work in it.
        if ($withinCapacity && $facts['deferred_work']) {
            $explanations[] = [
                'key' => 'deferred_work_not_drawn_early',
                'summary' => 'Deferred time draws on the retainer only once allocated.',
            ];
        }

        // The original pooled the whole company's work against a project-scoped
        // agreement. Only reaches an agreement that is scoped *and* a company
        // that did work outside that project.
        if ($withinCapacity && $facts['project_scoped'] && $facts['other_project_work']) {
            $explanations[] = [
                'key' => 'project_scoped_agreement_counts_its_own_project',
                'summary' => "A project-scoped agreement counts only its own project's work.",
            ];
        }

        // The original applied a recurring item's start-date fallback whenever a
        // cycle opened mid-month, re-billing an anchor the previous cycle had
        // already charged.
        if (self::confinedTo($changedLineTypes, ['recurring_item', 'subtotal'])
            && $facts['recurring_items']
            && $facts['cycle_opens_mid_month']) {
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
