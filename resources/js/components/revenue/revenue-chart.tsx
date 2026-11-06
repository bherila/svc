import { Link, router } from '@inertiajs/react';
import { TableIcon } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useMediaQuery } from '@/hooks/use-media-query';
import { formatMoney } from '@/lib/money';
import {
    compactMoney,
    monthLabel,
    monthName,
    phoneWindow,
} from '@/lib/revenue';
import { cn } from '@/lib/utils';
import type {
    ChartMonth,
    RevenueAssumptions,
    RevenueFilters,
} from '@/types/revenue';

/**
 * The 24-month revenue chart (docs/ux-overhaul.md section 2b), also drawn on
 * the client overview.
 *
 * Past months are stacked actuals: Recurring, Overage and other, and Expenses
 * when that switch is on. Future months are the Contracted bar (outlined,
 * lighter) with a hatched Projected overage band on top when that switch is
 * on. A dashed rule marks the current month, which carries what is invoiced so
 * far plus what remains contracted. Hovering a month names its top clients;
 * clicking a bar opens that month's invoices.
 *
 * The bars draw the server's numbers as they are. A phone shows six months
 * back and five ahead.
 *
 * It is an image to a screen reader, whose way in is the data table: every
 * month, every band, the top clients and a link to the month's invoices, always
 * in the page (visually hidden) and shown by "Show as table". The chart's own
 * keyboard layer is off on purpose - a focusable chart inside a described image
 * is a nested interactive control.
 */

type Datum = ChartMonth & {
    recurringBar: number;
    overageBar: number;
    expensesBar: number;
    contractedBar: number;
    projectedBar: number;
};

const COLORS = {
    recurring: 'var(--revenue-recurring)',
    other: 'var(--revenue-other)',
    expenses: 'var(--revenue-expenses)',
};

function clientsLine(month: ChartMonth, currency: string | null): string {
    const parts = month.clients.map(
        (client) => `${client.name} ${formatMoney(client.total, currency)}`,
    );

    if (month.others !== null) {
        parts.push(
            `${month.others.count} other${month.others.count === 1 ? '' : 's'} ${formatMoney(month.others.total, currency)}`,
        );
    }

    return parts.join(', ');
}

