<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Illuminate\Database\Eloquent\Builder;

/**
 * The `month` filter of the invoice lists a chart bar links to: the invoices
 * whose service month is that month, the issue date standing in for an
 * invoice with no service period - the same placement the invoiced basis
 * uses for everything but retainer and recurring-item lines, which sit in the
 * month of their own line date.
 */
final class ServiceMonth
{
    /**
     * The first and last day of a `Y-m` month, or null for anything that is
     * not a real month (so a mangled link filters nothing rather than
     * everything).
     *
     * @return array{string, string}|null
     */
    public static function bounds(mixed $month): ?array
    {
        if (! is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        $first = $month.'-01';
        $last = date('Y-m-t', (int) strtotime($first));

        return [$first, $last];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, mixed $month): Builder
    {
        $bounds = self::bounds($month);

        if ($bounds === null) {
            return $query;
        }

        return $query->where(function (Builder $inMonth) use ($bounds): void {
            $inMonth->whereBetween('service_period_end', $bounds)
                ->orWhere(function (Builder $undated) use ($bounds): void {
                    $undated->whereNull('service_period_end')->whereBetween('issue_date', $bounds);
                });
        });
    }
}
