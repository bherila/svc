<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * An {@see InvoiceHoursStatement} laid out for a person to read.
 *
 * Only presentation: which rows a section shows, what they are called and
 * which way their sign points. Every figure is one the statement stores, and
 * the two net lines are arithmetic over rows printed directly above them, so a
 * reader can check each total with the rows in front of them.
 *
 * Core rows always print, zero or not, so two months' statements line up.
 * Rows that describe something this invoice may simply not have - deferred
 * work, termination, interim invoices, expired hours - print only when they
 * carry hours, so a quiet month is not a page of zeros.
 */
final class InvoiceHoursStatementRows
{
    /**
     * Each row's `kind` is `row`, `detail` (an indented part of the row above
     * it), `total`, or `note`.
     *
     * @return list<array{title: string, rows: list<array{label: string, hours: string, kind: string}>}>
     */
    public static function for(InvoiceHoursStatement $statement): array
    {
        $work = self::period($statement->workStart, $statement->workEnd);
        $next = $statement->retainerStart !== null && $statement->retainerEnd !== null
            ? self::period($statement->retainerStart, $statement->retainerEnd)
            : 'the next period';

        $sections = [];

        // The one optional row is last, so filtering it out leaves a list.
        $sections[] = ['title' => 'Opening pool: '.$work, 'rows' => array_filter([
            self::row('Retainer hours for '.$work, $statement->openingRetainerHours),
            self::row('Unused hours rolled in from earlier periods', $statement->openingRolloverHours),
            self::row('Hours owed from earlier periods', -$statement->openingDeficitHours),
            self::row('Net hours available at the start of '.$work, $statement->openingNetHours(), 'total'),
            self::optional('Unused hours that expired at the start of '.$work, $statement->openingExpiredHours, 'note'),
        ])];

        $sections[] = ['title' => 'Work reconciled on this invoice', 'rows' => array_values(array_filter([
            self::row('Hours worked in '.$work, $statement->ordinaryHours),
            self::row('Applied to the '.$work.' pool', $statement->ordinaryAppliedToWorkPool, 'detail'),
            self::optional('Applied in advance to the '.$next.' retainer', $statement->ordinaryAppliedToNextRetainer, 'detail'),
            self::row('Billed at the hourly rate', $statement->ordinaryBilledAtRate, 'detail'),
            self::optional('Subcontractor hours billed separately at their own rate (not drawn on the pool)', $statement->subcontractorHours),
            self::optional(
                'Deferred work applied to free capacity ('.$statement->deferredAppliedEntries.' '.($statement->deferredAppliedEntries === 1 ? 'entry' : 'entries').')',
                $statement->deferredAppliedHours,
            ),
            self::optional('Earlier deferred work settled from free capacity', $statement->recarriedSettledHours),
            self::optional('Deferred work billed at the hourly rate on termination', $statement->deferredBilledOnTerminationHours),
            self::optional('Already billed in this cycle by interim invoices', $statement->interimBilledHours),
        ]))];

        $sections[] = ['title' => 'Catch-up billed at the hourly rate', 'rows' => array_values(array_filter([
            self::row('Work beyond the available pool', $statement->ordinaryBilledAtRate),
            // Whenever the agreement keeps a minimum, even in a month that
            // needed none of it: the zero is the answer to "was I charged for
            // availability?".
            round($statement->minimumAvailabilityThresholdHours, 2) > 0 || round($statement->minimumAvailabilityHours, 2) > 0
                ? self::row(
                    'Minimum availability: restores the agreement\'s '.self::hours($statement->minimumAvailabilityThresholdHours).'-hour minimum for '.$next,
                    $statement->minimumAvailabilityHours,
                )
                : null,
            self::row('Total catch-up billed on this invoice', $statement->catchUpBilledHours, 'total'),
        ]))];

        $sections[] = ['title' => 'Carried forward', 'rows' => array_values(array_filter([
            self::optional('Unused hours that expired within '.$work, $statement->expiredWithinPeriodHours),
            self::row('Unused hours rolling into '.$next, $statement->rolledForwardHours),
            self::row('Unused hours expiring at the start of '.$next, $statement->expiringHours),
            self::row('Hours still owed, carried into '.$next, $statement->deficitCarriedForwardHours),
            self::optional(
                'Deferred work waiting for free capacity ('.$statement->deferredBacklogEntries.' '.($statement->deferredBacklogEntries === 1 ? 'entry' : 'entries').')',
                $statement->deferredBacklogHours,
            ),
            self::optional('Earlier deferred work still to settle', $statement->recarriedRemainingHours),
        ]))];

        $sections[] = ['title' => 'Closing position: '.$next, 'rows' => [
            self::row('Retainer hours for '.$next, $statement->nextRetainerHours),
            self::row('Unused hours rolled in', $statement->rolledForwardHours),
            self::row('Hours owed carried in', -$statement->deficitCarriedForwardHours),
            self::row('Net hours available at the start of '.$next, $statement->closingNetHours(), 'total'),
        ]];

        return $sections;
    }

    /** Hours to two places, which is what every other hour on the invoice shows. */
    public static function hours(float $hours): string
    {
        // number_format() rounds, and prints a rounded-away negative as
        // "0.00" rather than "-0.00", which would read as a debt.
        return number_format($hours, 2, '.', ',');
    }

    /**
     * A period the way a client names it: "August 2026", "July – September
     * 2026", or the dates themselves when it is not whole months.
     */
    public static function period(string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        $wholeMonths = $from->day === 1 && $to->isSameDay($to->endOfMonth());

        if (! $wholeMonths) {
            return $from->format('M j, Y').' – '.$to->format('M j, Y');
        }
        if ($from->isSameMonth($to)) {
            return $from->format('F Y');
        }
        if ($from->year === $to->year) {
            return $from->format('F').' – '.$to->format('F Y');
        }

        return $from->format('F Y').' – '.$to->format('F Y');
    }

    /** @return array{label: string, hours: string, kind: string} */
    private static function row(string $label, float $hours, string $kind = 'row'): array
    {
        return ['label' => $label, 'hours' => self::hours($hours), 'kind' => $kind];
    }

    /** @return array{label: string, hours: string, kind: string}|null */
    private static function optional(string $label, float $hours, string $kind = 'row'): ?array
    {
        return round($hours, 2) === 0.0 ? null : self::row($label, $hours, $kind);
    }
}