function MonthTooltip({
    active,
    payload,
    currency,
}: {
    active?: boolean;
    payload?: { payload: Datum }[];
    currency: string | null;
}) {
    const datum = payload?.[0]?.payload;

    if (!active || datum === undefined) {
        return null;
    }

    return (
        <div
            data-slot="chart-tooltip"
            className="max-w-64 rounded-lg bg-popover p-3 text-xs text-popover-foreground shadow-md ring-1 ring-foreground/10"
        >
            <p className="font-semibold">{monthName(datum.month)}</p>
            <p className="tabular-nums">
                {formatMoney(datum.total, currency)}
                {datum.kind === 'projected' && ' projected'}
            </p>
            {datum.clients.length > 0 && (
                <ul className="mt-2 grid grid-cols-1 gap-0.5">
                    {datum.clients.map((client) => (
                        <li
                            key={client.client_id}
                            className="flex justify-between gap-3"
                        >
                            <span className="min-w-0 wrap-anywhere">
                                {client.name}
                            </span>
                            <span className="shrink-0 tabular-nums">
                                {formatMoney(client.total, currency)}
                            </span>
                        </li>
                    ))}
                    {datum.others !== null && (
                        <li className="flex justify-between gap-3 text-muted-foreground">
                            <span>{datum.others.count} others</span>
                            <span className="tabular-nums">
                                {formatMoney(datum.others.total, currency)}
                            </span>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

function Swatch({
    kind,
}: {
    kind: 'recurring' | 'other' | 'expenses' | 'contracted' | 'projected';
}) {
    const style =
        kind === 'contracted'
            ? {
                  background:
                      'color-mix(in oklch, var(--revenue-recurring) 25%, transparent)',
                  border: '1.5px solid var(--revenue-recurring)',
              }
            : kind === 'projected'
              ? {
                    background:
                        'repeating-linear-gradient(135deg, var(--revenue-other) 0 2px, transparent 2px 5px)',
                    border: '1px solid var(--revenue-other)',
                }
              : {
                    background:
                        kind === 'recurring'
                            ? COLORS.recurring
                            : kind === 'other'
                              ? COLORS.other
                              : COLORS.expenses,
                };

    return (
        <span
            aria-hidden="true"
            className="inline-block size-3 shrink-0 rounded-sm"
            style={style}
        />
    );
}

export default function RevenueChart({
    months,
    filters,
    assumptions,
    caption,
    busy = false,
}: {
    months: ChartMonth[];
    filters: RevenueFilters;
    assumptions: RevenueAssumptions;
    /** Shown to a screen reader as the chart's description. */
    caption: string;
    busy?: boolean;
}) {
    const currency = assumptions.currency;
    const phone = useMediaQuery('(max-width: 639px)');
    const [showTable, setShowTable] = useState(false);
    const tableId = useId();
    const shown = phone ? phoneWindow(months) : months;
    const data: Datum[] = useMemo(
        () =>
            shown.map((month) => ({
                ...month,
                recurringBar: month.recurring ?? 0,
                overageBar: month.overage_and_other ?? 0,
                expensesBar: month.expenses ?? 0,
                contractedBar: month.contracted ?? 0,
                projectedBar: month.projected_overage ?? 0,
            })),
        [shown],
    );
    const current = months.find((month) => month.kind === 'current');
    const open = (item: unknown) => {
        const href = (item as { payload?: { href?: string } }).payload?.href;

        if (href) {
            router.visit(href);
        }
    };

    return (
        <div
            className={cn('grid grid-cols-1 gap-3', busy && 'opacity-60')}
            data-slot="revenue-chart"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                <ul
                    aria-label="Legend"
                    className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                >
                    <li className="flex items-center gap-1.5">
                        <Swatch kind="recurring" />
                        Recurring
                    </li>
                    <li className="flex items-center gap-1.5">
                        <Swatch kind="other" />
                        Overage and other
                    </li>
                    {filters.expenses && (
                        <li className="flex items-center gap-1.5">
                            <Swatch kind="expenses" />
                            Expenses
                        </li>
                    )}
                    <li className="flex items-center gap-1.5">
                        <Swatch kind="contracted" />
                        Contracted
                    </li>
                    {filters.overage && (
                        <li className="flex items-center gap-1.5">
                            <Swatch kind="projected" />
                            Projected overage
                        </li>
                    )}
                </ul>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-expanded={showTable}
                    aria-controls={tableId}
                    onClick={() => setShowTable((value) => !value)}
                >
                    <TableIcon aria-hidden="true" data-icon="inline-start" />
                    {showTable ? 'Hide table' : 'Show as table'}
                </Button>
            </div>

            {assumptions.basis !== assumptions.projection_basis && (
                <p className="text-xs text-muted-foreground">
                    Past months show money collected. The forecast stays on the
                    invoiced basis, because collections cannot be forecast.
                </p>
            )}

            <div
                role="img"
                aria-label={caption}
                className={cn(
                    'h-60 w-full min-w-0 sm:h-72',
                    showTable && 'hidden',
                )}
            >
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={data}
                        accessibilityLayer={false}
                        margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                    >
                        <defs>
                            <pattern
                                id="revenue-hatch"
                                patternUnits="userSpaceOnUse"
                                width="6"
                                height="6"
                                patternTransform="rotate(45)"
                            >
                                <line
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="6"
                                    stroke={COLORS.other}
                                    strokeWidth="3"
                                />
                            </pattern>
                        </defs>
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                        />
                        <XAxis
                            dataKey="month"
                            tickLine={false}
                            axisLine={false}
                            interval={1}
                            tick={{
                                fill: 'var(--muted-foreground)',
                                fontSize: 11,
                            }}
                            tickFormatter={(month: string) =>
                                monthLabel(month, month.endsWith('-01'))
                            }
                        />
                        <YAxis
                            width={52}
                            tickLine={false}
                            axisLine={false}
                            tick={{
                                fill: 'var(--muted-foreground)',
                                fontSize: 11,
                            }}
                            tickFormatter={(value: number) =>
                                compactMoney(value, currency)
                            }
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                            content={<MonthTooltip currency={currency} />}
                        />
                        {current !== undefined && (
                            <ReferenceLine
                                x={current.month}
                                stroke="var(--muted-foreground)"
                                strokeDasharray="4 4"
                                label={{
                                    value: 'Today',
                                    position: 'insideTopRight',
                                    fill: 'var(--muted-foreground)',
                                    fontSize: 11,
                                }}
                            />
                        )}
                        <Bar
                            dataKey="recurringBar"
                            stackId="revenue"
                            fill={COLORS.recurring}
                            cursor="pointer"
                            onClick={open}
                            isAnimationActive={false}
                        />
                        <Bar
                            dataKey="contractedBar"
                            stackId="revenue"
                            fill="color-mix(in oklch, var(--revenue-recurring) 25%, transparent)"
                            stroke={COLORS.recurring}
                            strokeWidth={1.5}
                            cursor="pointer"
                            onClick={open}
                            isAnimationActive={false}
                        />
                        <Bar
                            dataKey="overageBar"
                            stackId="revenue"
                            fill={COLORS.other}
                            cursor="pointer"
                            onClick={open}
                            isAnimationActive={false}
                        />
                        {filters.expenses && (
                            <Bar
                                dataKey="expensesBar"
                                stackId="revenue"
                                fill={COLORS.expenses}
                                cursor="pointer"
                                onClick={open}
                                isAnimationActive={false}
                            />
                        )}
                        {filters.overage && (
                            <Bar
                                dataKey="projectedBar"
                                stackId="revenue"
                                fill="url(#revenue-hatch)"
                                stroke={COLORS.other}
                                strokeWidth={1}
                                cursor="pointer"
                                onClick={open}
                                isAnimationActive={false}
                            />
                        )}
                    </BarChart>
                </ResponsiveContainer>
            </div>

            <div
                id={tableId}
                className={cn(!showTable && 'sr-only')}
                data-slot="revenue-chart-table"
            >
                <Table>
                    <caption className="sr-only">{caption}</caption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">Month</TableHead>
                            <TableHead scope="col" className="text-right">
                                Recurring
                            </TableHead>
                            <TableHead scope="col" className="text-right">
                                Overage and other
                            </TableHead>
                            {filters.expenses && (
                                <TableHead scope="col" className="text-right">
                                    Expenses
                                </TableHead>
                            )}
                            <TableHead scope="col" className="text-right">
                                Contracted
                            </TableHead>
                            {filters.overage && (
                                <TableHead scope="col" className="text-right">
                                    Projected overage
                                </TableHead>
                            )}
                            <TableHead scope="col" className="text-right">
                                Total
                            </TableHead>
                            <TableHead scope="col" className="min-w-64">
                                Top clients
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {months.map((month) => (
                            <TableRow key={month.month}>
                                <TableCell>
                                    <Link
                                        href={month.href}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {monthName(month.month)}
                                    </Link>
                                    {month.kind === 'current' && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            (this month)
                                        </span>
                                    )}
                                </TableCell>
                                {[
                                    month.recurring,
                                    month.overage_and_other,
                                    ...(filters.expenses
                                        ? [month.expenses]
                                        : []),
                                    month.contracted,
                                    ...(filters.overage
                                        ? [month.projected_overage]
                                        : []),
                                    month.total,
                                ].map((amount, index) => (
                                    <TableCell
                                        key={index}
                                        className="text-right tabular-nums"
                                    >
                                        {amount === null
                                            ? '—'
                                            : formatMoney(amount, currency)}
                                    </TableCell>
                                ))}
                                <TableCell className="max-w-0 min-w-64 wrap-anywhere whitespace-normal">
                                    {clientsLine(month, currency) || '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
