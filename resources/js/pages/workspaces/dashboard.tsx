import { Head, Link } from '@inertiajs/react';
import {
    AlertCircleIcon,
    CalendarClockIcon,
    CheckSquareIcon,
    ChevronRightIcon,
    ClockIcon,
    FilePenLineIcon,
    FileSignatureIcon,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export type QueueKind =
    | 'overdue_invoice'
    | 'schedule_due'
    | 'draft_invoice'
    | 'unapproved_time'
    | 'proposal_awaiting'
    | 'my_draft_time'
    | 'my_task';

export type QueueRow = {
    id: string;
    kind: QueueKind;
    title: string;
    client: string;
    href: string;
    action: string;
    amount: number | null;
    currency: string | null;
    date: string | null;
    count: number | null;
    minutes: number | null;
};

export type DashboardProps = {
    workspace: { id: string; name: string };
    audience: 'workspace' | 'member';
    queue: QueueRow[];
    /**
     * Present for owners and admins only - the key is absent, not null, for
     * anyone else. The revenue lane mounts the KPI strip and chart here.
     */
    revenue?: { slot: 'revenue' };
};

const ICONS = {
    overdue_invoice: AlertCircleIcon,
    schedule_due: CalendarClockIcon,
    draft_invoice: FilePenLineIcon,
    unapproved_time: ClockIcon,
    proposal_awaiting: FileSignatureIcon,
    my_draft_time: ClockIcon,
    my_task: CheckSquareIcon,
} satisfies Record<QueueKind, typeof ClockIcon>;

/** The one muted line under a row's title: the client, then the one fact. */
function detail(row: QueueRow, style: DurationStyle): string {
    const day = row.date === null ? null : formatDay(row.date);
    const entries =
        row.count === null
            ? null
            : `${row.count} ${row.count === 1 ? 'entry' : 'entries'}${
                  row.minutes === null
                      ? ''
                      : `, ${formatDuration(row.minutes, style)}`
              }`;
    const money =
        row.amount === null ? null : formatMoney(row.amount, row.currency);
    let fact: string | null;

    switch (row.kind) {
        case 'overdue_invoice':
            fact = `${money ?? ''} overdue since ${day ?? 'an unknown date'}`;
            break;
        case 'schedule_due':
            fact = `Due ${day ?? 'now'}`;
            break;
        case 'draft_invoice':
            fact = money === null ? 'Draft' : `Draft, ${money}`;
            break;
        case 'unapproved_time':
        case 'my_draft_time':
            fact = entries;
            break;
        case 'proposal_awaiting':
            fact = day === null ? 'Sent' : `Sent ${day}`;
            break;
        case 'my_task':
            fact = day === null ? 'No due date' : `Due ${day}`;
            break;
    }

    return fact === null ? row.client : `${row.client} · ${fact}`;
}

export default function Dashboard({
    audience,
    queue,
    revenue,
}: DashboardProps) {
    const style = useDurationStyle();

    return (
        <WorkspaceShell activeSection="dashboard">
            <Head title="Dashboard" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <h1 className="text-[17px] font-semibold tracking-tight md:text-lg">
                    Dashboard
                </h1>

                {/*
                 * REVENUE SLOT. The revenue lane mounts the KPI strip and the
                 * revenue chart here, above the queue. The server sends the
                 * `revenue` prop to owners and admins only, so a member's page
                 * has no slot and nothing to fill it with.
                 */}
                {revenue !== undefined && (
                    <section
                        aria-label="Revenue"
                        data-slot="revenue-slot"
                        className="min-w-0"
                    />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {audience === 'member' ? 'Your work' : 'Needs you'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {queue.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing needs you
                            </p>
                        ) : (
                            <ul className="grid grid-cols-1 divide-y divide-border">
                                {queue.map((row) => {
                                    const Icon = ICONS[row.kind];

                                    return (
                                        <li
                                            key={row.id}
                                            className="flex min-h-11 items-center gap-3 py-2"
                                        >
                                            <Icon
                                                aria-hidden="true"
                                                className="size-5 shrink-0 text-muted-foreground"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium wrap-anywhere">
                                                    {row.title}
                                                </p>
                                                <p className="text-xs wrap-anywhere text-muted-foreground">
                                                    {detail(row, style)}
                                                </p>
                                            </div>
                                            <Link
                                                href={row.href}
                                                className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                aria-label={`${row.action}: ${row.title}`}
                                            >
                                                {row.action}
                                                <ChevronRightIcon
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </main>
        </WorkspaceShell>
    );
}
