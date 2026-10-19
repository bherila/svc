import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ApiSchema } from '@/lib/api';
import { formatDay } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { formatDecimalHours } from '@/lib/time';

export type DeferredBacklog = ApiSchema<'DeferredBacklog'>;

/**
 * How the shelf moved over the months read, in words.
 *
 * Positive net is work arriving faster than free capacity absorbs it: the
 * backlog an operator may want to raise with the client before it grows.
 */
export function backlogTrend(backlog: DeferredBacklog): string | null {
    const net = backlog.net_hours_per_month;
    const months = backlog.trend.length;

    if (net === null || months === 0) {
        return null;
    }

    const window =
        months === 1 ? 'this month' : `over the last ${months} months`;

    if (net === 0) {
        return `steady ${window}`;
    }

    return `${net > 0 ? 'growing' : 'shrinking'} ${formatDecimalHours(Math.abs(net))} h/month ${window}`;
}

/**
 * The agreement's deferred backlog, as one line (#366).
 *
 * The figure is the server's: the hours the termination invoice would collect
 * today and what it would charge for them. Nothing here recomputes it.
 */
export function DeferredBacklogCard({ backlog }: { backlog: DeferredBacklog }) {
    const trend = backlogTrend(backlog);
    const amount = backlog.amount_at_hourly_rate;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Deferred backlog</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-2 text-sm">
                {backlog.hours === null ? (
                    <p className="text-muted-foreground">Withheld.</p>
                ) : backlog.hours === 0 ? (
                    <p className="text-muted-foreground">
                        No deferred work is waiting
                        {trend === null ? '' : ` (${trend})`}.
                    </p>
                ) : (
                    <p data-testid="deferred-backlog-line">
                        <span className="font-medium tabular-nums">
                            {formatDecimalHours(backlog.hours)} h
                        </span>
                        {amount !== null && (
                            <>
                                {' ('}
                                <span className="tabular-nums">
                                    {formatMoney(
                                        amount.amount,
                                        amount.currency,
                                    )}
                                </span>
                                {' if billed on termination)'}
                            </>
                        )}
                        {trend !== null && `, ${trend}`}
                    </p>
                )}
                {backlog.hours !== null &&
                    backlog.hours > 0 &&
                    backlog.oldest_worked_on !== null && (
                        <p className="text-muted-foreground">
                            {backlog.entry_count === 1
                                ? '1 entry'
                                : `${backlog.entry_count} entries`}
                            , the oldest worked on{' '}
                            {formatDay(backlog.oldest_worked_on)}. Billed only
                            on termination, at the agreement&apos;s hourly rate
                            {backlog.flat_hourly_hours !== null &&
                                backlog.flat_hourly_hours > 0 &&
                                ' (flat-hourly work at its subcontractor rate)'}
                            .
                        </p>
                    )}
                {backlog.issue_message !== null && (
                    <p className="wrap-anywhere text-amber-700 dark:text-amber-400">
                        {backlog.issue_message}
                        {backlog.issue_detail !== null &&
                            ` ${backlog.issue_detail}`}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
