import { Head, Link } from '@inertiajs/react';
import { AlertCircleIcon, CreditCardIcon, FileTextIcon } from 'lucide-react';
import { ToApprove } from '@/components/portal/to-approve';
import { RetainerMeter } from '@/components/revenue/retainer-meter';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay, formatShortDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import { statusLabel, statusNextStep } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PortalHomeProps, PortalThisPeriod } from '@/types/portal';

/** "due Nov 15 (in 9 days)", "due today", "due Nov 1 (3 days overdue)". */
function dueText(dueDate: string | null, days: number | null): string {
    if (dueDate === null) {
        return 'no due date';
    }

    const when = `due ${formatDay(dueDate)}`;

    if (days === null) {
        return when;
    }

    if (days === 0) {
        return `${when} (today)`;
    }

    const count = Math.abs(days);
    const unit = count === 1 ? 'day' : 'days';

    return days > 0
        ? `${when} (in ${count} ${unit})`
        : `${when} (${count} ${unit} overdue)`;
}

function BalanceCard({
    balance,
    lastPayment,
    pay,
}: {
    balance: PortalHomeProps['balance'];
    lastPayment: PortalHomeProps['last_payment'];
    pay: PortalHomeProps['pay'];
}) {
    const first = balance.first;
    const owing = balance.invoice_count > 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Balance</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3">
                <p className="text-3xl font-semibold tracking-tight wrap-anywhere tabular-nums">
                    {!owing
                        ? 'Nothing due'
                        : balance.amount_due === null
                          ? 'Several currencies'
                          : formatMoney(balance.amount_due, balance.currency)}
                </p>
                {owing && balance.overdue && (
                    <p className="flex items-center gap-1.5 text-sm font-medium text-destructive">
                        <AlertCircleIcon
                            aria-hidden="true"
                            className="size-4"
                        />
                        Overdue
                        {balance.overdue_amount !== null &&
                            `: ${formatMoney(balance.overdue_amount, balance.currency)}`}
                    </p>
                )}
                {first !== null && (
                    <p className="text-sm wrap-anywhere">
                        Invoice {first.number} ·{' '}
                        {dueText(first.due_date, first.days_until_due)}
                        {balance.invoice_count > 1 &&
                            ` · ${balance.invoice_count - 1} more open`}
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    {lastPayment === null
                        ? 'No payments received yet.'
                        : `Last payment ${formatMoney(lastPayment.amount, lastPayment.currency)} · ${formatDay(lastPayment.received_on)}`}
                </p>
            </CardContent>
            {first !== null && (
                // Beside the card's content rather than inside it: Pay is a
                // button that commits in place, the one filled button on the
                // page, and an external page, so a plain anchor.
                <div className="flex flex-wrap items-center gap-3 px-(--card-spacing)">
                    {pay.href !== null && (
                        <a
                            href={pay.href}
                            // The name has to contain the visible text (WCAG 2.5.3).
                            aria-label={`Pay ${formatMoney(first.balance, first.currency)}, invoice ${first.number}`}
                            className={buttonVariants({ size: 'lg' })}
                        >
                            <CreditCardIcon aria-hidden="true" />
                            Pay {formatMoney(first.balance, first.currency)}
                        </a>
                    )}
                    {pay.invoice_href !== null && (
                        <Link
                            href={pay.invoice_href}
                            className="inline-flex min-h-11 items-center gap-1.5 text-sm underline-offset-4 hover:underline md:min-h-8"
                        >
                            <FileTextIcon
                                aria-hidden="true"
                                className="size-4"
                            />
                            View invoice
                        </Link>
                    )}
                </div>
            )}
        </Card>
    );
}

function ThisPeriodCard({
    period,
    style,
}: {
    period: PortalThisPeriod | null;
    style: DurationStyle;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>This period</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3">
                {period === null ? (
                    <p className="text-sm text-muted-foreground">
                        Once an agreement is in force, the hours used and your
                        next invoice appear here.
                    </p>
                ) : (
                    <>
                        <p className="text-sm wrap-anywhere text-muted-foreground">
                            <Link
                                href={period.agreement.href}
                                className="underline-offset-4 hover:underline"
                            >
                                {period.agreement.title}
                            </Link>{' '}
                            · {formatShortDay(period.period_start)} to{' '}
                            {formatShortDay(period.period_end)}
                        </p>
                        {period.use !== null && (
                            <div className="grid grid-cols-1 gap-1">
                                <RetainerMeter
                                    status="ok"
                                    minutesUsed={period.use.minutes_used}
                                    minutesIncluded={
                                        period.use.minutes_included +
                                        period.use.rollover_minutes
                                    }
                                    over={period.use.overage_minutes > 0}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {period.use.rollover_minutes > 0
                                        ? `Includes ${formatDuration(period.use.rollover_minutes, style)} rolled over.`
                                        : 'No hours rolled over.'}
                                </p>
                            </div>
                        )}
                        {period.still_deferred !== null && (
                            <p className="text-sm">
                                {formatDuration(
                                    Math.round(
                                        period.still_deferred.total_hours * 60,
                                    ),
                                    style,
                                )}{' '}
                                of work is still deferred, the oldest from{' '}
                                {formatDay(
                                    period.still_deferred.oldest_worked_on,
                                )}
                                .
                            </p>
                        )}
                        <NextInvoice
                            estimate={period.next_invoice}
                            style={style}
                        />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function NextInvoice({
    estimate,
    style,
}: {
    estimate: PortalThisPeriod['next_invoice'];
    style: DurationStyle;
}) {
    return (
        <section
            aria-label="Next invoice (estimate)"
            className="grid grid-cols-1 gap-2 border-t border-border pt-3"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                <h3 className="text-sm font-semibold">
                    Next invoice (estimate)
                </h3>
                {estimate !== null && (
                    <p className="text-sm font-semibold tabular-nums">
                        {formatMoney(estimate.total, estimate.currency)}
                    </p>
                )}
            </div>
            {estimate === null ? (
                <p className="text-sm text-muted-foreground">
                    No recurring charges are scheduled yet.
                </p>
            ) : (
                <>
                    <p className="text-xs text-muted-foreground">
                        For {formatShortDay(estimate.period_start)} to{' '}
                        {formatShortDay(estimate.period_end)}
                    </p>
                    <ul className="grid grid-cols-1 divide-y divide-border text-sm">
                        {estimate.parts.map((part, index) => (
                            <li
                                key={`${part.kind}-${index}`}
                                className="flex flex-wrap items-baseline justify-between gap-x-3 py-1.5"
                            >
                                <span className="min-w-0 wrap-anywhere">
                                    {part.description}
                                    {part.hours !== null &&
                                        ` (${formatDuration(Math.round(part.hours * 60), style)})`}
                                </span>
                                <span className="tabular-nums">
                                    {part.amount === null
                                        ? 'Priced when billed'
                                        : formatMoney(
                                              part.amount,
                                              estimate.currency,
                                          )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        Before {estimate.excludes}. An estimate, not an invoice.
                    </p>
                </>
            )}
        </section>
    );
}

function Section({
    title,
    href,
    viewAll,
    empty,
    isEmpty,
    children,
}: {
    title: string;
    href: string | null;
    viewAll: string;
    empty: string;
    isEmpty: boolean;
    children?: React.ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-baseline justify-between gap-4">
                    <CardTitle>{title}</CardTitle>
                    {href !== null && (
                        <Link
                            href={href}
                            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            {viewAll}
                        </Link>
                    )}
                </div>
            </CardHeader>
            <CardContent>
                {isEmpty ? (
                    <p className="text-sm text-muted-foreground">{empty}</p>
                ) : (
                    children
                )}
            </CardContent>
        </Card>
    );
}

export default function PortalHome({
    company,
    balance,
    last_payment: lastPayment,
    pay,
    this_period: thisPeriod,
    recent_invoices: invoices,
    recent_time: recentTime,
    open_tasks: openTasks,
    engagement,
    links,
    to_approve: toApprove,
    approval_result: approvalResult,
    approvals_href: approvalsHref,
}: PortalHomeProps) {
    const style = useDurationStyle();

    return (
        <WorkspaceShell activeModule="home">
            <Head title={company.name} />

            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="min-w-0 text-2xl font-semibold tracking-tight wrap-anywhere">
                    {company.name}
                </h1>

                {engagement !== null && engagement.proposal_href !== null && (
                    <Card className="bg-muted/40 text-sm ring-border">
                        <CardContent
                            role="status"
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            <span className="min-w-0 wrap-anywhere">
                                <span className="font-medium">
                                    {engagement.proposal_title}
                                </span>{' '}
                                is waiting for a response.
                            </span>
                            <Link
                                href={engagement.proposal_href}
                                className="underline underline-offset-4"
                            >
                                Review proposal
                            </Link>
                        </CardContent>
                    </Card>
                )}

                <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,21rem),1fr))] items-start gap-6">
                    <BalanceCard
                        balance={balance}
                        lastPayment={lastPayment}
                        pay={pay}
                    />
                    <ThisPeriodCard period={thisPeriod} style={style} />
                </div>

                {toApprove !== null && (
                    <ToApprove
                        data={toApprove}
                        result={approvalResult}
                        allHref={approvalsHref}
                    />
                )}

                <Section
                    title="Open tasks"
                    href={links.tasks}
                    viewAll="All tasks"
                    empty="Nothing is open. Tasks your provider is working on appear here."
                    isEmpty={openTasks.length === 0}
                >
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {openTasks.map((task) => (
                            <li
                                key={task.id}
                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2 text-sm"
                            >
                                <span className="min-w-0 flex-1 basis-60 wrap-anywhere">
                                    {task.title}
                                    {task.project !== null && (
                                        <span className="block text-xs text-muted-foreground">
                                            {task.project}
                                        </span>
                                    )}
                                </span>
                                <Badge
                                    variant="outline"
                                    className="shrink-0"
                                    title={
                                        statusNextStep(task.status) ?? undefined
                                    }
                                >
                                    {statusLabel(task.status)}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    title="Recent time"
                    href={links.time}
                    viewAll="All time"
                    empty="No approved time yet. Work appears here once it has been approved."
                    isEmpty={recentTime.length === 0}
                >
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {recentTime.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-start justify-between gap-4 py-2 text-sm"
                            >
                                <span className="w-24 shrink-0 text-muted-foreground tabular-nums">
                                    {formatShortDay(entry.worked_on)}
                                </span>
                                <span className="min-w-0 flex-1 wrap-anywhere">
                                    {entry.description ?? '—'}
                                    {entry.project !== null && (
                                        <span className="block text-xs text-muted-foreground">
                                            {entry.project}
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 tabular-nums">
                                    {formatDuration(entry.minutes, style)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    title="Invoice history"
                    href={links.invoices}
                    viewAll="All invoices"
                    empty="No invoices yet. They appear here once the first one is issued."
                    isEmpty={invoices.length === 0}
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead scope="col" className="min-w-40">
                                    Invoice
                                </TableHead>
                                <TableHead scope="col">Status</TableHead>
                                <TableHead scope="col">Issued</TableHead>
                                <TableHead scope="col">Due</TableHead>
                                <TableHead scope="col" className="text-right">
                                    Total
                                </TableHead>
                                <TableHead scope="col" className="text-right">
                                    Balance
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.map((invoice) => (
                                <TableRow key={invoice.id}>
                                    <TableCell className="max-w-0 wrap-anywhere whitespace-normal">
                                        <Link
                                            href={invoice.href}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {invoice.invoice_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="outline"
                                            title={
                                                statusNextStep(
                                                    invoice.status,
                                                ) ?? undefined
                                            }
                                        >
                                            {statusLabel(invoice.status)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {formatDay(invoice.issue_date)}
                                    </TableCell>
                                    <TableCell>
                                        {formatDay(invoice.due_date)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            invoice.total_amount,
                                            invoice.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            invoice.balance_amount,
                                            invoice.currency,
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Section>
            </main>
        </WorkspaceShell>
    );
}
