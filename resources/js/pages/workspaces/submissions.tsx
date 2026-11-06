import { Head, Link } from '@inertiajs/react';
import {
    CheckIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    MessageSquareWarningIcon,
    PencilLineIcon,
    SendIcon,
    Undo2Icon,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { apiRequest } from '@/lib/api';
import { formatShortDay } from '@/lib/datetime';
import { formatDuration } from '@/lib/duration';
import { describeStatus } from '@/lib/labels';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatMoney } from '@/lib/money';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { cn } from '@/lib/utils';
import type {
    SubmissionExpense,
    SubmissionHour,
    SubmissionState,
    SubmissionsProps,
} from '@/types/submission';

type Body = { time_entry_ids?: string[]; expense_ids?: string[]; period?: string };

const STATE_ICON = {
    draft: PencilLineIcon,
    submitted: SendIcon,
    approved: CheckIcon,
    disputed: MessageSquareWarningIcon,
} as const;

/** The state, as an icon and words with its next step; never colour alone. */
function State({
    state,
    note,
}: {
    state: SubmissionState;
    note: string | null;
}) {
    const Icon = STATE_ICON[state];
    const { label, nextStep } = describeStatus(state);

    return (
        <div className="grid grid-cols-1 gap-0.5">
            <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                <Icon aria-hidden="true" className="size-4 shrink-0" />
                {label}
            </span>
            <span className="text-xs wrap-anywhere text-muted-foreground">
                {note !== null && note !== '' ? `“${note}”` : nextStep}
            </span>
        </div>
    );
}

function RowAction({
    row,
    busy,
    onSubmit,
    onWithdraw,
}: {
    row: SubmissionHour | SubmissionExpense;
    busy: boolean;
    onSubmit: () => void;
    onWithdraw: () => void;
}) {
    if (row.can_withdraw) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="min-h-11 md:min-h-7"
                disabled={busy}
                onClick={onWithdraw}
            >
                <Undo2Icon aria-hidden="true" />
                Withdraw
            </Button>
        );
    }

    if (row.can_submit) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="min-h-11 md:min-h-7"
                disabled={busy}
                onClick={onSubmit}
            >
                <SendIcon aria-hidden="true" />
                Submit
            </Button>
        );
    }

    return (
        <span className="text-xs wrap-anywhere text-muted-foreground">
            {row.why_not}
        </span>
    );
}

/**
 * "Submit for review": the signed-in member's own hours and expenses for one
 * calendar month, each with where it stands, and the controls to submit them
 * or take a submission back (docs/ux-overhaul.md sections 7b and 7e).
 *
 * Submitting is not approving: it puts the rows in front of whoever the
 * provider asked to look at them, and nothing here can approve anything. Every
 * control posts to a URL the server sent, to the same operations an agent uses,
 * and the server decides again.
 */
