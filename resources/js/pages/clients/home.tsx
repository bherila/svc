import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatMoney } from '@/lib/money';
import { formatHours } from '@/lib/time';

/**
 * One client, at a glance.
 *
 * The two screens this replaced were unbounded in opposite directions. The
 * internal one sent every project, every agreement and twenty invoices; the
 * client-facing one sent the client's entire visible record - projects, tasks,
 * time, proposals, agreements, invoices and attachments - and rendered it as a
 * wall of cards, one per record. Both grew with the engagement, so the longer a
 * client had been worked for the less either screen said.
 *
 * This asks four questions instead: what is the most recent billing event, what
 * work happened recently, what needs attention, and where is the full history.
 * Each section shows a handful of rows and one link to its module, because the
 * module is where an unbounded list belongs.
 *
 * Rows and dividers rather than cards. A card is a frame around something worth
 * stopping at; a border around each of eleven records frames nothing and turns
 * a page you scan into a page you scroll.
 *
 * Rendered for both an operator and a client. Which of them is looking changed
 * the queries behind this - fail-closed and quite different - and does not
 * change this file, which is the entire point: one experience, two authorities.
 */

type InvoiceSummary = {
    id: string;
    invoice_number: string;
    status: string;
    currency: string;
    issue_date: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    href: string;
};

type TimeRow = {
    id: string;
    worked_on: string;
    project: string | null;
    description: string | null;
    minutes: number;
};

type TaskRow = {
    id: string;
    title: string;
    project: string | null;
    status: string;
};

type Engagement = {
    agreement_title: string | null;
    agreement_status: string | null;
    agreement_cadence: string | null;
    agreement_href: string | null;
    proposal_title: string | null;
    proposal_status: string | null;
    proposal_href: string | null;
};

export type ClientHomeProps = {
    company: { id: string; name: string };
    latest_invoice: InvoiceSummary | null;
    recent_time: TimeRow[];
    open_tasks: TaskRow[];
    engagement: Engagement | null;
    links: {
        invoices: string | null;
        time: string | null;
        tasks: string | null;
    };
    settings_href: string | null;
};

/**
 * A section, its rows, and one way to see the rest.
 *
 * The "view all" link is part of the section rather than an afterthought at the
 * bottom of the page: a preview that does not say where the whole thing lives
 * is just a truncated list.
 */
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
        <section className="border-t border-border pt-6">
            <div className="flex items-baseline justify-between gap-4">
                <h2 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                    {title}
                </h2>
                {href !== null && (
                    <Link
                        href={href}
                        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {viewAll}
                    </Link>
                )}
            </div>

            {isEmpty ? (
                <p className="mt-3 text-sm text-muted-foreground">{empty}</p>
            ) : (
                <div className="mt-3">{children}</div>
            )}
        </section>
    );
}

export default function ClientHome({
    company,
    latest_invoice: latestInvoice,
    recent_time: recentTime,
    open_tasks: openTasks,
    engagement,
    links,
    settings_href: settingsHref,
}: ClientHomeProps) {
    return (
        <WorkspaceShell activeModule="home">
            <Head title={company.name} />

            <main className="mx-auto grid max-w-4xl grid-cols-1 gap-6 px-6 py-8">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="min-w-0 text-2xl font-semibold tracking-tight wrap-anywhere">
                        {company.name}
                    </h1>
                    {settingsHref !== null && (
                        <Button
                            variant="outline"
                            size="sm"
                            render={
                                <Link href={settingsHref}>Client settings</Link>
                            }
                        />
                    )}
                </header>

                {/*
                 * What is waiting on someone, if anything. A banner rather than
                 * a form: accepting a proposal is a decision, and a decision
                 * taken beside a list of five other things is one taken without
                 * reading it.
                 */}
                {engagement !== null && engagement.proposal_href !== null && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        <span>
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
                    </div>
                )}

                <Section
                    title="Latest invoice"
                    href={links.invoices}
                    viewAll="All invoices"
                    empty="No invoices yet."
                    isEmpty={latestInvoice === null}
                >
                    {latestInvoice !== null && (
                        <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border px-4 py-3">
                            <div className="grid min-w-0 grid-cols-1 gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link
                                        href={latestInvoice.href}
                                        className="font-medium wrap-anywhere underline-offset-4 hover:underline"
                                    >
                                        {latestInvoice.invoice_number}
                                    </Link>
                                    <Badge variant="outline">
                                        {latestInvoice.status}
                                    </Badge>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {latestInvoice.issue_date ?? 'Undated'}
                                    {latestInvoice.due_date !== null &&
                                        ` · due ${latestInvoice.due_date}`}
                                </p>
                            </div>
                            <div className="text-end">
                                <p className="font-medium tabular-nums">
                                    {formatMoney(
                                        latestInvoice.total_amount,
                                        latestInvoice.currency,
                                    )}
                                </p>
                                {latestInvoice.balance_amount > 0 && (
                                    <p className="text-sm text-muted-foreground tabular-nums">
                                        {formatMoney(
                                            latestInvoice.balance_amount,
                                            latestInvoice.currency,
                                        )}{' '}
                                        outstanding
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Section>

                <Section
                    title="Recent time"
                    href={links.time}
                    viewAll="All time"
                    empty="No recent time."
                    isEmpty={recentTime.length === 0}
                >
                    <ul className="divide-y divide-border">
                        {recentTime.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-baseline justify-between gap-4 py-2 text-sm"
                            >
                                <span className="w-24 shrink-0 text-muted-foreground tabular-nums">
                                    {entry.worked_on}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {entry.description ?? '—'}
                                    {entry.project !== null && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {entry.project}
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 tabular-nums">
                                    {formatHours(entry.minutes)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>

                <Section
                    title="Open tasks"
                    href={links.tasks}
                    viewAll="All tasks"
                    empty="No open tasks."
                    isEmpty={openTasks.length === 0}
                >
                    <ul className="divide-y divide-border">
                        {openTasks.map((task) => (
                            <li
                                key={task.id}
                                className="flex items-baseline justify-between gap-4 py-2 text-sm"
                            >
                                <span className="min-w-0 flex-1 truncate">
                                    {task.title}
                                    {task.project !== null && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {task.project}
                                        </span>
                                    )}
                                </span>
                                <Badge variant="outline">{task.status}</Badge>
                            </li>
                        ))}
                    </ul>
                </Section>

                {/*
                 * What this client is engaged under. One line, because the
                 * terms are a page of their own and repeating them here would
                 * be a second, shorter, subtly different copy of them.
                 */}
                {engagement !== null && engagement.agreement_href !== null && (
                    <section className="border-t border-border pt-6">
                        <h2 className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                            Engagement
                        </h2>
                        <p className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <Link
                                href={engagement.agreement_href}
                                className="font-medium underline-offset-4 hover:underline"
                            >
                                {engagement.agreement_title}
                            </Link>
                            <Badge variant="outline">
                                {engagement.agreement_status}
                            </Badge>
                            {engagement.agreement_cadence !== null && (
                                <span className="text-muted-foreground">
                                    {engagement.agreement_cadence.replace(
                                        /_/g,
                                        ' ',
                                    )}
                                </span>
                            )}
                        </p>
                    </section>
                )}
            </main>
        </WorkspaceShell>
    );
}
