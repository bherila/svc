import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { apiRequest } from '@/lib/api';
import type { ApiSchema } from '@/lib/api';
import { formatDay, formatTimestamp } from '@/lib/datetime';
import { statusLabel } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

type MirrorInvoice = ApiSchema<'SquareMirrorInvoice'>;
type Series = ApiSchema<'SquareSeries'>;
type SyncRun = ApiSchema<'SquareSyncRun'>;
type Option = { value: string; label: string };

type FilterName =
    | 'location_id'
    | 'status'
    | 'customer_id'
    | 'series_key'
    | 'due_from'
    | 'due_to';
type Filters = Record<FilterName, string | null>;

const ANY = '__any__';

export type SquareExploreProps = {
    filters: Filters;
    options: {
        locations: Option[];
        customers: Option[];
        statuses: { value: string }[];
    };
    invoices: {
        data: MirrorInvoice[];
        page: number;
        last_page: number;
        total: number;
    };
    series: Series[];
    series_truncated: boolean;
    sync: {
        last_synced_at: string | null;
        last_run: SyncRun | null;
        /** Finished by the server: square.sync for this workspace. */
        sync_url: string;
    };
    /** This screen's own address, to which filters are applied. */
    explore_url: string;
};

function amount(value: number | null, currency: string | null): string {
    return value === null ? '—' : formatMoney(value, currency);
}

function SyncStatus({ sync }: { sync: SquareExploreProps['sync'] }) {
    const [state, setState] = useState<'idle' | 'running'>('idle');
    const [message, setMessage] = useState('');
    const run = sync.last_run;

    async function refresh() {
        setState('running');
        setMessage('Syncing with Square…');
        const result = await apiRequest<SyncRun>('POST', sync.sync_url, {});
        setState('idle');

        if (!result.ok) {
            setMessage(result.message);

            return;
        }

        setMessage(
            result.data.status === 'completed'
                ? `Synced: ${result.data.invoices_changed} invoices changed.`
                : (result.data.error ?? 'The sync did not finish.'),
        );
        router.reload();
    }

    return (
        <section
            aria-label="Square sync"
            className="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
        >
            <div className="grid min-w-0 grid-cols-1 gap-1 text-sm">
                <p>
                    {sync.last_synced_at === null
                        ? 'Not synced yet.'
                        : `Last synced ${formatTimestamp(sync.last_synced_at)}.`}
                </p>
                {run !== null && run.status === 'failed' && (
                    <p className="text-destructive">
                        Latest sync failed: {run.error ?? run.error_code}
                    </p>
                )}
                {run !== null && run.truncated && (
                    <p className="text-muted-foreground">
                        The latest sync stopped at its page limit; some Square
                        records are not mirrored.
                    </p>
                )}
                <p role="status" className="text-muted-foreground">
                    {message}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                onClick={refresh}
                disabled={state === 'running'}
                data-test="square-sync"
            >
                {state === 'running' ? 'Syncing…' : 'Sync now'}
            </Button>
        </section>
    );
}

