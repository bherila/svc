import { Head, Link } from '@inertiajs/react';
import {
    AlertCircleIcon,
    CalendarClockIcon,
    CheckSquareIcon,
    ChevronRightIcon,
    ClockIcon,
    FilePenLineIcon,
    FileSignatureIcon,
    InboxIcon,
} from 'lucide-react';
import { AtRiskList } from '@/components/revenue/at-risk-list';
import { RevenueSection } from '@/components/revenue/revenue-section';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useDurationStyle } from '@/hooks/use-duration-style';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatDay, formatTimestampDate } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { DashboardRevenue } from '@/types/revenue';

export type QueueKind =
    | 'overdue_invoice'
    | 'schedule_due'
    | 'draft_invoice'
    | 'unapproved_time'
    | 'proposal_awaiting'
    | 'package_requests'
    | 'enrollment_review'
    | 'enrollment_paid_after_lapse'
    | 'enrollment_autopay_off'
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
     * anyone else, so a member's page has no figure and nothing to hide.
     */
    revenue?: DashboardRevenue;
};

const ICONS = {
    overdue_invoice: AlertCircleIcon,
    schedule_due: CalendarClockIcon,
    draft_invoice: FilePenLineIcon,
    unapproved_time: ClockIcon,
    proposal_awaiting: FileSignatureIcon,
    package_requests: InboxIcon,
    enrollment_review: FileSignatureIcon,
    enrollment_paid_after_lapse: AlertCircleIcon,
    enrollment_autopay_off: AlertCircleIcon,
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
        case 'package_requests':
            // The row's date is the oldest open request.
            fact = [
                row.count === null ? null : `${row.count} waiting`,
                row.date === null
                    ? null
                    : `oldest ${row.date.includes('T') ? formatTimestampDate(row.date) : day}`,
            ]
                .filter((part) => part !== null)
                .join(', ');
            break;
        case 'enrollment_review':
            // The row's date is the day the client accepted.
            fact = day === null ? 'Accepted' : `Accepted ${day}`;
            break;
        case 'enrollment_paid_after_lapse':
            // The row's date is the day the sign-up lapsed.
            fact = day === null ? 'Lapsed' : `Lapsed ${day}`;
            break;
        case 'enrollment_autopay_off':
            // The row's date is the day the service started.
            fact = day === null ? 'Active' : `Active since ${day}`;
            break;
        case 'my_task':
            fact = day === null ? 'No due date' : `Due ${day}`;
            break;
    }

    // A row with no client (sign-up requests belong to none yet) is just its fact.
    return [row.client, fact]
        .filter((part) => part !== null && part !== '')
        .join(' · ');
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

                {revenue !== undefined && (
                    <>
                        <RevenueSection
                            variant="workspace"
                            only="revenue"
                            toggleHref={revenue.toggle_href}
                            filters={revenue.filters}
                            assumptions={revenue.assumptions}
                            kpis={revenue.kpis}
                            months={revenue.months}
                        />
                        <AtRiskList
                            atRisk={revenue.at_risk}
                            currency={revenue.assumptions.currency}
                        />
                    </>
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
