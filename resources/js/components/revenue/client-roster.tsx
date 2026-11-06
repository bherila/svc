import { Link, router } from '@inertiajs/react';
import {
    AlertCircleIcon,
    ArrowDownIcon,
    ArrowUpIcon,
    ChevronsUpDownIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TermTip } from '@/components/help/term-tip';
import { RetainerMeter } from '@/components/revenue/retainer-meter';
import type { MeterStatus } from '@/components/revenue/retainer-meter';
import { Sparkline } from '@/components/revenue/sparkline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import type {
    Roster,
    RosterFilter,
    RosterHealth,
    RosterRow,
    RosterSort,
} from '@/types/revenue';

/**
 * The client roster for owners and admins (docs/ux-overhaul.md section 3): one
 * row per client with its MRR, year to date, trailing twelve months (and a
 * trend line), what is open and overdue, and its retainer health.
 *
 * Searching, filtering and sorting are asked of the server - the totals row is
 * the server's total of the rows left, never a sum made here. The retainer
 * meters arrive a moment after the table (they read each agreement's hours),
 * so a row says it is reading them until they do. Below `md` each row becomes
 * a card: name, MRR, open balance and the meter.
 */

const FILTERS: { value: RosterFilter; label: string }[] = [
    { value: 'all', label: 'All clients' },
    { value: 'active', label: 'Active' },
    { value: 'ending_soon', label: 'Ending soon' },
    { value: 'over_retainer', label: 'Over retainer' },
    { value: 'overdue', label: 'Has overdue invoices' },
];

/** Numeric columns sort largest first; the name sorts A to Z. */
const FIRST_DIRECTION: Record<RosterSort, 'asc' | 'desc'> = {
    name: 'asc',
    mrr: 'desc',
    ytd: 'desc',
    ttm: 'desc',
    open: 'desc',
    overdue: 'desc',
};

function meterOf(
    row: RosterRow,
    health: Record<string, RosterHealth> | undefined,
): {
    status: MeterStatus;
    used: number | null;
    included: number | null;
    over: boolean;
} {
    const found = health?.[row.client_id];

    return found === undefined
        ? { status: 'pending', used: null, included: null, over: false }
        : {
              status: found.status,
              used: found.minutes_used,
              included: found.minutes_included,
              over: found.over_retainer,
          };
}

function ClientName({ row }: { row: RosterRow }) {
    return (
        <>
            <Link
                href={row.href}
                className="font-medium wrap-anywhere underline-offset-4 hover:underline"
            >
                {row.name}
            </Link>
            {!row.is_active && (
                <Badge variant="outline" className="ml-2 align-middle">
                    Inactive
                </Badge>
            )}
        </>
    );
}

function OpenCell({
    row,
    currency,
}: {
    row: RosterRow;
    currency: string | null;
}) {
    return (
        <div className="grid grid-cols-1 gap-0.5">
            <span className="tabular-nums">
                {formatMoney(row.open_amount, currency)}
            </span>
            {row.overdue_count > 0 ? (
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    <AlertCircleIcon
                        aria-hidden="true"
                        className="size-3.5 shrink-0"
                    />
                    {formatMoney(row.overdue_amount, currency)} overdue (
                    {row.overdue_count})
                </span>
            ) : (
                <span className="text-xs text-muted-foreground">
                    None overdue
                </span>
            )}
        </div>
    );
}

function SortHead({
    sort,
    filters,
    onSort,
    children,
    className,
}: {
    sort: RosterSort;
    filters: Roster['filters'];
    onSort: (sort: RosterSort) => void;
    children: React.ReactNode;
    className?: string;
}) {
    const active = filters.sort === sort;
    const Icon = !active
        ? ChevronsUpDownIcon
        : filters.dir === 'asc'
          ? ArrowUpIcon
          : ArrowDownIcon;

    return (
        <TableHead
            scope="col"
            className={className}
            aria-sort={
                active
                    ? filters.dir === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : 'none'
            }
        >
            <button
                type="button"
                onClick={() => onSort(sort)}
                className="inline-flex min-h-8 items-center gap-1 rounded-md font-medium hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none pointer-coarse:min-h-11"
            >
                {children}
                <Icon aria-hidden="true" className="size-3.5" />
            </button>
        </TableHead>
    );
}

