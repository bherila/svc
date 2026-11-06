import { Link } from '@inertiajs/react';
import { ChevronRightIcon, ClockIcon } from 'lucide-react';
import { RetainerMeter } from '@/components/revenue/retainer-meter';
import { RevenueSection } from '@/components/revenue/revenue-section';
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
import { formatDay } from '@/lib/datetime';
import { statusLabel, statusNextStep } from '@/lib/labels';
import { formatMoney } from '@/lib/money';
import type { ClientOverview, ThisPeriodRow } from '@/types/revenue';

/**
 * The client overview's money and engagement (docs/ux-overhaul.md section 4),
 * for owners and admins: the same key figures and 24-month chart as the
 * Dashboard scoped to one client, this period's retainer use, the active
 * agreements as cards, the recent invoices, and the client's projects and
 * proposals - the pieces of the retired Operations screen that belong to one
 * client.
 *
 * Each section shows a handful of rows and a link to the module that holds
 * the rest. Every figure and every URL is the server's.
 */

function Heading({
    id,
    children,
    href,
    linkLabel,
}: {
    id?: string;
    children: React.ReactNode;
    href?: string | null;
    linkLabel?: string;
}) {
    return (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2
                id={id}
                className="scroll-mt-20 text-[17px] font-semibold tracking-tight md:text-lg"
            >
                {children}
            </h2>
            {href && linkLabel && (
                <Link
                    href={href}
                    className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    {linkLabel}
                </Link>
            )}
        </div>
    );
}

