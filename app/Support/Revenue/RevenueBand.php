<?php

namespace App\Support\Revenue;

use App\Support\Billing\InvoiceLineType;

/**
 * The bands the revenue chart, the planning reads and the client figures split
 * invoiced money into (docs/ux-overhaul.md sections 2b and 9).
 *
 * Every {@see InvoiceLineType} is decided here by an exhaustive `match` with
 * no `default` arm, so a new line type fails static analysis until someone
 * decides which band it belongs to, whether its own date places it, and
 * whether it feeds the overage projection.
 */
enum RevenueBand: string
{
    /** Retainer fees and agreement recurring items: what an agreement sells by its cadence. */
    case Recurring = 'recurring';

    /** Hours billed beyond the retainer, milestones, adjustments and everything else charged ad hoc. */
    case OverageAndOther = 'overage_and_other';

    /** Pass-through expenses billed at cost; the dashboard can switch them off. */
    case Expenses = 'expenses';

    /**
     * The band one invoice line counts in.
     *
     * - `retainer`: the fee for the cycle the invoice sells.
     * - `prior_month_retainer`: the $0 line saying which work (or which
     *   deferred work) the retainer absorbed. It is part of the retainer, so
     *   a nonzero legacy amount stays beside the fee it draws on.
     * - `recurring_item`: an agreement recurring item at its own cadence.
     * - `carried_deferred_applied`: the $0 line settling re-carried deferred
     *   work from retainer capacity (CarriedDeferredLine::Applied), the same
     *   kind of retainer draw as `prior_month_retainer`.
     * - `prior_month_billable` and `additional_hours`: hours charged at the
     *   hourly rate beyond the retainer - the overage itself.
     * - `carried_deferred_billed`: re-carried deferred work billed at the
     *   hourly rate on termination; hourly work beyond the retainer.
     * - `deferred_buydown`: an explicit hourly settlement of deferred work
     *   outside the retainer (docs/client-management/deferred-billing.md);
     *   its discount is written as an `adjustment`.
     * - `subcontractor`: flat-hourly subcontractor hours billed at their own
     *   rate, never against the retainer pool.
     * - `milestone`, `adjustment`: fixed-price deliverables and operator lines.
     * - `reconciliation`: the $0 line recording hours an interim overage
     *   invoice already billed; the money is on those `additional_hours` lines.
     * - `credit`: overpayment credit spent on this invoice. It nets in the
     *   month of the invoice it applies to (settled answer: credits net in the
     *   month they apply to) and is no part of any agreement's recurring terms.
     * - `expense`: an approved expense billed at cost.
     */
    public static function forLineType(InvoiceLineType $type): self
    {
        return match ($type) {
            InvoiceLineType::Retainer,
            InvoiceLineType::PriorMonthRetainer,
            InvoiceLineType::RecurringItem,
            InvoiceLineType::CarriedDeferredApplied => self::Recurring,
            InvoiceLineType::PriorMonthBillable,
            InvoiceLineType::AdditionalHours,
            InvoiceLineType::CarriedDeferredBilled,
            InvoiceLineType::DeferredBuyDown,
            InvoiceLineType::Subcontractor,
            InvoiceLineType::Milestone,
            InvoiceLineType::Adjustment,
            InvoiceLineType::Reconciliation,
            InvoiceLineType::Credit => self::OverageAndOther,
            InvoiceLineType::Expense => self::Expenses,
        };
    }

    /**
     * The band of a stored line type. The column is an unconstrained string,
     * so an imported or legacy value no case names still carries money: it is
     * counted as "overage and other" rather than dropped, because nothing
     * says it recurs.
     */
    public static function forStoredLineType(string $type): self
    {
        $known = InvoiceLineType::tryFrom($type);

        return $known === null ? self::OverageAndOther : self::forLineType($known);
    }

    /**
     * Is the line placed by its own `line_date` rather than by its invoice's
     * service month?
     *
     * A cadence invoice reconciles one period's work and sells the next
     * period in advance, so its service period is the work's. The retainer
     * line is dated the first day of the cycle it sells and a recurring item
     * the day it is charged; placing them by the invoice would put every
     * retainer a cycle early and make the projection (which places a charge
     * on the day it is charged) disagree with the actuals it continues. Every
     * other line, credits included, describes the invoice's own period.
     */
    public static function placesByOwnDate(InvoiceLineType $type): bool
    {
        return match ($type) {
            InvoiceLineType::Retainer, InvoiceLineType::RecurringItem => true,
            InvoiceLineType::PriorMonthRetainer,
            InvoiceLineType::PriorMonthBillable,
            InvoiceLineType::AdditionalHours,
            InvoiceLineType::Credit,
            InvoiceLineType::Expense,
            InvoiceLineType::Milestone,
            InvoiceLineType::Adjustment,
            InvoiceLineType::Reconciliation,
            InvoiceLineType::Subcontractor,
            InvoiceLineType::CarriedDeferredApplied,
            InvoiceLineType::CarriedDeferredBilled,
            InvoiceLineType::DeferredBuyDown => false,
        };
    }

    /**
     * Does the line count as billed overage for the projection's trailing
     * average?
     *
     * Projected overage covers additional hours only (docs/ux-overhaul.md
     * section 9). Termination billing of carried deferred work and a deferred
     * buy-down are one-off settlements, and carrying either forward flat would
     * forecast a settlement every month.
     */
    public static function countsAsBilledOverage(InvoiceLineType $type): bool
    {
        return match ($type) {
            InvoiceLineType::AdditionalHours, InvoiceLineType::PriorMonthBillable => true,
            InvoiceLineType::Retainer,
            InvoiceLineType::PriorMonthRetainer,
            InvoiceLineType::Credit,
            InvoiceLineType::Expense,
            InvoiceLineType::Milestone,
            InvoiceLineType::Adjustment,
            InvoiceLineType::RecurringItem,
            InvoiceLineType::Reconciliation,
            InvoiceLineType::Subcontractor,
            InvoiceLineType::CarriedDeferredApplied,
            InvoiceLineType::CarriedDeferredBilled,
            InvoiceLineType::DeferredBuyDown => false,
        };
    }

    /** @return list<string> */
    public static function ownDateLineTypeValues(): array
    {
        return self::valuesWhere(self::placesByOwnDate(...));
    }

    /** @return list<string> */
    public static function billedOverageLineTypeValues(): array
    {
        return self::valuesWhere(self::countsAsBilledOverage(...));
    }

    /**
     * @param  callable(InvoiceLineType): bool  $predicate
     * @return list<string>
     */
    private static function valuesWhere(callable $predicate): array
    {
        $values = [];
        foreach (InvoiceLineType::cases() as $type) {
            if ($predicate($type)) {
                $values[] = $type->value;
            }
        }

        return $values;
    }
}
