import { Head, Link } from '@inertiajs/react';
import { AppearanceSelector } from '@/components/appearance-selector';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    ClientIndexProps,
    CompanyRow,
    RetainerUsage,
} from '@/types/clients';

/**
 * How much of this period's retainer is gone.
 *
 * The bar is capped at full while the caption is not, so an over-run reads as
 * an over-run rather than as a bar that stopped growing. `capacity_minutes` can
 * legitimately be zero - an agreement whose retainer is priced but not stocked
 * - and dividing by it would render a NaN-wide bar.
 */
function RetainerMeter({ retainer }: { retainer: RetainerUsage }) {
    const fraction =
        retainer.capacity_minutes > 0
            ? Math.min(1, retainer.used_minutes / retainer.capacity_minutes)
            : 0;
    const over = retainer.over_minutes > 0;

    return (
        <div className="grid gap-1">
            <div className="flex flex-wrap items-baseline gap-x-2 text-sm">
                <span className="font-medium tabular-nums">
                    {formatHours(retainer.used_minutes)} /{' '}
                    {formatHours(retainer.capacity_minutes)}
                </span>
                <span className="text-xs text-muted-foreground">
                    {retainer.agreement}
                </span>
            </div>
            <div
                className="h-1.5 w-32 overflow-hidden rounded-full bg-muted"
                role="presentation"
            >
                <div
                    className={cn(
                        'h-full rounded-full',
                        over ? 'bg-destructive' : 'bg-primary',
                    )}
                    style={{ width: `${Math.round(fraction * 100)}%` }}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {over
                    ? `${formatHours(retainer.over_minutes)} over`
                    : `${formatHours(retainer.remaining_minutes)} left`}{' '}
                · {retainer.period_start} to {retainer.period_end}
            </p>
        </div>
    );
}

function CompanyRowCells({
    workspaceId,
    company,
}: {
    workspaceId: string;
    company: CompanyRow;
}) {
    return (
        <TableRow>
            <TableCell>
                <Link
                    href={`/workspaces/${workspaceId}/clients/${company.id}`}
                    className="font-medium underline-offset-4 hover:underline"
                >
                    {company.name}
                </Link>
                {!company.is_active && (
                    <Badge variant="outline" className="ml-2">
                        Inactive
                    </Badge>
                )}
                {company.billing_email !== null && (
                    <p className="text-xs text-muted-foreground">
                        {company.billing_email}
                    </p>
                )}
            </TableCell>
            <TableCell className="tabular-nums">
                {company.project_count}
            </TableCell>
            <TableCell className="tabular-nums">
                {company.draft_invoice_count}
            </TableCell>
            <TableCell className="tabular-nums">
                {company.open_invoice_count}
            </TableCell>
            <TableCell>
                {company.retainer === null ? (
                    <span className="text-sm text-muted-foreground">
                        No active retainer
                    </span>
                ) : (
                    <RetainerMeter retainer={company.retainer} />
                )}
            </TableCell>
        </TableRow>
    );
}

export default function ClientIndex({
    workspace,
    companies,
}: ClientIndexProps) {
    return (
        <>
            <Head title={`${workspace.name} clients`} />
            <main
                className="mx-auto grid max-w-6xl gap-6 p-6"
                data-appearance-bridge
            >
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="grid gap-1">
                        <Link
                            href="/app"
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            ← Workspaces
                        </Link>
                        <h1 className="text-2xl font-semibold">
                            {workspace.name} clients
                        </h1>
                    </div>
                    <AppearanceSelector />
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {companies.length === 1
                                ? '1 client company'
                                : `${companies.length} client companies`}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {companies.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No client companies in this workspace yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Client</TableHead>
                                        <TableHead>Projects</TableHead>
                                        <TableHead>Draft invoices</TableHead>
                                        <TableHead>Open invoices</TableHead>
                                        <TableHead>
                                            Retainer this period
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {companies.map((company) => (
                                        <CompanyRowCells
                                            key={company.id}
                                            workspaceId={workspace.id}
                                            company={company}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
                <p className="text-xs text-muted-foreground">
                    Retainer figures count approved work inside the current
                    billing cycle only. The running balance, including rollover
                    carried in from earlier cycles, is on the{' '}
                    <Link
                        href={`/workspaces/${workspace.id}/time`}
                        className="underline underline-offset-4"
                    >
                        time sheet
                    </Link>
                    .
                </p>
            </main>
        </>
    );
}