/** Retainer hours used against the hours included, for each agreement in force. */
function ThisPeriod({ rows }: { rows: ThisPeriodRow[] | undefined }) {
    return (
        <Card data-slot="this-period">
            <CardHeader>
                <CardTitle>This period</CardTitle>
            </CardHeader>
            <CardContent>
                {rows === undefined ? (
                    <p className="text-sm text-muted-foreground">
                        Reading this period&rsquo;s hours…
                    </p>
                ) : rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Retainer use appears here once an active agreement
                        includes hours.
                    </p>
                ) : (
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {rows.map((row) => (
                            <li
                                key={row.agreement_id}
                                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3"
                            >
                                <div className="min-w-0 flex-1 basis-56">
                                    <p className="text-sm font-medium wrap-anywhere">
                                        <Link
                                            href={row.href}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {row.title}
                                        </Link>
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDay(row.period_start)} to{' '}
                                        {formatDay(row.period_end)}
                                    </p>
                                </div>
                                <div className="min-w-0 basis-48">
                                    <RetainerMeter
                                        status={row.ledger_status}
                                        minutesUsed={row.minutes_used}
                                        minutesIncluded={row.minutes_included}
                                        over={row.over_retainer === true}
                                        endingSoon={row.ends_within_60_days}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function Agreements({ overview }: { overview: ClientOverview }) {
    const currency = overview.assumptions.currency;
    const money = (amount: number) => formatMoney(amount, currency);

    return (
        <section
            className="grid grid-cols-1 gap-3"
            aria-labelledby="agreements"
        >
            <Heading id="agreements">Agreements</Heading>
            {overview.agreements.length === 0 ? (
                <Card>
                    <CardContent>
                        <p className="text-sm text-muted-foreground">
                            No active agreement. Terms, cadence and recurring
                            services appear here once an agreement is activated.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))] gap-4">
                    {overview.agreements.map((agreement) => (
                        <Card key={agreement.agreement_id}>
                            <CardHeader>
                                <CardTitle className="wrap-anywhere">
                                    <Link
                                        href={agreement.href}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {agreement.title}
                                    </Link>
                                </CardTitle>
                                <p className="text-sm text-muted-foreground">
                                    {statusLabel(agreement.billing_cadence)}
                                    {' · '}
                                    {agreement.ends_on === null
                                        ? 'no end date'
                                        : `ends ${formatDay(agreement.ends_on)}`}
                                </p>
                            </CardHeader>
                            <CardContent className="grid grid-cols-1 gap-3 text-sm">
                                <dl className="grid grid-cols-2 gap-3">
                                    <div className="min-w-0">
                                        <dt className="text-xs text-muted-foreground">
                                            Retainer
                                        </dt>
                                        <dd className="font-medium wrap-anywhere tabular-nums">
                                            {money(
                                                agreement.retainer.period_fee,
                                            )}
                                        </dd>
                                    </div>
                                    <div className="min-w-0">
                                        <dt className="text-xs text-muted-foreground">
                                            Recurring a month
                                        </dt>
                                        <dd className="font-medium wrap-anywhere tabular-nums">
                                            {money(agreement.monthly_recurring)}
                                        </dd>
                                    </div>
                                </dl>
                                {agreement.recurring_items.length > 0 && (
                                    <ul className="grid grid-cols-1 divide-y divide-border">
                                        {agreement.recurring_items.map(
                                            (item) => (
                                                <li
                                                    key={item.item_id}
                                                    className="py-2"
                                                >
                                                    <p className="wrap-anywhere">
                                                        {item.description}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {money(item.amount)}{' '}
                                                        {statusLabel(
                                                            item.cadence,
                                                        ).toLowerCase()}
                                                        {item.next_charge_on !==
                                                            null &&
                                                            ` · next charged ${formatDay(item.next_charge_on)}`}
                                                    </p>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </section>
    );
}

function RecentInvoices({
    overview,
    allHref,
}: {
    overview: ClientOverview;
    allHref: string | null;
}) {
    return (
        <section className="grid grid-cols-1 gap-3">
            <Heading href={allHref} linkLabel="All invoices">
                Recent invoices
            </Heading>
            <Card>
                <CardContent>
                    {overview.recent_invoices.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No invoices yet. They appear here once the first one
                            is issued.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col" className="min-w-40">
                                        Invoice
                                    </TableHead>
                                    <TableHead scope="col">Status</TableHead>
                                    <TableHead scope="col">Issued</TableHead>
                                    <TableHead scope="col">Due</TableHead>
                                    <TableHead
                                        scope="col"
                                        className="text-right"
                                    >
                                        Total
                                    </TableHead>
                                    <TableHead
                                        scope="col"
                                        className="text-right"
                                    >
                                        Balance
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {overview.recent_invoices.map((invoice) => (
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
                    )}
                </CardContent>
            </Card>
        </section>
    );
}

/**
 * Each project, with a link that opens the time sheet on it. The name is the
 * row's prose: it takes the room the link leaves and wraps, so a long project
 * name can never run underneath its own "Log time" link.
 */
function Projects({ overview }: { overview: ClientOverview }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Projects</CardTitle>
            </CardHeader>
            <CardContent>
                {overview.projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Time is logged to a project. Add one in client settings
                        and it appears here with a link to log time.
                    </p>
                ) : (
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {overview.projects.map((project) => (
                            <li
                                key={project.id}
                                className="flex min-h-11 flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2"
                            >
                                <p className="min-w-0 flex-1 basis-48 text-sm font-medium wrap-anywhere">
                                    <Link
                                        href={project.href}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {project.name}
                                    </Link>
                                </p>
                                <Link
                                    href={project.log_time_href}
                                    className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`Log time: ${project.name}`}
                                >
                                    <ClockIcon
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    Log time
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function Proposals({ overview }: { overview: ClientOverview }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Proposals</CardTitle>
            </CardHeader>
            <CardContent>
                {overview.proposals.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No proposals yet.
                    </p>
                ) : (
                    <ul className="grid grid-cols-1 divide-y divide-border">
                        {overview.proposals.map((proposal) => (
                            <li
                                key={proposal.id}
                                className="flex min-h-11 flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2"
                            >
                                <div className="min-w-0 flex-1 basis-48">
                                    <p className="text-sm font-medium wrap-anywhere">
                                        {proposal.title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {statusLabel(proposal.status)}
                                        {proposal.sent_at !== null &&
                                            ` · ${formatDay(proposal.sent_at)}`}
                                    </p>
                                </div>
                                <Link
                                    href={proposal.href}
                                    className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`View proposal: ${proposal.title}`}
                                >
                                    View
                                    <ChevronRightIcon
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

export function ClientOverviewSections({
    overview,
    thisPeriod,
    invoicesHref,
}: {
    overview: ClientOverview;
    /** Undefined until the deferred read of the capacity ledgers arrives. */
    thisPeriod: ThisPeriodRow[] | undefined;
    invoicesHref: string | null;
}) {
    return (
        <>
            <RevenueSection
                variant="client"
                only="overview"
                toggleHref={overview.toggle_href}
                filters={overview.filters}
                assumptions={overview.assumptions}
                kpis={overview.kpis}
                months={overview.months}
            />
            <ThisPeriod rows={thisPeriod} />
            <Agreements overview={overview} />
            <RecentInvoices overview={overview} allHref={invoicesHref} />
            <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-4">
                <Projects overview={overview} />
                <Proposals overview={overview} />
            </div>
        </>
    );
}