export function ClientRoster({
    roster,
    health,
}: {
    roster: Roster;
    health: Record<string, RosterHealth> | undefined;
}) {
    const { filters, currency } = roster;
    const money = (amount: number) => formatMoney(amount, currency);
    const [query, setQuery] = useState(filters.q);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    function ask(next: Partial<Roster['filters']>) {
        const merged = { ...filters, ...next };
        const data: Record<string, string> = {};

        if (merged.q !== '') {
            data.q = merged.q;
        }

        if (merged.filter !== 'all') {
            data.filter = merged.filter;
        }

        if (merged.sort !== 'name' || merged.dir !== 'asc') {
            data.sort = merged.sort;
            data.dir = merged.dir;
        }

        router.get(roster.href, data, {
            // The meters are a deferred read of every agreement's hours; only
            // the over-retainer filter needs them to decide the rows.
            only:
                merged.filter === 'over_retainer'
                    ? ['roster', 'health']
                    : ['roster'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function sortBy(sort: RosterSort) {
        ask({
            sort,
            dir:
                filters.sort === sort
                    ? filters.dir === 'asc'
                        ? 'desc'
                        : 'asc'
                    : FIRST_DIRECTION[sort],
        });
    }

    const filtered =
        filters.q !== '' || filters.filter !== 'all'
            ? `${roster.clients.length} of ${roster.total_count} clients`
            : `${roster.total_count} ${roster.total_count === 1 ? 'client' : 'clients'}`;

    return (
        <div className="grid min-w-0 grid-cols-1 gap-4" data-slot="roster">
            <form
                role="search"
                className="flex flex-wrap items-center gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    window.clearTimeout(timer.current);
                    ask({ q: query.trim() });
                }}
            >
                <Input
                    type="search"
                    aria-label="Search clients"
                    placeholder="Search clients"
                    value={query}
                    maxLength={100}
                    className="max-w-xs pointer-coarse:h-11"
                    onChange={(event) => {
                        const value = event.target.value;

                        setQuery(value);
                        window.clearTimeout(timer.current);
                        timer.current = window.setTimeout(
                            () => ask({ q: value.trim() }),
                            300,
                        );
                    }}
                />
                <Select
                    // The trigger renders the raw value unless the root is
                    // given the labels.
                    items={FILTERS}
                    value={filters.filter}
                    onValueChange={(next) => {
                        if (typeof next === 'string') {
                            ask({ filter: next as RosterFilter });
                        }
                    }}
                >
                    <SelectTrigger
                        className="w-52 pointer-coarse:h-11"
                        aria-label="Filter clients"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {FILTERS.map((filter) => (
                            <SelectItem key={filter.value} value={filter.value}>
                                {filter.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p aria-live="polite" className="text-sm text-muted-foreground">
                    {filtered}
                </p>
            </form>

            {roster.clients.length === 0 ? (
                <Card>
                    <CardContent className="grid grid-cols-1 gap-3">
                        {roster.total_count === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No clients yet. A client appears here with its
                                revenue as soon as it is added and invoiced.
                            </p>
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    No client matches this search and filter.
                                </p>
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setQuery('');
                                            ask({ q: '', filter: 'all' });
                                        }}
                                    >
                                        Clear search and filter
                                    </Button>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            ) : (
                <>
                    <Card className="hidden md:flex">
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <SortHead
                                            filters={filters}
                                            onSort={sortBy}
                                            sort="name"
                                            className="min-w-48"
                                        >
                                            Client
                                        </SortHead>
                                        <SortHead
                                            filters={filters}
                                            onSort={sortBy}
                                            sort="mrr"
                                            className="text-right"
                                        >
                                            MRR <TermTip term="MRR" />
                                        </SortHead>
                                        <SortHead
                                            filters={filters}
                                            onSort={sortBy}
                                            sort="ytd"
                                            className="text-right"
                                        >
                                            YTD
                                        </SortHead>
                                        <SortHead
                                            filters={filters}
                                            onSort={sortBy}
                                            sort="ttm"
                                            className="text-right"
                                        >
                                            TTM
                                        </SortHead>
                                        <SortHead
                                            filters={filters}
                                            onSort={sortBy}
                                            sort="overdue"
                                        >
                                            Open / overdue
                                        </SortHead>
                                        <TableHead scope="col">
                                            Agreement health
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {roster.clients.map((row) => {
                                        const meter = meterOf(row, health);

                                        return (
                                            <TableRow key={row.client_id}>
                                                <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                                    <ClientName row={row} />
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {money(row.mrr)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {money(row.ytd)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Sparkline
                                                            series={
                                                                row.ttm.series
                                                            }
                                                        />
                                                        <span className="tabular-nums">
                                                            {money(
                                                                row.ttm.amount,
                                                            )}
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    <OpenCell
                                                        row={row}
                                                        currency={currency}
                                                    />
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    <RetainerMeter
                                                        status={meter.status}
                                                        minutesUsed={meter.used}
                                                        minutesIncluded={
                                                            meter.included
                                                        }
                                                        over={meter.over}
                                                        endingSoon={
                                                            row.ending_soon
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-medium">
                                            {filters.q !== '' ||
                                            filters.filter !== 'all'
                                                ? `Total of ${roster.clients.length} shown`
                                                : 'Total'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(roster.totals.mrr)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(roster.totals.ytd)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(roster.totals.ttm)}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            <span className="tabular-nums">
                                                {money(
                                                    roster.totals.open_amount,
                                                )}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {money(
                                                    roster.totals
                                                        .overdue_amount,
                                                )}{' '}
                                                overdue (
                                                {roster.totals.overdue_count})
                                            </span>
                                        </TableCell>
                                        <TableCell />
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </CardContent>
                    </Card>

                    <ul
                        className="grid grid-cols-1 gap-3 md:hidden"
                        aria-label="Clients"
                    >
                        {roster.clients.map((row) => {
                            const meter = meterOf(row, health);

                            return (
                                <li key={row.client_id} className="min-w-0">
                                    <Card size="sm">
                                        <CardContent className="grid grid-cols-1 gap-3">
                                            <p className="text-sm">
                                                <ClientName row={row} />
                                            </p>
                                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                                <div className="min-w-0">
                                                    <dt className="text-xs text-muted-foreground">
                                                        MRR
                                                    </dt>
                                                    <dd className="font-medium wrap-anywhere tabular-nums">
                                                        {money(row.mrr)}
                                                    </dd>
                                                </div>
                                                <div className="min-w-0">
                                                    <dt className="text-xs text-muted-foreground">
                                                        Open balance
                                                    </dt>
                                                    <dd className="font-medium wrap-anywhere tabular-nums">
                                                        {money(row.open_amount)}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <RetainerMeter
                                                status={meter.status}
                                                minutesUsed={meter.used}
                                                minutesIncluded={meter.included}
                                                over={meter.over}
                                                endingSoon={row.ending_soon}
                                            />
                                        </CardContent>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                    <Card size="sm" className="md:hidden">
                        <CardContent className="grid grid-cols-2 gap-3 text-sm">
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">
                                    Total MRR
                                </p>
                                <p className="font-medium wrap-anywhere tabular-nums">
                                    {money(roster.totals.mrr)}
                                </p>
                            </div>
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">
                                    Total open
                                </p>
                                <p className="font-medium wrap-anywhere tabular-nums">
                                    {money(roster.totals.open_amount)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    );
}
