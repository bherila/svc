import { Head, router } from '@inertiajs/react';
import { CheckIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkspaceShell from '@/layouts/workspace-shell';
import { formatShortDay } from '@/lib/datetime';
import { SHELL_CONTAINER } from '@/lib/layout';
import { formatDecimalHours, formatHours } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    Capacity,
    Month,
    TimeEntry,
    TimeSheetProps,
} from '@/types/time-sheet';

/** What the row's badge should say, and where it links. */
function stateOf(entry: TimeEntry): {
    label: string;
    variant: 'default' | 'secondary' | 'outline' | 'destructive';
    className?: string;
    href?: string;
} {
    if (entry.invoice !== null) {
        const sent = entry.invoice.status !== 'draft';

        return {
            label: sent ? 'Invoiced' : 'Upcoming',
            variant: 'secondary',
            className: sent
                ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                : 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
        };
    }

    if (entry.status === 'approved') {
        return { label: 'Approved', variant: 'secondary' };
    }

    return { label: 'Draft', variant: 'outline' };
}

/** Hours with the sign shown, so a deduction reads as one. */
function signedHours(hours: number): string {
    const sign = hours < 0 ? '\u2212' : '';

    return `${sign}${formatDecimalHours(Math.abs(hours))} h`;
}

/**
 * One line of the capacity breakdown: what it is, and how many hours.
 *
 * The figures used to run together in prose - "10.00 h this cycle + 4.00 h
 * carried in", "8.00 h left · 4.00 h rollover" - which reads as a sentence and
 * not as arithmetic. Labelled and right-aligned, the column adds up on the
 * page, which is the only way a reader can check it.
 */
function CapacityLine({
    label,
    value,
    emphasis = 'normal',
}: {
    label: string;
    value: string;
    emphasis?: 'normal' | 'total' | 'over';
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-xs">
            <span
                className={cn(
                    'wrap-anywhere',
                    emphasis === 'over'
                        ? 'text-destructive'
                        : emphasis === 'total'
                          ? 'font-medium text-foreground'
                          : 'text-muted-foreground',
                )}
            >
                {label}
            </span>
            <span
                className={cn(
                    'shrink-0 tabular-nums',
                    emphasis === 'over'
                        ? 'text-destructive'
                        : emphasis === 'total'
                          ? 'font-medium text-foreground'
                          : 'text-muted-foreground',
                )}
            >
                {value}
            </span>
        </div>
    );
}