function FilterSelect({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string | null;
    options: Option[];
    onChange: (value: string | null) => void;
}) {
    const items = [
        { value: ANY, label: `Any ${label.toLowerCase()}` },
        ...options,
    ];

    return (
        <div className="grid min-w-0 grid-cols-1 gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                items={items}
                value={value ?? ANY}
                onValueChange={(next) => {
                    if (typeof next === 'string') {
                        onChange(next === ANY ? null : next);
                    }
                }}
            >
                <SelectTrigger id={id} className="w-full min-w-0">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {items.map((item) => (
                        <SelectItem key={item.value} value={item.value}>
                            {item.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function visit(url: string, filters: Filters, page = 1) {
    const query: Record<string, string> = {};

    for (const [name, value] of Object.entries(filters)) {
        if (value !== null && value !== '') {
            query[name] = value;
        }
    }

    if (page > 1) {
        query.page = String(page);
    }

    router.get(url, query, { preserveScroll: true, preserveState: true });
}

function InvoiceTable({ invoices }: { invoices: MirrorInvoice[] }) {
    if (invoices.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No mirrored Square invoices match.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Number</TableHead>
                        <TableHead className="min-w-48">Customer</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Due</TableHead>
                        <TableHead>Total</TableHead>
                        <TableHead>Paid</TableHead>
                        <TableHead>In Square</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {invoices.map((invoice) => (
                        <TableRow key={invoice.square_invoice_id}>
                            <TableCell className="font-medium">
                                {invoice.invoice_number ?? 'Unnumbered'}
                                {invoice.autopay && (
                                    <Badge variant="outline" className="ml-2">
                                        Autopay
                                    </Badge>
                                )}
                            </TableCell>
                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                {invoice.recipient_name ?? '—'}
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline">
                                    {statusLabel(invoice.status.toLowerCase())}
                                </Badge>
                            </TableCell>
                            <TableCell>{formatDay(invoice.due_on)}</TableCell>
                            <TableCell className="tabular-nums">
                                {amount(invoice.total_amount, invoice.currency)}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {amount(invoice.paid_amount, invoice.currency)}
                            </TableCell>
                            <TableCell>
                                {invoice.public_url === null ? (
                                    '—'
                                ) : (
                                    <a
                                        href={invoice.public_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline-offset-4 hover:underline"
                                    >
                                        Open
                                    </a>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function SeriesTable({ series }: { series: Series[] }) {
    if (series.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No recurring series are mirrored.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Series</TableHead>
                        <TableHead className="min-w-48">Customer</TableHead>
                        <TableHead>Cadence</TableHead>
                        <TableHead>Amount</TableHead>
                        <TableHead>Invoices</TableHead>
                        <TableHead>Next due</TableHead>
                        <TableHead>Open</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {series.map((entry) => (
                        <TableRow key={entry.series_key}>
                            <TableCell className="font-medium tabular-nums">
                                {entry.series_key}
                            </TableCell>
                            <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                {entry.recipient_name ?? '—'}
                            </TableCell>
                            <TableCell>{statusLabel(entry.cadence)}</TableCell>
                            <TableCell className="tabular-nums">
                                {amount(entry.amount, entry.currency)}
                                {entry.amount_varies && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        (varies)
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {entry.invoice_count}
                                {entry.missing_occurrences.length > 0 && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        ({entry.missing_occurrences.length}{' '}
                                        missing)
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                {formatDay(entry.next_due_on)}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {amount(entry.open_amount, entry.currency)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * svc's read-only mirror of the connected Square account (#438): the
 * mirrored invoices with filters, the recurring series, and how fresh the
 * mirror is. Nothing here changes Square; "Sync now" refreshes the mirror.
 */
export default function SquareExplore(props: SquareExploreProps) {
    const {
        filters,
        options,
        invoices,
        series,
        sync,
        explore_url: url,
    } = props;
    const [dates, setDates] = useState({
        due_from: filters.due_from ?? '',
        due_to: filters.due_to ?? '',
    });
    const set = (name: FilterName, value: string | null) =>
        visit(url, { ...filters, [name]: value });

    function applyDates(event: FormEvent) {
        event.preventDefault();
        visit(url, {
            ...filters,
            due_from: dates.due_from === '' ? null : dates.due_from,
            due_to: dates.due_to === '' ? null : dates.due_to,
        });
    }

    return (
        <WorkspaceShell>
            <Head title="Square invoices" />
            <main className={cn(SHELL_CONTAINER, 'py-8')}>
                <div className="grid min-w-0 grid-cols-1 gap-6">
                    <div className="grid min-w-0 grid-cols-1 gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Square invoices
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            A read-only mirror of the connected Square account,
                            refreshed every 15 minutes. Square stays the source
                            of truth; nothing here changes it.
                        </p>
                    </div>
                    <SyncStatus sync={sync} />
                    <section
                        aria-label="Filters"
                        className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <FilterSelect
                            id="square-location"
                            label="Location"
                            value={filters.location_id}
                            options={options.locations}
                            onChange={(value) => set('location_id', value)}
                        />
                        <FilterSelect
                            id="square-status"
                            label="Status"
                            value={filters.status}
                            options={options.statuses.map((status) => ({
                                value: status.value,
                                label: statusLabel(status.value.toLowerCase()),
                            }))}
                            onChange={(value) => set('status', value)}
                        />
                        <FilterSelect
                            id="square-customer"
                            label="Customer"
                            value={filters.customer_id}
                            options={options.customers}
                            onChange={(value) => set('customer_id', value)}
                        />
                        <FilterSelect
                            id="square-series"
                            label="Series"
                            value={filters.series_key}
                            options={series.map((entry) => ({
                                value: entry.series_key,
                                label: `${entry.series_key} · ${entry.recipient_name ?? '—'}`,
                            }))}
                            onChange={(value) => set('series_key', value)}
                        />
                        <form
                            onSubmit={applyDates}
                            className="grid min-w-0 grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end lg:col-span-4"
                        >
                            <div className="grid min-w-0 grid-cols-1 gap-1">
                                <Label htmlFor="square-due-from">
                                    Due from
                                </Label>
                                <Input
                                    id="square-due-from"
                                    type="date"
                                    value={dates.due_from}
                                    onChange={(event) =>
                                        setDates({
                                            ...dates,
                                            due_from: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="grid min-w-0 grid-cols-1 gap-1">
                                <Label htmlFor="square-due-to">Due to</Label>
                                <Input
                                    id="square-due-to"
                                    type="date"
                                    value={dates.due_to}
                                    onChange={(event) =>
                                        setDates({
                                            ...dates,
                                            due_to: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                Apply dates
                            </Button>
                        </form>
                    </section>
                    <section className="grid min-w-0 grid-cols-1 gap-3">
                        <h2 className="text-lg font-semibold">
                            Invoices ({invoices.total})
                        </h2>
                        <InvoiceTable invoices={invoices.data} />
                        {invoices.last_page > 1 && (
                            <nav
                                aria-label="Invoice pages"
                                className="flex flex-wrap items-center gap-3 text-sm"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={invoices.page <= 1}
                                    onClick={() =>
                                        visit(url, filters, invoices.page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <span>
                                    Page {invoices.page} of {invoices.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        invoices.page >= invoices.last_page
                                    }
                                    onClick={() =>
                                        visit(url, filters, invoices.page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </nav>
                        )}
                    </section>
                    <section className="grid min-w-0 grid-cols-1 gap-3">
                        <h2 className="text-lg font-semibold">
                            Recurring series
                        </h2>
                        <SeriesTable series={series} />
                        {props.series_truncated && (
                            <p className="text-sm text-muted-foreground">
                                Showing the first {series.length} series.
                            </p>
                        )}
                    </section>
                </div>
            </main>
        </WorkspaceShell>
    );
}