export default function Submissions({
    workspace,
    submissions,
    links,
}: SubmissionsProps) {
    const style = useDurationStyle();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    const waiting = submissions.time.filter((row) => row.can_submit).length +
        submissions.expenses.filter((row) => row.can_submit).length;
    const withdrawable =
        submissions.time.filter((row) => row.can_withdraw).length +
        submissions.expenses.filter((row) => row.can_withdraw).length;

    const send = async (url: string, body: Body, message: string) => {
        if (busy) {
            return;
        }

        setBusy(true);
        setError(null);
        setDone(null);

        const result = await apiRequest('POST', url, body);

        if (!result.ok) {
            setError(result.message);
            setBusy(false);

            return;
        }

        setDone(message);
        void refreshPageProps({ onFinish: () => setBusy(false) });
    };

    const submit = (body: Body) =>
        send(links.submit_url, body, 'Submitted. A reviewer can see it now.');
    const withdraw = (body: Body) =>
        send(links.withdraw_url, body, 'Withdrawn. It is back in your drafts.');

    return (
        <WorkspaceShell activeSection="submissions">
            <Head title="Submit for review" />
            <main
                className={cn(SHELL_CONTAINER, 'grid grid-cols-1 gap-6 py-6')}
            >
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <h1 className="min-w-0 text-[17px] font-semibold tracking-tight md:text-lg">
                        Submit for review
                    </h1>
                    <nav
                        aria-label="Month"
                        className="flex items-center gap-2 text-sm"
                    >
                        <Link
                            href={links.previous_href}
                            className="inline-flex min-h-11 items-center gap-1 underline underline-offset-4 md:min-h-8"
                        >
                            <ChevronLeftIcon
                                aria-hidden="true"
                                className="size-4"
                            />
                            Previous
                        </Link>
                        <span
                            aria-live="polite"
                            className="min-w-28 text-center font-medium"
                            data-test="period"
                        >
                            {submissions.period_label}
                        </span>
                        <Link
                            href={links.next_href}
                            className="inline-flex min-h-11 items-center gap-1 underline underline-offset-4 md:min-h-8"
                        >
                            Next
                            <ChevronRightIcon
                                aria-hidden="true"
                                className="size-4"
                            />
                        </Link>
                    </nav>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{workspace.name}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-4">
                        <p
                            className="text-sm wrap-anywhere text-muted-foreground"
                            data-test="totals"
                        >
                            {(
                                [
                                    'draft',
                                    'submitted',
                                    'approved',
                                    'disputed',
                                ] as const
                            )
                                .map(
                                    (state) =>
                                        `${describeStatus(state).label} ${formatDuration(submissions.totals[state], style)}`,
                                )
                                .join(' · ')}
                        </p>
                        {error !== null && (
                            <p
                                role="alert"
                                className="text-sm font-medium wrap-anywhere text-destructive"
                            >
                                {error}
                            </p>
                        )}
                        {done !== null && (
                            <p
                                role="status"
                                className="text-sm wrap-anywhere text-muted-foreground"
                            >
                                {done}
                            </p>
                        )}
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                className="min-h-11 md:min-h-8"
                                disabled={busy || waiting === 0}
                                onClick={() =>
                                    void submit({ period: submissions.period })
                                }
                            >
                                <SendIcon aria-hidden="true" />
                                Submit {submissions.period_label}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 md:min-h-8"
                                disabled={busy || withdrawable === 0}
                                onClick={() =>
                                    void withdraw({
                                        period: submissions.period,
                                    })
                                }
                            >
                                <Undo2Icon aria-hidden="true" />
                                Withdraw {submissions.period_label}
                            </Button>
                            <Link
                                href={links.timesheet_href}
                                className="inline-flex min-h-11 items-center text-sm underline underline-offset-4 md:min-h-8"
                            >
                                Approved timesheet
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Hours</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {submissions.time.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                You logged no hours in {submissions.period_label}
                                .{' '}
                                <Link
                                    href={links.my_work_href}
                                    className="underline underline-offset-4"
                                >
                                    Log some on My work
                                </Link>
                                .
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead scope="col">Date</TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-56"
                                        >
                                            What the client reads
                                        </TableHead>
                                        <TableHead scope="col">
                                            Project
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="text-right"
                                        >
                                            Time
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-44"
                                        >
                                            State
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-36"
                                        >
                                            <span className="sr-only">
                                                Action
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {submissions.time.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatShortDay(row.worked_on)}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-56 wrap-anywhere whitespace-normal">
                                                {row.client_description !==
                                                    null &&
                                                row.client_description !== ''
                                                    ? row.client_description
                                                    : row.description}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-32 wrap-anywhere whitespace-normal">
                                                {row.project}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatDuration(
                                                    row.minutes,
                                                    style,
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <State
                                                    state={row.state}
                                                    note={row.dispute_note}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <RowAction
                                                    row={row}
                                                    busy={busy}
                                                    onSubmit={() =>
                                                        void submit({
                                                            time_entry_ids: [
                                                                row.id,
                                                            ],
                                                        })
                                                    }
                                                    onWithdraw={() =>
                                                        void withdraw({
                                                            time_entry_ids: [
                                                                row.id,
                                                            ],
                                                        })
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {submissions.expenses.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Expenses</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead scope="col">Date</TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-56"
                                        >
                                            Description
                                        </TableHead>
                                        <TableHead scope="col">
                                            Project
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="text-right"
                                        >
                                            Amount
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-44"
                                        >
                                            State
                                        </TableHead>
                                        <TableHead
                                            scope="col"
                                            className="min-w-36"
                                        >
                                            <span className="sr-only">
                                                Action
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {submissions.expenses.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatShortDay(row.spent_on)}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-56 wrap-anywhere whitespace-normal">
                                                {row.description}
                                            </TableCell>
                                            <TableCell className="max-w-0 min-w-32 wrap-anywhere whitespace-normal">
                                                {row.project}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    row.amount,
                                                    row.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <State
                                                    state={row.state}
                                                    note={row.dispute_note}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                <RowAction
                                                    row={row}
                                                    busy={busy}
                                                    onSubmit={() =>
                                                        void submit({
                                                            expense_ids: [
                                                                row.id,
                                                            ],
                                                        })
                                                    }
                                                    onWithdraw={() =>
                                                        void withdraw({
                                                            expense_ids: [
                                                                row.id,
                                                            ],
                                                        })
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {submissions.truncated && (
                    <p className="text-xs text-muted-foreground">
                        This month has more rows than fit on one page. The
                        first 500 of each kind are shown.
                    </p>
                )}
            </main>
        </WorkspaceShell>
    );
}