function CapacityStrip({ capacity }: { capacity: Capacity[] }) {
    if (capacity.length === 0) {
        return null;
    }

    return (
        <div className="grid grid-cols-1 gap-2">
            <div className="flex flex-wrap gap-3">
                {capacity.map((row, index) => {
                    const over = row.over_hours > 0;
                    // A cadence anchored mid-month puts two cycles in one
                    // month, so the agreement name does not identify a row -
                    // neither as a key nor to the reader looking at two
                    // strips under the same heading.
                    const shareTheName =
                        capacity.filter(
                            (other) => other.agreement === row.agreement,
                        ).length > 1;
                    const used = row.worked_hours;
                    const available = row.available_hours;
                    const fraction =
                        available > 0
                            ? Math.min(1, used / available)
                            : used > 0
                              ? 1
                              : 0;
                    // The balance is the whole bank, and part of it may be
                    // older hours on their way out - or, on the other side of
                    // zero, debt this cycle did not create. Either way the
                    // reader wants to know which part of the number is not
                    // this cycle's doing.
                    const carriedDebt = Math.max(
                        0,
                        row.carried_deficit_hours - row.over_hours,
                    );

                    return (
                        <div
                            key={`${row.agreement}-${row.cycle_start}-${index}`}
                            // Bounded as well as flexible: a labelled figure
                            // and its value on one row are only readable near
                            // each other, and a single agreement on a wide
                            // screen otherwise stretched the pair a thousand
                            // pixels apart.
                            className="max-w-md min-w-64 flex-1 rounded-lg border border-border bg-muted/40 p-3"
                        >
                            <p className="truncate text-xs font-medium text-muted-foreground">
                                {row.agreement}
                                {shareTheName && row.cycle_start !== '' && (
                                    <span className="ml-1 font-normal">
                                        · cycle from{' '}
                                        {formatShortDay(row.cycle_start)}
                                    </span>
                                )}
                            </p>
                            <p className="mt-1 font-medium tabular-nums">
                                <span
                                    className={
                                        over ? 'text-destructive' : undefined
                                    }
                                >
                                    {formatDecimalHours(used)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    / {formatDecimalHours(available)} h
                                </span>
                            </p>
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-border">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        over
                                            ? 'bg-destructive'
                                            : 'bg-emerald-500',
                                    )}
                                    style={{ width: `${fraction * 100}%` }}
                                />
                            </div>
                            {/*
                             * Where the availability came from. The ledger has
                             * computed these since the port and the screen
                             * showed none of them, so a month living on hours
                             * carried in read exactly like one with a large
                             * retainer - and the hours spent repaying an
                             * earlier overrun were invisible, which left the
                             * headline figure with no derivation at all.
                             */}
                            <div className="mt-3 grid grid-cols-1 gap-1">
                                <CapacityLine
                                    label="Included this cycle"
                                    value={`${formatDecimalHours(row.retainer_hours)} h`}
                                />
                                {row.deficit_offset_hours > 0 && (
                                    <CapacityLine
                                        label="Repaid earlier overrun"
                                        value={signedHours(
                                            -row.deficit_offset_hours,
                                        )}
                                        emphasis="over"
                                    />
                                )}
                                {row.rollover_in_hours > 0 && (
                                    <CapacityLine
                                        label="Carried in"
                                        value={signedHours(
                                            row.rollover_in_hours,
                                        )}
                                    />
                                )}
                                <div className="mt-0.5 border-t border-border pt-1">
                                    <CapacityLine
                                        label="Available"
                                        value={`${formatDecimalHours(available)} h`}
                                        emphasis="total"
                                    />
                                </div>
                                <CapacityLine
                                    label="Used"
                                    value={signedHours(-used)}
                                    emphasis={over ? 'over' : 'normal'}
                                />
                                {/*
                                 * The one figure that says where the agreement
                                 * stands. Unused hours, unspent carry-in and a
                                 * deficit were three separate numbers on this
                                 * card, and no arrangement of them told a
                                 * reader whether the client was ahead of the
                                 * retainer or behind it.
                                 */}
                                <div className="mt-0.5 border-t border-border pt-1">
                                    <CapacityLine
                                        label="Carryover balance"
                                        value={signedHours(row.balance_hours)}
                                        emphasis={
                                            row.balance_hours < 0
                                                ? 'over'
                                                : 'total'
                                        }
                                    />
                                </div>
                            </div>
                            {row.balance_hours >= 0 &&
                                row.remaining_rollover > 0 && (
                                    <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                        Including{' '}
                                        {formatDecimalHours(
                                            row.remaining_rollover,
                                        )}{' '}
                                        h carried in from earlier cycles.
                                    </p>
                                )}
                            {row.balance_hours < 0 && carriedDebt > 0 && (
                                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                    Including {formatDecimalHours(carriedDebt)}{' '}
                                    h owed from earlier cycles.
                                </p>
                            )}
                            {row.expired_hours > 0 && (
                                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                    <span className="text-destructive">
                                        {formatDecimalHours(row.expired_hours)}{' '}
                                        h
                                    </span>{' '}
                                    expired before this cycle opened.
                                </p>
                            )}
                            {/*
                             * What the client actually bought. Hours worked
                             * over the retainer and carried forward as a
                             * deficit are unpaid; hours worked over it and
                             * invoiced are paid. A card reporting only what the
                             * retainer included and how far the work went past
                             * it draws the two the same way.
                             */}
                            <div className="mt-2 grid grid-cols-1 gap-1 border-t border-border pt-2">
                                <CapacityLine
                                    label="Paid for"
                                    value={`${formatDecimalHours(row.paid_hours)} h`}
                                />
                                {row.billed_overage_hours !== 0 && (
                                    <CapacityLine
                                        label={
                                            row.billed_overage_hours > 0
                                                ? 'Billed at the hourly rate'
                                                : 'Reversed by a correction'
                                        }
                                        value={signedHours(
                                            row.billed_overage_hours,
                                        )}
                                    />
                                )}
                            </div>
                            {row.pending_minutes > 0 && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground tabular-nums">
                                        {formatHours(row.pending_minutes)}
                                    </span>{' '}
                                    awaiting approval
                                </p>
                            )}
                            {/*
                             * The rule behind the arithmetic above, so the
                             * numbers can be read rather than only observed.
                             * Null months is not zero months: it says the
                             * agreement states no rollover, which reaches the
                             * same outcome by a different route.
                             */}
                            <p className="mt-2 text-xs text-muted-foreground">
                                {row.rollover_months === null
                                    ? 'Unused hours do not carry forward.'
                                    : row.rollover_months === 0
                                      ? 'Unused hours expire at the end of the cycle.'
                                      : `Unused hours carry forward ${row.rollover_months} month${row.rollover_months === 1 ? '' : 's'}.`}
                            </p>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function TimeSheet({
    workspace,
    companies,
    months,
    approval_limit: approvalLimit,
}: TimeSheetProps) {
    const [dialogEntry, setDialogEntry] = useState<TimeEntry | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<TimeEntry | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    // Approval can fail for a reason the row cannot show - most often a
    // billable entry with no agreement rate to stamp. Without this the row
    // simply stays a draft and the click looks like it did nothing.
    const [notice, setNotice] = useState<string | null>(null);
    const [approving, setApproving] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const reportFailure = (errors: Record<string, string>) => {
        setNotice(
            Object.values(errors)[0] ?? 'That action could not be completed.',
        );
    };

    // The route names the client, and the server sends exactly that one. The
    // page used to hold a second picker under the navbar's; two controls for
    // one decision is how a screen ends up showing one client's time under
    // another client's name.
    const company = companies[0];

    const approvable = useMemo(
        () =>
            months
                .flatMap((month) => month.entries)
                .filter((entry) => entry.can_approve),
        [months],
    );

    const selectedEntries = approvable.filter((entry) =>
        selected.includes(entry.id),
    );

    const openDialog = (entry: TimeEntry | null) => {
        setDialogEntry(entry);
        setDialogOpen(true);
    };

    // Approval carries the version each row was rendered with, so a second
    // click sends versions the first click has already spent: the first
    // request approves, the second comes back 409 and the operator is told
    // their successful approval failed.
    const approve = (entries: TimeEntry[]) => {
        if (approving) {
            return;
        }

        setApproving(true);
        router.post(
            `/workspaces/${workspace.id}/time-entries/approve`,
            {
                entries: entries.map((entry) => ({
                    id: entry.id,
                    expected_version: entry.version,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    // Only what was sent. Clearing the whole selection after
                    // approving the first batch would discard the remainder
                    // the button just told the operator was still to come.
                    const sent = new Set(entries.map((entry) => entry.id));

                    setSelected((current) =>
                        current.filter((id) => !sent.has(id)),
                    );
                    setNotice(null);
                },
                onError: reportFailure,
                onFinish: () => setApproving(false),
            },
        );
    };

    // Same hazard as approval: the version travelled with the row, so a
    // second click spends one the first has already used - the entry is
    // deleted and the operator is told it failed.
    const remove = (entry: TimeEntry) => {
        if (deleting) {
            return;
        }

        setDeleting(true);
        router.delete(`/workspaces/${workspace.id}/time-entries/${entry.id}`, {
            data: { expected_version: entry.version },
            preserveScroll: true,
            onSuccess: () => setNotice(null),
            onError: reportFailure,
            onFinish: () => {
                setDeleting(false);
                setPendingDelete(null);
            },
        });
    };

    return (
        <WorkspaceShell activeModule="time">
            <Head title="Time" />

            <div>
                <div className={cn(SHELL_CONTAINER, 'py-8')}>
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <h1 className="text-3xl font-semibold tracking-tight">
                            Time
                        </h1>

                        <div className="flex items-center gap-2">
                            {company?.projects.some(
                                (project) => project.can_log_time,
                            ) && (
                                <Button onClick={() => openDialog(null)}>
                                    <PlusIcon />
                                    Log time
                                </Button>
                            )}
                        </div>
                    </header>

                    {notice !== null && (
                        <div
                            role="alert"
                            className="mt-6 flex items-start justify-between gap-4 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                        >
                            <p>{notice}</p>
                            <Button
                                variant="ghost"
                                size="xs"
                                onClick={() => setNotice(null)}
                            >
                                Dismiss
                            </Button>
                        </div>
                    )}

                    {selectedEntries.length > 0 && (
                        <div className="mt-6 flex items-center justify-between rounded-lg border border-border bg-muted/40 px-4 py-3">
                            <p className="text-sm">
                                {selectedEntries.length} selected ·{' '}
                                <span className="tabular-nums">
                                    {formatHours(
                                        selectedEntries.reduce(
                                            (total, entry) =>
                                                total + entry.minutes,
                                            0,
                                        ),
                                    )}
                                </span>
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSelected([])}
                                >
                                    Clear
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={approving}
                                    onClick={() =>
                                        approve(
                                            selectedEntries.slice(
                                                0,
                                                approvalLimit,
                                            ),
                                        )
                                    }
                                >
                                    <CheckIcon />
                                    {selectedEntries.length > approvalLimit
                                        ? `Approve ${approvalLimit} of ${selectedEntries.length}`
                                        : 'Approve'}
                                </Button>
                            </div>
                        </div>
                    )}

                    {company !== undefined && months.length === 0 && (
                        <p className="mt-10 text-muted-foreground">
                            No time logged for {company.name} in the last twelve
                            months.
                        </p>
                    )}

                    <div className="mt-8 grid grid-cols-1 gap-8">
                        {groupMonths(months).map((group) =>
                            group.kind === 'quiet' ? (
                                <QuietMonths
                                    key={group.key}
                                    months={group.months}
                                />
                            ) : (
                                <MonthCard
                                    key={group.month.key}
                                    month={group.month}
                                    workspaceId={workspace.id}
                                    selected={selected}
                                    onToggle={(id) =>
                                        setSelected((current) =>
                                            current.includes(id)
                                                ? current.filter(
                                                      (value) => value !== id,
                                                  )
                                                : [...current, id],
                                        )
                                    }
                                    onEdit={openDialog}
                                    onDelete={setPendingDelete}
                                    onApprove={(entry) => approve([entry])}
                                    approving={approving}
                                />
                            ),
                        )}
                    </div>
                </div>
            </div>

            {company !== undefined && dialogOpen && (
                <TimeEntryDialog
                    key={dialogEntry?.id ?? 'new'}
                    workspaceId={workspace.id}
                    timezone={workspace.timezone}
                    company={company}
                    entry={dialogEntry}
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                />
            )}

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open: boolean) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this entry?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete === null
                                ? ''
                                : `${formatHours(pendingDelete.minutes)} on ${formatShortDay(
                                      pendingDelete.worked_on,
                                  )} — ${pendingDelete.description}`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            render={<Button variant="outline">Cancel</Button>}
                        />
                        <AlertDialogAction
                            render={
                                <Button
                                    variant="destructive"
                                    disabled={deleting}
                                    onClick={() =>
                                        pendingDelete !== null &&
                                        remove(pendingDelete)
                                    }
                                >
                                    Delete
                                </Button>
                            }
                        />
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </WorkspaceShell>
    );
}

/**
 * A run of months nobody logged against, as one quiet block.
 *
 * The sheet's window is twelve months, and a client worked for two of them
 * produced ten full cards - each with a heading, a capacity strip and an empty
 * table - to scroll past before reaching anything. The window is still twelve
 * months on purpose: "did I log anything in April" is a question this screen
 * should answer, and a month that has silently dropped out cannot answer it.
 * So an empty month keeps its row and loses its card.
 *
 * The unused figure comes along because it is the one thing an empty month
 * still says: a retainer that sold thirty hours and drew none is a fact about
 * the engagement, not an absence of one.
 */
function QuietMonths({ months }: { months: Month[] }) {
    return (
        <section
            aria-label="Months with no time logged"
            className="rounded-xl border border-border"
        >
            <ul className="divide-y divide-border">
                {months.map((month) => {
                    const unused = month.capacity.reduce(
                        (total, row) => total + row.unused_hours,
                        0,
                    );

                    return (
                        <li
                            key={month.key}
                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm"
                        >
                            <span className="font-medium">{month.label}</span>
                            <span className="text-muted-foreground tabular-nums">
                                Nothing logged
                                {month.capacity.length > 0 &&
                                    ` · ${formatDecimalHours(unused)} h unused`}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/**
 * The months in order, with runs of empty ones folded together.
 *
 * Folded rather than filtered, and folded in place: an empty March between two
 * worked months is still March, and moving it - or dropping it - would change
 * what the sheet says about the year.
 */
type MonthGroup =
    | { kind: 'logged'; month: Month }
    | { kind: 'quiet'; key: string; months: Month[] };

function groupMonths(months: Month[]): MonthGroup[] {
    const groups: MonthGroup[] = [];

    for (const month of months) {
        if (month.entries.length > 0) {
            groups.push({ kind: 'logged', month });

            continue;
        }

        const last = groups.at(-1);

        if (last?.kind === 'quiet') {
            last.months.push(month);

            continue;
        }

        groups.push({ kind: 'quiet', key: month.key, months: [month] });
    }

    return groups;
}

function MonthCard({
    month,
    workspaceId,
    selected,
    onToggle,
    onEdit,
    onDelete,
    onApprove,
    approving,
}: {
    month: Month;
    workspaceId: string;
    selected: string[];
    onToggle: (id: string) => void;
    onEdit: (entry: TimeEntry) => void;
    onDelete: (entry: TimeEntry) => void;
    onApprove: (entry: TimeEntry) => void;
    approving: boolean;
}) {
    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-lg font-semibold">{month.label}</h2>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {formatHours(month.total_minutes)} logged ·{' '}
                        {formatHours(month.billable_minutes)} billable
                        {month.deferred_minutes > 0 && (
                            <>
                                {' '}
                                · {formatHours(month.deferred_minutes)} deferred
                            </>
                        )}
                    </p>
                </div>
                <CapacityStrip capacity={month.capacity} />
            </CardHeader>

            <CardContent>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8" />
                                <TableHead className="w-28">Date</TableHead>
                                <TableHead className="min-w-64">Work</TableHead>
                                <TableHead className="w-20 text-right">
                                    Hours
                                </TableHead>
                                <TableHead className="w-28">State</TableHead>
                                <TableHead className="w-32 text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {month.entries.map((entry) => {
                                const state = stateOf(entry);

                                return (
                                    <TableRow key={entry.id}>
                                        <TableCell>
                                            {entry.can_approve && (
                                                <input
                                                    type="checkbox"
                                                    className="size-4 accent-primary"
                                                    checked={selected.includes(
                                                        entry.id,
                                                    )}
                                                    onChange={() =>
                                                        onToggle(entry.id)
                                                    }
                                                    aria-label={`Select ${entry.description}`}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground tabular-nums">
                                            {formatShortDay(entry.worked_on)}
                                        </TableCell>
                                        {/*
                                         * The one prose column. Table cells
                                         * do not wrap by default, which is
                                         * right for a date or an amount and
                                         * wrong here: a description ran the
                                         * table wider than the card and
                                         * pushed Hours, State and Actions off
                                         * the screen entirely.
                                         */}
                                        <TableCell className="max-w-0 whitespace-normal">
                                            <p className="font-medium wrap-anywhere">
                                                {entry.description}
                                            </p>
                                            <p className="text-xs wrap-anywhere text-muted-foreground">
                                                {entry.project.name}
                                                {entry.task !== null &&
                                                    ` · ${entry.task.title}`}
                                                {entry.worker !== null &&
                                                    ` · ${entry.worker}`}
                                            </p>
                                            <div className="mt-1 flex flex-wrap gap-1">
                                                {!entry.is_billable && (
                                                    <Badge variant="outline">
                                                        Non-billable
                                                    </Badge>
                                                )}
                                                {entry.is_deferred && (
                                                    <Badge variant="outline">
                                                        Deferred
                                                    </Badge>
                                                )}
                                                {entry.subcontractor_billing_mode !==
                                                    null &&
                                                    entry.subcontractor_billing_mode !==
                                                        undefined && (
                                                        <Badge variant="outline">
                                                            {entry.subcontractor_billing_mode ===
                                                            'flat_hourly'
                                                                ? 'Subcontractor · billed separately'
                                                                : entry.subcontractor_billing_mode ===
                                                                    'retainer'
                                                                  ? 'Subcontractor · retainer'
                                                                  : 'Subcontractor · direct'}
                                                        </Badge>
                                                    )}
                                                {entry.is_visible_to_client && (
                                                    <Badge variant="outline">
                                                        Client-visible
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatHours(entry.minutes)}
                                        </TableCell>
                                        <TableCell>
                                            {entry.invoice !== null ? (
                                                <a
                                                    href={`/workspaces/${workspaceId}/invoices/${entry.invoice.id}`}
                                                    className="inline-block"
                                                >
                                                    <Badge
                                                        variant={state.variant}
                                                        className={
                                                            state.className
                                                        }
                                                    >
                                                        {state.label}
                                                        {entry.invoice
                                                            .number !== null &&
                                                            ` · ${entry.invoice.number}`}
                                                    </Badge>
                                                </a>
                                            ) : (
                                                <Badge
                                                    variant={state.variant}
                                                    className={state.className}
                                                >
                                                    {state.label}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                {entry.can_approve && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-xs"
                                                        aria-label="Approve"
                                                        disabled={approving}
                                                        onClick={() =>
                                                            onApprove(entry)
                                                        }
                                                    >
                                                        <CheckIcon />
                                                    </Button>
                                                )}
                                                {entry.can_edit && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-xs"
                                                            aria-label="Edit"
                                                            onClick={() =>
                                                                onEdit(entry)
                                                            }
                                                        >
                                                            <PencilIcon />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-xs"
                                                            aria-label="Delete"
                                                            onClick={() =>
                                                                onDelete(entry)
                                                            }
                                                        >
                                                            <Trash2Icon />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
